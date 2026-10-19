<?php

namespace App\Console\Commands;

use App\Gateways\GatewayPix;
use App\Messaging\EventBus;
use App\Models\Pagamento;
use App\Services\ConfirmacaoPagamento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Worker: roda no container "pagamentos-worker". */
class ConsumirEventos extends Command
{
    protected $signature = 'eventos:consumir';
    protected $description = 'Gera cobrança Pix quando um pedido é criado';

    public function handle(EventBus $bus, GatewayPix $gateway, ConfirmacaoPagamento $confirmacao): void
    {
        $this->info("Pagamentos escutando eventos... (gateway: {$gateway->nome()})");

        $bus->consumir('pagamentos.fila', [
            'pedido.criado' => function (array $dados, array $env) use ($gateway, $confirmacao) {
                // Idempotência: mesma mensagem entregue 2x não gera 2 cobranças
                $novo = DB::table('eventos_processados')->insertOrIgnore([
                    'evento_id' => $env['id'], 'processado_em' => now(),
                ]);
                if (! $novo || Pagamento::where('pedido_codigo', $dados['codigo'])->exists()) {
                    return;
                }

                $pagamento = new Pagamento([
                    'pedido_codigo'  => $dados['codigo'],
                    'cliente_id'     => $dados['cliente_id'] ?? null,
                    'valor_centavos' => $dados['total_centavos'],
                    'gateway'        => $gateway->nome(),
                ]);

                try {
                    $pagamento->fill($gateway->criarCobranca(
                        $dados['codigo'], $dados['total_centavos'], $dados['cliente_nome'] ?? 'Cliente'
                    ))->save();

                    $this->line("Cobrança Pix ({$gateway->nome()}) gerada para {$dados['codigo']}: {$pagamento->gateway_id}");
                } catch (\Throwable $e) {
                    report($e);
                    $pagamento->fill(['gateway_id' => 'erro_' . $dados['codigo'], 'erro' => $e->getMessage()])->save();
                    $this->error("Falha ao gerar Pix para {$dados['codigo']}: {$e->getMessage()}");

                    // Compensação da saga: sem cobrança, o pedido é cancelado
                    $confirmacao->aplicar($pagamento, GatewayPix::RECUSADO);
                }
            },
        ]);
    }
}
