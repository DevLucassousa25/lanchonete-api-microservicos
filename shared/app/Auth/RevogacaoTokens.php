<?php

namespace App\Auth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Lista de tokens revogados (logout/refresh), compartilhada entre os serviços.
 * Em produção é o store "jwt" (Redis com prefixo fixo, igual em todos os serviços);
 * nos testes vira "array" (memória) via JWT_REVOGACAO_STORE.
 */
final class RevogacaoTokens
{
    public static function revogar(string $jti, int $ate): void
    {
        self::store()->put("revogado:{$jti}", 1, max(1, $ate - time()));
    }

    public static function revogado(string $jti): bool
    {
        return self::store()->has("revogado:{$jti}");
    }

    private static function store(): Repository
    {
        return Cache::store(config('services.jwt.revogacao_store', 'jwt'));
    }
}
