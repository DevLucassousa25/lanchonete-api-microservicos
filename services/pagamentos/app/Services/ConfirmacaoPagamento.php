<?php

namespace App\Services;

use App\Gateways\GatewayPix;
use App\Messaging\EventBus;
use App\Models\Pagamento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar que muda o status de um pagamento e avisa o resto do sistema.
 * Usado pelo webhook e pela consulta ativa (polling) — os dois caem aqui.
 */
class ConfirmacaoPagamento
{
    public function __construct(private GatewayPix $gateway) {}

    /** Pergunta ao provedor como está o pagamento (no máximo 1x a cada 5s por pagamento). */
    public function sincronizar(Pagamento $pagamento): Pagamento
    {
        if ($pagamento->status !== GatewayPix::PENDENTE || $pagamento->gateway !== $this->gateway->nome()) {
            return $pagamento;
        }

        if (! Cache::add("pagamento:sync:{$pagamento->id}", true, now()->addSeconds(5))) {
            return $pagamento; // já consultou há pouco
        }

        try {
            $status = $this->gateway->consultarStatus($pagamento->gateway_id);
        } catch (\Throwable $e) {
            report($e);
            return $pagamento; // tenta de novo na próxima
        }

        return $this->aplicar($pagamento, $status);
    }

    /** Aplica o novo status e publica pagamento.aprovado / pagamento.recusado (só uma vez). */
    public function aplicar(Pagamento $pagamento, string $status): Pagamento
    {
        if ($status === GatewayPix::PENDENTE) {
            return $pagamento;
        }

        $mudou = DB::transaction(function () use ($pagamento, $status) {
            // trava a linha: webhook e polling podem chegar ao mesmo tempo
            $atual = Pagamento::lockForUpdate()->find($pagamento->id);
            if ($atual->status !== GatewayPix::PENDENTE) {
                return false;
            }
            $atual->update(['status' => $status]);

            return true;
        });

        if ($mudou) {
            app(EventBus::class)->publicar("pagamento.{$status}", [
                'pedido_codigo'  => $pagamento->pedido_codigo,
                'valor_centavos' => $pagamento->valor_centavos,
            ]);
        }

        return $pagamento->refresh();
    }
}
