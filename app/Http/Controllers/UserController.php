<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\Cpf;
use App\Rules\CpfOrCnpj;
use App\Services\GeocodingService;
use App\Support\BrazilianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestão de usuários do sistema.
 *
 * Escopos:
 * - super_admin cria analistas (Percapital) e admins de whitelabel;
 * - company_admin cria gerentes comerciais **da própria empresa** — o
 *   company_id vem sempre do usuário autenticado, nunca do payload.
 */
class UserController extends Controller
{
    public function __construct(private readonly GeocodingService $geocoding) {}

    /** GET /api/users?role=&company_id= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = User::query()
            ->with(['company:id,name', 'parentManager:id,name'])
            ->whereNot('id', $user->id)
            ->orderBy('name');

        if ($user->isSuperAdmin()) {
            if ($companyId = $request->query('company_id')) {
                $query->where('company_id', $companyId);
            }
        } elseif ($user->isCompanyAdmin()) {
            // Admin do whitelabel só enxerga os gerentes da própria empresa
            $query->where('company_id', $user->company_id)
                ->where('role', User::ROLE_COMPANY_MANAGER);
        } else {
            abort(403, 'Você não tem permissão para listar usuários.');
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        return response()->json(['data' => $query->get()]);
    }

    /** POST /api/users — cria com senha provisória */
    public function store(Request $request): JsonResponse
    {
        $author = $request->user();
        $role = (string) $request->input('role');

        $this->assertCanManageRole($author, $role);

        $data = $this->validated($request, $role);

        $companyId = $author->isSuperAdmin()
            ? ($role === User::ROLE_COMPANY_ADMIN ? (int) $data['company_id'] : null)
            : (int) $author->company_id;

        $temporaryPassword = $this->generateTemporaryPassword();

        $attributes = [
            ...$this->addressAttributes($data),
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'document' => preg_replace('/\D/', '', (string) ($data['document'] ?? '')) ?: null,
            'phone' => preg_replace('/\D/', '', (string) ($data['phone'] ?? '')) ?: null,
            'role' => $role,
            'company_id' => $companyId,
            'password' => $temporaryPassword,
            'must_change_password' => true,
            'is_active' => true,
        ];

        if ($role === User::ROLE_COMPANY_MANAGER) {
            $attributes['parent_manager_id'] = $this->resolveParentManager(
                companyId: (int) $companyId,
                parentManagerId: isset($data['parent_manager_id']) ? (int) $data['parent_manager_id'] : null,
            );
        }

        $attributes['document_type'] = $attributes['document']
            ? (strlen($attributes['document']) === 14 ? 'cnpj' : 'cpf')
            : null;

        if ($role === User::ROLE_COMPANY_MANAGER) {
            $attributes['referral_code'] = User::generateReferralCode();
        }

        $user = User::query()->create($attributes);

        return response()->json([
            'data' => $user->load(['company:id,name', 'parentManager:id,name']),
            // Exibido uma única vez ao criador (envio por e-mail ainda desativado)
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    /** PUT /api/users/{user} */
    public function update(Request $request, User $user): JsonResponse
    {
        $author = $request->user();
        $this->assertCanManageUser($author, $user);

        $data = $this->validated($request, $user->role, $user);

        $attributes = [
            ...$this->addressAttributes($data),
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'document' => preg_replace('/\D/', '', (string) ($data['document'] ?? '')) ?: null,
            'phone' => preg_replace('/\D/', '', (string) ($data['phone'] ?? '')) ?: null,
        ];

        $attributes['document_type'] = $attributes['document']
            ? (strlen($attributes['document']) === 14 ? 'cnpj' : 'cpf')
            : null;

        if ($request->has('is_active')) {
            $attributes['is_active'] = $request->boolean('is_active');
        }

        if ($user->role === User::ROLE_COMPANY_MANAGER && $request->has('parent_manager_id')) {
            $parentManagerId = $request->input('parent_manager_id');
            $attributes['parent_manager_id'] = $this->resolveParentManager(
                companyId: (int) $user->company_id,
                parentManagerId: $parentManagerId !== null ? (int) $parentManagerId : null,
                ignoreId: $user->id,
            );
        }

        $user->update($attributes);

        return response()->json([
            'data' => $user->fresh()->load(['company:id,name', 'parentManager:id,name']),
        ]);
    }

    /** POST /api/users/{user}/reset-password — nova senha provisória */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUser($request->user(), $user);

        $temporaryPassword = $this->generateTemporaryPassword();

        $user->forceFill([
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ])->save();

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Senha provisória gerada.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    /** POST /api/users/{user}/deactivate — bloqueia o acesso, mantém histórico */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUser($request->user(), $user);

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Acesso desativado.']);
    }

    /** POST /api/users/{user}/activate */
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUser($request->user(), $user);

        $user->update(['is_active' => true]);

        return response()->json(['message' => 'Acesso reativado.']);
    }

    /**
     * DELETE /api/users/{user} — exclusão definitiva.
     *
     * Gerente com clientes indicados não pode ser excluído: as propostas
     * perderiam o vínculo (manager_id vira null) e a origem comercial se
     * perderia. Gerente com subgerentes vinculados também não — eles
     * ficariam órfãos na hierarquia. Em ambos os casos, o caminho é desativar.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUser($request->user(), $user);

        $clients = $user->referredOpenings()->count();
        $subManagers = $user->subManagers()->count();

        if ($clients > 0 || $subManagers > 0) {
            $parts = [];
            if ($clients > 0) {
                $parts[] = $clients.' '.($clients === 1 ? 'cliente vinculado' : 'clientes vinculados');
            }
            if ($subManagers > 0) {
                $parts[] = $subManagers.' '.
                    ($subManagers === 1 ? 'subgerente vinculado' : 'subgerentes vinculados');
            }

            throw ValidationException::withMessages([
                'user' => [
                    'Este gerente possui '.implode(' e ', $parts).
                    ' e não pode ser excluído. Desative o acesso para bloquear novos cadastros.',
                ],
            ]);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Usuário excluído definitivamente.']);
    }

    /* -----------------------------------------------------------------
     | Apoio
     |------------------------------------------------------------------
     */

    private function validated(Request $request, string $role, ?User $user = null): array
    {
        $isManager = $role === User::ROLE_COMPANY_MANAGER;

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'document' => $isManager
                ? ['required', 'string', new CpfOrCnpj]
                : ['required', 'string', new Cpf],
        ];

        if ($isManager) {
            $rules += [
                'zip_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
                'street' => ['required', 'string', 'max:150'],
                'number' => ['required', 'string', 'max:10'],
                'complement' => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city' => ['required', 'string', 'max:100'],
                'state' => ['required', 'string', Rule::in(BrazilianStates::UFS)],
                // Presença/elegibilidade real (mesma empresa, gerente-topo)
                // é resolvida em resolveParentManager(); aqui só o tipo.
                'parent_manager_id' => ['nullable', 'integer'],
            ];
        }

        if ($request->user()->isSuperAdmin() && $role === User::ROLE_COMPANY_ADMIN) {
            $rules['company_id'] = ['required', 'integer', 'exists:companies,id'];
        }

        return $request->validate($rules, [
            'name.required' => 'Informe o nome.',
            'email.required' => 'Informe o e-mail.',
            'email.unique' => 'Este e-mail já está em uso.',
            'document.required' => 'Informe o documento.',
            'zip_code.required' => 'Informe o CEP.',
            'zip_code.regex' => 'CEP inválido (use 00000-000).',
            'street.required' => 'Informe o logradouro.',
            'number.required' => 'Informe o número.',
            'neighborhood.required' => 'Informe o bairro.',
            'city.required' => 'Informe a cidade.',
            'state.required' => 'Informe o estado.',
            'company_id.required' => 'Selecione a empresa (whitelabel).',
        ]);
    }

    /** Normaliza o endereço e resolve lat/long (falha silenciosa). */
    private function addressAttributes(array $data): array
    {
        if (! isset($data['zip_code'])) {
            return [];
        }

        $address = [
            'zip_code' => preg_replace('/\D/', '', $data['zip_code']),
            'street' => $data['street'] ?? null,
            'number' => $data['number'] ?? null,
            'complement' => $data['complement'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => strtoupper((string) ($data['state'] ?? '')),
        ];

        $coords = $this->geocoding->locate($address);

        return [
            ...$address,
            'latitude' => $coords['latitude'] ?? null,
            'longitude' => $coords['longitude'] ?? null,
        ];
    }

    /**
     * Valida e resolve o gerente "pai" de um subgerente: precisa ser um
     * gerente-topo (sem parent_manager_id próprio) da mesma empresa, e
     * nunca pode ser o próprio usuário sendo criado/editado.
     */
    private function resolveParentManager(
        int $companyId,
        ?int $parentManagerId,
        ?int $ignoreId = null,
    ): ?int {
        if ($parentManagerId === null) {
            return null;
        }

        $parent = User::query()
            ->where('id', $parentManagerId)
            ->where('role', User::ROLE_COMPANY_MANAGER)
            ->where('company_id', $companyId)
            ->whereNull('parent_manager_id')
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_manager_id' => ['Selecione um gerente válido da sua empresa.'],
            ]);
        }

        return $parent->id;
    }

    private function generateTemporaryPassword(): string
    {
        // Legível e forte o bastante para uso único (troca obrigatória)
        return strtoupper(Str::random(4)).'-'.random_int(1000, 9999).'-'.strtolower(Str::random(4));
    }

    private function assertCanManageRole(User $author, string $role): void
    {
        $allowed = match (true) {
            $author->isSuperAdmin() => [User::ROLE_ANALYST, User::ROLE_COMPANY_ADMIN],
            $author->isCompanyAdmin() => [User::ROLE_COMPANY_MANAGER],
            default => [],
        };

        if (! in_array($role, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => ['Você não pode criar usuários com este perfil.'],
            ]);
        }
    }

    private function assertCanManageUser(User $author, User $target): void
    {
        if ($author->isSuperAdmin()) {
            return;
        }

        $isOwnManager = $author->isCompanyAdmin()
            && $target->role === User::ROLE_COMPANY_MANAGER
            && $target->company_id === $author->company_id;

        if (! $isOwnManager) {
            abort(403, 'Você não tem permissão para alterar este usuário.');
        }
    }
}
