<?php

namespace App\Console\Commands;

use App\Gateways\GatewayPix;
use App\Models\Pagamento;
use App\Services\ConfirmacaoPagamento;
use Illuminate\Console\Command;

/**
 * Consulta no provedor os pagamentos pendentes das últimas 24h.
 * Roda a cada 10s pelo agendador (container pagamentos-scheduler).
 * Garante a confirmação mesmo sem webhook (ex.: rodando local, sem URL pública).
 */
class SincronizarPagamentos extends Command
{
    protected $signature = 'pagamentos:sincronizar';
    protected $description = 'Confere no provedor o status dos Pix pendentes';

    public function handle(GatewayPix $gateway, ConfirmacaoPagamento $confirmacao): void
    {
        if ($gateway->nome() === 'fake') {
            return; // no modo fake a confirmação é manual (webhook)
        }

        Pagamento::where('status', GatewayPix::PENDENTE)
            ->where('gateway', $gateway->nome())
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (Pagamento $p) use ($confirmacao) {
                $novo = $confirmacao->sincronizar($p);
                if ($novo->status !== GatewayPix::PENDENTE) {
                    $this->line("Pagamento do pedido {$p->pedido_codigo} → {$novo->status}");
                }
            });
    }
}
