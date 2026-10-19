<?php

namespace App\Gateways;

/**
 * Contrato de qualquer provedor de Pix (Strategy).
 * O resto do serviço não sabe se é Mercado Pago, Efí, Asaas ou o fake.
 */
interface GatewayPix
{
    /** Status normalizados usados no nosso banco */
    public const PENDENTE = 'pendente';
    public const APROVADO = 'aprovado';
    public const RECUSADO = 'recusado';

    public function nome(): string;

    /**
     * Cria a cobrança Pix.
     *
     * @return array{gateway_id: string, pix_copia_e_cola: string, qr_code_base64: ?string, ticket_url: ?string}
     */
    public function criarCobranca(string $pedidoCodigo, int $valorCentavos, string $clienteNome): array;

    /** Consulta o status atual no provedor (pendente | aprovado | recusado). */
    public function consultarStatus(string $gatewayId): string;
}
