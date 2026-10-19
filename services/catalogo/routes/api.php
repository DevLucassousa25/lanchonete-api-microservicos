<?php

use App\Http\Controllers\CardapioController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\PrecoController;
use App\Http\Controllers\ProdutoController;
use Illuminate\Support\Facades\Route;

// ---------- Público ----------
Route::get('/cardapio', [CardapioController::class, 'index']);
Route::get('/categorias', [CategoriaController::class, 'index']);
Route::get('/categorias/{categoria}', [CategoriaController::class, 'show'])->whereNumber('categoria');
Route::get('/produtos', [ProdutoController::class, 'index']);
Route::get('/produtos/{produto}', [ProdutoController::class, 'show'])->whereNumber('produto');

// Interno: chamado pelo serviço de pedidos (o gateway NÃO expõe esta rota)
Route::post('/produtos/cotacao', [CardapioController::class, 'cotacao']);

// ---------- Admin ----------
Route::middleware('auth.jwt:admin')->group(function () {
    Route::pattern('categoria', '[0-9]+');
    Route::pattern('produto', '[0-9]+');
    Route::pattern('preco', '[0-9]+');

    Route::post('/categorias', [CategoriaController::class, 'store']);
    Route::patch('/categorias/{categoria}', [CategoriaController::class, 'update']);
    Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy']);

    Route::post('/produtos', [ProdutoController::class, 'store']);
    Route::patch('/produtos/{produto}', [ProdutoController::class, 'update']);
    Route::delete('/produtos/{produto}', [ProdutoController::class, 'destroy']);

    Route::get('/produtos/{produto}/precos', [PrecoController::class, 'index']);
    Route::post('/produtos/{produto}/precos', [PrecoController::class, 'store']);
    Route::delete('/produtos/{produto}/precos/{preco}', [PrecoController::class, 'destroy']);
});
