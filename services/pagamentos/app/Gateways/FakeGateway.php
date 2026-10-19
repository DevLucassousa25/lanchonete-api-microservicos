<?php

namespace App\Gateways;

use Illuminate\Support\Str;

/**
 * Pix simulado: não fala com ninguém.
 * O pagamento é confirmado chamando o webhook manualmente (ver README).
 */
class FakeGateway implements GatewayPix
{
    public function nome(): string
    {
        return 'fake';
    }

    public function criarCobranca(string $pedidoCodigo, int $valorCentavos, string $clienteNome): array
    {
        return [
            'gateway_id'       => 'fake_' . Str::random(10),
            'pix_copia_e_cola' => '00020126...FAKE...' . $pedidoCodigo,
            'qr_code_base64'   => null,
            'ticket_url'       => null,
        ];
    }

    public function consultarStatus(string $gatewayId): string
    {
        // No fake, quem muda o status é o webhook simulado
        return self::PENDENTE;
    }
}
