<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
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

/*
|--------------------------------------------------------------------------
| Rotas autenticadas (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Gestão de empresas (whitelabel) — somente super admin
    Route::middleware('role:super_admin')->group(function () {
        Route::apiResource('companies', CompanyController::class);
    });
});
