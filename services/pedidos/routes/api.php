<?php

use App\Http\Controllers\PedidoController;
use Illuminate\Support\Facades\Route;

// ---------- Cliente logado (admin também pode) ----------
Route::middleware('auth.jwt')->group(function () {
    Route::get('/pedidos', [PedidoController::class, 'index']);
    Route::post('/pedidos', [PedidoController::class, 'store']);
    Route::get('/pedidos/{pedido}', [PedidoController::class, 'show'])->whereUuid('pedido');
});

// ---------- Cozinha / admin ----------
Route::middleware('auth.jwt:admin')->group(function () {
    Route::get('/cozinha', [PedidoController::class, 'cozinha']);
    Route::post('/pedidos/{pedido}/avancar', [PedidoController::class, 'avancar'])->whereUuid('pedido');
});
