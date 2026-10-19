<?php

namespace Tests\Unit;

use App\Gateways\GatewayPix;
use App\Gateways\MercadoPagoGateway;
use MercadoPago\Client\Order\OrderClient;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Gateway do Mercado Pago usando o SDK de verdade; só a camada HTTP é simulada
 * (nenhuma chamada real à internet). Assim testamos a requisição exatamente como sai do SDK.
 */
class MercadoPagoGatewayTest extends TestCase
{
    /** @var MPRequest[] */
    private array $requisicoes = [];

    /** Cria o gateway com um "servidor do Mercado Pago" que responde $respostas em ordem. */
    private function gateway(array $respostas, ?string $nomeTeste = null): MercadoPagoGateway
    {
        $http = new class($respostas, $this->requisicoes) implements MPHttpClient {
            public function __construct(private array $respostas, private array &$log) {}

            public function send(MPRequest $request): MPResponse
            {
                $this->log[] = $request;

                return array_shift($this->respostas);
            }
        };

        return new MercadoPagoGateway('APP_USR-teste', testPayerName: $nomeTeste, client: new OrderClient($http));
    }

    private function headers(MPRequest $r): array
    {
        $h = [];
        foreach ($r->getHeaders() as $linha) {
            [$k, $v] = array_map('trim', explode(':', $linha, 2));
            $h[strtolower($k)] = $v;
        }

        return $h;
    }

    private function respostaPix(string $status = 'action_required', string $detalhe = 'waiting_transfer'): array
    {
        return [
            'id' => 'ORD01ABC', 'status' => $status, 'status_detail' => $detalhe,
            'transactions' => ['payments' => [[
                'id' => 'PAY01', 'status' => $status, 'status_detail' => $detalhe,
                'payment_method' => ['id' => 'pix', 'qr_code' => '00020126...PIX', 'qr_code_base64' => 'iVBOR', 'ticket_url' => 'https://mp/ticket'],
            ]]],
        ];
    }

    #[Test]
    public function cria_cobranca_pix_no_formato_da_orders_api(): void
    {
        $gw = $this->gateway([new MPResponse(201, $this->respostaPix())], nomeTeste: 'APRO');
        $cobranca = $gw->criarCobranca('pedido-123', 6400, 'Maria');

        $this->assertCount(1, $this->requisicoes);
        $req = $this->requisicoes[0];
        $enviado = json_decode($req->getPayload(), true);
        $headers = $this->headers($req);

        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('/v1/orders', $req->getUri());
        $this->assertSame('Bearer APP_USR-teste', $headers['authorization']);
        $this->assertSame('pedido-pedido-123', $headers['x-idempotency-key']);

        // o que foi enviado ao Mercado Pago
        $this->assertSame('online', $enviado['type']);
        $this->assertSame('pedido-123', $enviado['external_reference']);
        $this->assertSame('64.00', $enviado['total_amount']);
        $this->assertSame('64.00', $enviado['transactions']['payments'][0]['amount']);
        $this->assertSame(['id' => 'pix', 'type' => 'bank_transfer'], $enviado['transactions']['payments'][0]['payment_method']);
        $this->assertSame('APRO', $enviado['payer']['first_name']);

        // o que o gateway devolve para o nosso sistema
        $this->assertSame([
            'gateway_id' => 'ORD01ABC', 'pix_copia_e_cola' => '00020126...PIX',
            'qr_code_base64' => 'iVBOR', 'ticket_url' => 'https://mp/ticket',
        ], $cobranca);
    }

    #[Test]
    public function sem_nome_de_teste_usa_o_nome_do_cliente(): void
    {
        $this->gateway([new MPResponse(201, $this->respostaPix())])->criarCobranca('p1', 100, 'Maria');

        $this->assertSame('Maria', json_decode($this->requisicoes[0]->getPayload(), true)['payer']['first_name']);
    }

    #[Test]
    public function resposta_sem_id_vira_erro_claro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid token');
        $this->gateway([new MPResponse(401, ['message' => 'invalid token'])])->criarCobranca('p1', 100, 'Maria');
    }

    #[Test]
    public function exige_access_token(): void
    {
        $this->expectExceptionMessage('MERCADOPAGO_ACCESS_TOKEN');
        new MercadoPagoGateway('');
    }

    #[Test]
    public function consulta_status_da_order(): void
    {
        $gw = $this->gateway([new MPResponse(200, $this->respostaPix('processed', 'accredited'))]);

        $this->assertSame(GatewayPix::APROVADO, $gw->consultarStatus('ORD01ABC'));
        $this->assertSame('GET', $this->requisicoes[0]->getMethod());
        $this->assertStringEndsWith('/v1/orders/ORD01ABC', $this->requisicoes[0]->getUri());
    }

    public static function statusDoMercadoPago(): array
    {
        return [
            'aguardando Pix'  => ['action_required', 'action_required', 'waiting_transfer', GatewayPix::PENDENTE],
            'processando'     => ['processing', null, null, GatewayPix::PENDENTE],
            'pago'            => ['processed', 'processed', 'accredited', GatewayPix::APROVADO],
            'aprovado'        => [null, 'approved', null, GatewayPix::APROVADO],
            'expirado'        => ['expired', 'expired', null, GatewayPix::RECUSADO],
            'cancelado'       => ['canceled', null, null, GatewayPix::RECUSADO],
            'falhou'          => ['failed', 'rejected', null, GatewayPix::RECUSADO],
        ];
    }

    #[Test]
    #[DataProvider('statusDoMercadoPago')]
    public function normaliza_status(?string $order, ?string $pagamento, ?string $detalhe, string $esperado): void
    {
        $this->assertSame($esperado, MercadoPagoGateway::normalizarStatus($order, $pagamento, $detalhe));
    }
}
