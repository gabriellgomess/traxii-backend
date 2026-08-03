<?php

use App\Http\Controllers\AccountOpeningController;
use App\Http\Controllers\AccountOpeningPendencyController;
use App\Http\Controllers\AccountOpeningReviewController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas públicas
|--------------------------------------------------------------------------
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::get('/public/theme', [CompanyController::class, 'publicTheme'])
    ->middleware('throttle:60,1');

// Resolução de pendência pelo cliente (link enviado pelo backoffice)
Route::prefix('public/account-openings/{opening:uuid}/pendency')
    ->middleware('throttle:20,1')
    ->group(function () {
        Route::get('/', [AccountOpeningPendencyController::class, 'show']);
        Route::post('/resolve', [AccountOpeningPendencyController::class, 'resolve']);
    });

// Abertura de conta PF (wizard do whitelabel) — rate limiting agressivo na
// criação (anti-abuso) e token de retomada obrigatório nas demais rotas
Route::prefix('public/account-openings')->group(function () {
    Route::post('/', [AccountOpeningController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::middleware(['opening.token', 'throttle:30,1'])->group(function () {
        Route::get('/{opening:uuid}', [AccountOpeningController::class, 'show']);
        Route::put('/{opening:uuid}/personal-data', [AccountOpeningController::class, 'updatePersonalData']);
        Route::put('/{opening:uuid}/address', [AccountOpeningController::class, 'updateAddress']);
        Route::post('/{opening:uuid}/documents', [AccountOpeningController::class, 'storeDocuments']);
        Route::post('/{opening:uuid}/liveness', [AccountOpeningController::class, 'completeLiveness']);
        Route::post('/{opening:uuid}/selfie', [AccountOpeningController::class, 'storeSelfie']);
        Route::post('/{opening:uuid}/submit', [AccountOpeningController::class, 'submit']);
    });
});

/*
|--------------------------------------------------------------------------
| Rotas autenticadas (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    // Sempre disponíveis: sessão e troca da senha provisória
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
});

// Demais rotas exigem que a senha provisória já tenha sido trocada
Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    // Gestão de empresas (whitelabel) — somente super admin
    Route::middleware('role:super_admin')->group(function () {
        Route::apiResource('companies', CompanyController::class);
    });

    // Usuários — escopo por papel dentro do controller
    Route::middleware('role:super_admin,company_admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });

    // Pontos do mapa (clientes e gerentes) — escopo por papel no controller
    Route::get('/map-points', [MapController::class, 'index']);

    // Backoffice de aberturas de conta — escopo por papel dentro do controller
    Route::prefix('account-openings')->group(function () {
        Route::get('/', [AccountOpeningReviewController::class, 'index']);
        Route::post('/', [AccountOpeningReviewController::class, 'store']);
        Route::get('/{opening:uuid}', [AccountOpeningReviewController::class, 'show']);
        Route::get('/{opening:uuid}/documents/{document}', [AccountOpeningReviewController::class, 'document']);
        Route::post('/{opening:uuid}/start-analysis', [AccountOpeningReviewController::class, 'startAnalysis']);
        Route::post('/{opening:uuid}/approve', [AccountOpeningReviewController::class, 'approve']);
        Route::post('/{opening:uuid}/reject', [AccountOpeningReviewController::class, 'reject']);
        Route::post('/{opening:uuid}/pendency', [AccountOpeningReviewController::class, 'createPendency']);
        Route::post('/{opening:uuid}/resume-analysis', [AccountOpeningReviewController::class, 'resumeAnalysis']);
        Route::post('/{opening:uuid}/block', [AccountOpeningReviewController::class, 'block']);
        Route::post('/{opening:uuid}/unblock', [AccountOpeningReviewController::class, 'unblock']);
        Route::post('/{opening:uuid}/deactivate', [AccountOpeningReviewController::class, 'deactivate']);
        Route::post('/{opening:uuid}/reactivate', [AccountOpeningReviewController::class, 'reactivate']);
        Route::delete('/{opening:uuid}', [AccountOpeningReviewController::class, 'destroy']);
    });
});
