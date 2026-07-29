<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** POST /api/auth/login */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Este acesso está desativado. Fale com o administrador.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    /** GET /api/auth/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * POST /api/auth/change-password
     * Usado tanto na troca obrigatória da senha provisória quanto na troca
     * voluntária. Ao concluir, todos os outros tokens são revogados.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.required' => 'Informe a senha atual.',
            'password.required' => 'Informe a nova senha.',
            'password.confirmed' => 'A confirmação da nova senha não confere.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Senha atual incorreta.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['A nova senha deve ser diferente da atual.'],
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        // Mantém apenas o token atual: derruba sessões abertas com a senha antiga
        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->id)->delete();

        return response()->json([
            'message' => 'Senha alterada com sucesso.',
            'user' => $this->userPayload($user),
        ]);
    }

    private function userPayload(User $user): array
    {
        $user->load('company');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'company' => $user->company?->toTheme(),
            'must_change_password' => (bool) $user->must_change_password,
            'referral_code' => $user->referral_code,
        ];
    }
}
