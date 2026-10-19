<?php

namespace App\Http\Controllers;

use App\Auth\Usuario;
use App\Gateways\GatewayPix;
use App\Models\Pagamento;
use App\Services\ConfirmacaoPagamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagamentoController extends Controller
{
    /**
     * GET /api/pagamentos/{pedidoCodigo} — dono do pedido (ou admin) busca o Pix/QR code.
     * Enquanto estiver pendente, aproveita para consultar o provedor.
     */
    public function show(Request $request, string $pedidoCodigo, ConfirmacaoPagamento $confirmacao): JsonResponse
    {
        $pagamento = Pagamento::where('pedido_codigo', $pedidoCodigo)->first();
        if (! $pagamento) {
            // O Pix é gerado de forma assíncrona: logo após criar o pedido ele pode ainda não existir
            return response()->json(['message' => 'Cobrança ainda sendo gerada. Tente novamente em instantes.'], 404);
        }
        Usuario::atual($request)->autorizarDono($pagamento->cliente_id);

        return response()->json(['data' => $confirmacao->sincronizar($pagamento)]);
    }

    /**
     * POST /api/webhooks/pix — chamado pelo provedor quando o status muda.
     *
     * - Mercado Pago manda só o ID: {"type": "order", "data": {"id": "ORD..."}}.
     *   Nunca confiamos no corpo: consultamos o status direto na API do MP.
     * - Fake (dev): {"gateway_id": "fake_xxx", "status": "aprovado|recusado"}.
     */
    public function webhook(Request $request, GatewayPix $gateway, ConfirmacaoPagamento $confirmacao): JsonResponse
    {
        if ($gateway->nome() === 'fake') {
            $dados = $request->validate([
                'gateway_id' => 'required|string',
                'status'     => 'required|in:aprovado,recusado',
            ]);
            $pagamento = Pagamento::where('gateway_id', $dados['gateway_id'])->firstOrFail();
            $confirmacao->aplicar($pagamento, $dados['status']);

            return response()->json(['ok' => true]);
        }

        $gatewayId = $request->input('data.id') ?? $request->query('data_id') ?? $request->query('id');
        $pagamento = $gatewayId ? Pagamento::where('gateway_id', $gatewayId)->first() : null;

        if ($pagamento) {
            $confirmacao->aplicar($pagamento, $gateway->consultarStatus($pagamento->gateway_id));
        }

        // Sempre 200: senão o MP fica reenviando notificações de orders que não são nossas
        return response()->json(['ok' => true]);
    }
}
