<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Rules\Cnpj;
use App\Services\GeocodingService;
use App\Support\BrazilianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(private readonly GeocodingService $geocoding) {}

    /** GET /api/companies */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Company::query()->orderBy('name')->get()]);
    }

    /** POST /api/companies */
    public function store(Request $request): JsonResponse
    {
        $data = $this->withGeo($this->validated($request));

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        if ($request->hasFile('banner')) {
            $data['banner_path'] = $request->file('banner')->store('banners', 'public');
        }

        $company = Company::query()->create($data);

        return response()->json(['data' => $company], 201);
    }

    /** GET /api/companies/{company} */
    public function show(Company $company): JsonResponse
    {
        return response()->json(['data' => $company]);
    }

    /** PUT/PATCH /api/companies/{company} (multipart com _method) */
    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $this->withGeo($this->validated($request, $company));

        if ($request->boolean('remove_logo') && $company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        if ($request->boolean('remove_banner') && $company->banner_path) {
            Storage::disk('public')->delete($company->banner_path);
            $data['banner_path'] = null;
        }

        if ($request->hasFile('banner')) {
            if ($company->banner_path) {
                Storage::disk('public')->delete($company->banner_path);
            }
            $data['banner_path'] = $request->file('banner')->store('banners', 'public');
        }

        $company->update($data);

        return response()->json(['data' => $company->refresh()]);
    }

    /** DELETE /api/companies/{company} */
    public function destroy(Company $company): JsonResponse
    {
        // Proteção: excluir a empresa apagaria em cascata todas as aberturas
        // de conta vinculadas. Nesse caso, desative em vez de excluir.
        $openings = $company->accountOpenings()->count();
        $users = $company->users()->count();

        if ($openings > 0 || $users > 0) {
            $parts = [];
            if ($openings > 0) {
                $parts[] = $openings.' '.($openings === 1 ? 'cadastro de conta' : 'cadastros de conta');
            }
            if ($users > 0) {
                $parts[] = $users.' '.($users === 1 ? 'usuário' : 'usuários');
            }

            throw ValidationException::withMessages([
                'company' => [
                    'Esta empresa possui '.implode(' e ', $parts).
                    ' e não pode ser excluída. Desative-a para bloquear novos acessos.',
                ],
            ]);
        }

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }
        if ($company->banner_path) {
            Storage::disk('public')->delete($company->banner_path);
        }
        $company->delete();

        return response()->json(['message' => 'Empresa removida.']);
    }

    /**
     * GET /api/public/theme?domain=cliente.com.br
     * Endpoint público: resolve o tema whitelabel pelo domínio;
     * sem correspondência, devolve a primeira empresa ativa.
     */
    public function publicTheme(Request $request): JsonResponse
    {
        $domain = strtolower((string) $request->query('domain', ''));
        $domain = preg_replace('/^www\./', '', $domain);

        $company = null;

        if ($domain !== '') {
            $company = Company::query()
                ->where('is_active', true)
                ->where('domain', $domain)
                ->first();
        }

        $company ??= Company::query()->where('is_active', true)->orderBy('id')->first();

        return response()->json(['data' => $company?->toTheme()]);
    }

    /** Normaliza documento/telefone e resolve lat/long a partir do endereço. */
    private function withGeo(array $data): array
    {
        foreach (['document', 'phone', 'zip_code'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = preg_replace('/\D/', '', (string) $data[$field]) ?: null;
            }
        }

        if (isset($data['state'])) {
            $data['state'] = strtoupper((string) $data['state']);
        }

        if (! empty($data['zip_code'])) {
            $coords = $this->geocoding->locate($data);
            $data['latitude'] = $coords['latitude'] ?? null;
            $data['longitude'] = $coords['longitude'] ?? null;
        }

        return $data;
    }

    private function validated(Request $request, ?Company $company = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('companies', 'domain')->ignore($company?->id),
            ],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'document' => ['nullable', 'string', new Cnpj],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'zip_code' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['nullable', 'string', 'max:150'],
            'number' => ['nullable', 'string', 'max:10'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::in(BrazilianStates::UFS)],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'banner' => ['nullable', 'image', 'max:5120'],
            'remove_banner' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Informe o nome da empresa.',
            'domain.unique' => 'Este domínio já está em uso por outra empresa.',
            'zip_code.regex' => 'CEP inválido (use 00000-000).',
            'state.in' => 'Estado inválido.',
            'primary_color.regex' => 'Cor primária inválida (use #RRGGBB).',
            'secondary_color.regex' => 'Cor secundária inválida (use #RRGGBB).',
            'logo.image' => 'O logotipo deve ser uma imagem.',
            'logo.max' => 'O logotipo deve ter no máximo 2 MB.',
            'banner.image' => 'O banner deve ser uma imagem.',
            'banner.max' => 'O banner deve ter no máximo 5 MB.',
        ]);
    }
}
