<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteAdminController;
use App\Http\Controllers\EnderecoController;
use App\Http\Controllers\PerfilController;
use Illuminate\Support\Facades\Route;

// ---------- Público ----------
Route::post('/auth/registrar', [AuthController::class, 'registrar'])->middleware('throttle:registro');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// ---------- Usuário logado ----------
Route::middleware('auth.jwt')->group(function () {
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', [PerfilController::class, 'show']);
    Route::patch('/me', [PerfilController::class, 'update']);
    Route::put('/me/senha', [PerfilController::class, 'alterarSenha']);

    Route::get('/me/enderecos', [EnderecoController::class, 'index']);
    Route::post('/me/enderecos', [EnderecoController::class, 'store']);
    Route::get('/me/enderecos/{id}', [EnderecoController::class, 'show'])->whereNumber('id');
    Route::patch('/me/enderecos/{id}', [EnderecoController::class, 'update'])->whereNumber('id');
    Route::delete('/me/enderecos/{id}', [EnderecoController::class, 'destroy'])->whereNumber('id');
});

// ---------- Admin ----------
Route::middleware('auth.jwt:admin')->group(function () {
    Route::get('/clientes', [ClienteAdminController::class, 'index']);
    Route::get('/clientes/{id}', [ClienteAdminController::class, 'show'])->whereNumber('id');
});
