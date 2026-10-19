<?php

namespace Tests;

use App\Auth\Jwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Gera um JWT válido como se fosse emitido pelo serviço "contas". */
    protected function tokenPara(int $id = 1, string $papel = 'cliente', array $extra = []): string
    {
        return Jwt::encode([
            'sub'      => (string) $id,
            'nome'     => $extra['nome'] ?? "Usuário {$id}",
            'email'    => "usuario{$id}@teste.dev",
            'papel'    => $papel,
            'telefone' => $extra['telefone'] ?? '85999990000',
            'jti'      => (string) Str::uuid(),
            'iat'      => time(),
            'exp'      => $extra['exp'] ?? time() + 3600,
        ], config('services.jwt.secret'));
    }

    /** Headers de uma requisição autenticada. */
    protected function comToken(int $id = 1, string $papel = 'cliente'): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokenPara($id, $papel), 'Accept' => 'application/json'];
    }

    protected function comoAdmin(int $id = 99): array
    {
        return $this->comToken($id, 'admin');
    }
}
