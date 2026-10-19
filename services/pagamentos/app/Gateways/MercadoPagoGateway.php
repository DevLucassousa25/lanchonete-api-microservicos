<?php

namespace App\Gateways;

use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Order\OrderClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RuntimeException;

/**
 * Pix via Mercado Pago — Orders API (SDK oficial mercadopago/dx-php).
 * Docs: https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders
 */
class MercadoPagoGateway implements GatewayPix
{
    private OrderClient $client;

    public function __construct(
        string $accessToken,
        private ?string $testPayerName = null,
        private string $payerEmail = 'test@testuser.com',
        private ?string $notificationUrl = null,
        ?OrderClient $client = null,
    ) {
        if ($accessToken === '') {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN não configurado.');
        }
        MercadoPagoConfig::setAccessToken($accessToken);
        $this->client = $client ?? new OrderClient();
    }

    public function nome(): string
    {
        return 'mercadopago';
    }

    public function criarCobranca(string $pedidoCodigo, int $valorCentavos, string $clienteNome): array
    {
        $valor = number_format($valorCentavos / 100, 2, '.', ''); // Orders API usa string "64.00"

        $request = [
            'type'               => 'online',
            'external_reference' => $pedidoCodigo,       // liga a order ao nosso pedido
            'total_amount'       => $valor,
            'payer' => [
                'email'      => $this->payerEmail,
                'first_name' => $this->testPayerName ?: $clienteNome,
            ],
            'transactions' => [
                'payments' => [[
                    'amount'         => $valor,
                    'payment_method' => ['id' => 'pix', 'type' => 'bank_transfer'],
                ]],
            ],
        ];
        if ($this->notificationUrl) {
            $request['notification_url'] = $this->notificationUrl;
        }

        // Idempotência: se o evento for reprocessado, o MP devolve a MESMA order em vez de criar outra
        $options = new RequestOptions();
        $options->setCustomHeaders(['X-Idempotency-Key: pedido-' . $pedidoCodigo]);

        try {
            $order = $this->client->create($request, $options)->getResponse()->getContent();
        } catch (MPApiException $e) {
            throw new RuntimeException('Mercado Pago recusou a criação do Pix: '
                . json_encode($e->getApiResponse()->getContent(), JSON_UNESCAPED_UNICODE), previous: $e);
        }

        if (empty($order['id'])) {
            throw new RuntimeException('Resposta inesperada do Mercado Pago: ' . json_encode($order, JSON_UNESCAPED_UNICODE));
        }

        $pix = $order['transactions']['payments'][0]['payment_method'] ?? [];

        return [
            'gateway_id'       => $order['id'],
            'pix_copia_e_cola' => $pix['qr_code'] ?? '',
            'qr_code_base64'   => ($pix['qr_code_base64'] ?? '') ?: null,
            'ticket_url'       => $pix['ticket_url'] ?? null,
        ];
    }

    public function consultarStatus(string $gatewayId): string
    {
        $order = $this->client->get($gatewayId)->getResponse()->getContent();

        return self::normalizarStatus(
            $order['status'] ?? null,
            $order['transactions']['payments'][0]['status'] ?? null,
            $order['transactions']['payments'][0]['status_detail'] ?? null,
        );
    }

    /** Converte os status do Mercado Pago para os nossos 3 status. */
    public static function normalizarStatus(?string $orderStatus, ?string $paymentStatus, ?string $detail): string
    {
        $todos = array_filter([$orderStatus, $paymentStatus, $detail]);

        if (array_intersect($todos, ['processed', 'approved', 'accredited'])) {
            return self::APROVADO;
        }
        if (array_intersect($todos, ['failed', 'rejected', 'cancelled', 'canceled', 'expired', 'refunded'])) {
            return self::RECUSADO;
        }

        return self::PENDENTE; // created, processing, action_required, waiting_transfer...
    }
}
