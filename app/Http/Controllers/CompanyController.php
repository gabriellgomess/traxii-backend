<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    /** GET /api/companies */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Company::query()->orderBy('name')->get()]);
    }

    /** POST /api/companies */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
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
        $data = $this->validated($request, $company);

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

        $company->update($data);

        return response()->json(['data' => $company->refresh()]);
    }

    /** DELETE /api/companies/{company} */
    public function destroy(Company $company): JsonResponse
    {
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
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
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Informe o nome da empresa.',
            'domain.unique' => 'Este domínio já está em uso por outra empresa.',
            'primary_color.regex' => 'Cor primária inválida (use #RRGGBB).',
            'secondary_color.regex' => 'Cor secundária inválida (use #RRGGBB).',
            'logo.image' => 'O logotipo deve ser uma imagem.',
            'logo.max' => 'O logotipo deve ter no máximo 2 MB.',
        ]);
    }
}
