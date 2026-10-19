<?php

use App\Http\Controllers\PagamentoController;
use Illuminate\Support\Facades\Route;

// Cliente dono do pedido (ou admin) consulta o Pix
Route::get('/pagamentos/{pedidoCodigo}', [PagamentoController::class, 'show'])
    ->middleware('auth.jwt')
    ->whereUuid('pedidoCodigo');

// Chamado pelo provedor de pagamento (público; o status é sempre confirmado na API do provedor)
Route::post('/webhooks/pix', [PagamentoController::class, 'webhook']);
