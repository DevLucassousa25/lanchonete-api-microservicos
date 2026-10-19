<?php

namespace App\Console\Commands;

use App\Messaging\EventBus;
use App\Models\Pedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Worker: roda no container "pedidos-worker". */
class ConsumirEventos extends Command
{
    protected $signature = 'eventos:consumir';
    protected $description = 'Escuta eventos de pagamento e atualiza pedidos';

    public function handle(EventBus $bus): void
    {
        $this->info('Pedidos escutando eventos...');

        $bus->consumir('pedidos.fila', [
            'pagamento.aprovado' => fn (array $dados, array $env) =>
                $this->mudarStatus($env['id'], $dados['pedido_codigo'], 'pago'),

            // Compensação da saga: pagamento falhou → cancela o pedido
            'pagamento.recusado' => fn (array $dados, array $env) =>
                $this->mudarStatus($env['id'], $dados['pedido_codigo'], 'cancelado'),
        ]);
    }

    private function mudarStatus(string $eventoId, string $codigo, string $status): void
    {
        DB::transaction(function () use ($eventoId, $codigo, $status) {
            // Já processei essa mensagem? Ignora (idempotência)
            $inserido = DB::table('eventos_processados')->insertOrIgnore([
                'evento_id' => $eventoId, 'processado_em' => now(),
            ]);
            if (! $inserido) {
                return;
            }

            Pedido::where('codigo', $codigo)
                ->where('status', 'aguardando_pagamento')
                ->update(['status' => $status]);

            $this->line("Pedido {$codigo} → {$status}");
        });
    }
}
