<?php

namespace App\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Chamada síncrona ao serviço de Contas, repassando o token do PRÓPRIO cliente:
 * assim o serviço de contas só devolve endereços que pertencem a ele.
 */
class ContasClient
{
    public function endereco(int $enderecoId, string $tokenCliente): array
    {
        $resp = Http::baseUrl(env('CONTAS_URL'))
            ->withToken($tokenCliente)
            ->acceptJson()
            ->timeout(3)
            ->retry(2, 200, throw: false)
            ->get("/api/me/enderecos/{$enderecoId}");

        if ($resp->notFound()) {
            throw ValidationException::withMessages(['endereco_id' => 'Endereço não encontrado na sua conta.']);
        }

        return $resp->throw()->json('data');
    }
}
