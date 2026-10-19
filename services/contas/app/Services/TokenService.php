<?php

namespace App\Services;

use App\Auth\Jwt;
use App\Auth\RevogacaoTokens;
use App\Auth\Usuario as UsuarioToken;
use App\Models\Usuario;
use Illuminate\Support\Str;

class TokenService
{
    /** Emite um JWT e devolve o payload padrão de autenticação. */
    public function emitir(Usuario $usuario): array
    {
        $ttl = config('services.jwt.ttl') * 60;
        $agora = time();

        $token = Jwt::encode([
            'iss'      => 'lanchonete-contas',
            'sub'      => (string) $usuario->id,
            'nome'     => $usuario->nome,
            'email'    => $usuario->email,
            'papel'    => $usuario->papel,
            'telefone' => $usuario->telefone,
            'jti'      => (string) Str::uuid(),
            'iat'      => $agora,
            'exp'      => $agora + $ttl,
        ], (string) config('services.jwt.secret'));

        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => $ttl,
            'usuario'      => $usuario,
        ];
    }

    /** Revoga até o token expirar (os outros serviços consultam a mesma chave no Redis). */
    public function revogar(UsuarioToken $token): void
    {
        RevogacaoTokens::revogar($token->jti, $token->exp);
    }
}
