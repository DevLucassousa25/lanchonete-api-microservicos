<?php

namespace App\Auth;

use RuntimeException;

/**
 * JWT HS256 mínimo (sem dependência externa).
 * Emitido pelo serviço "contas"; os outros serviços só VALIDAM com o mesmo JWT_SECRET.
 * Copiado igual em todos os serviços (pasta shared/).
 */
final class Jwt
{
    public static function encode(array $claims, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $partes = [self::b64(json_encode($header)), self::b64(json_encode($claims))];
        $partes[] = self::b64(hash_hmac('sha256', implode('.', $partes), $secret, true));

        return implode('.', $partes);
    }

    /** @throws RuntimeException se o token for inválido ou expirado */
    public static function decode(string $token, string $secret): array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            throw new RuntimeException('Token malformado.');
        }
        [$h, $p, $s] = $partes;

        $header = json_decode(self::unb64($h), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Algoritmo não suportado.'); // bloqueia "alg: none"
        }

        $esperada = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
        if (! hash_equals($esperada, $s)) {
            throw new RuntimeException('Assinatura inválida.');
        }

        $claims = json_decode(self::unb64($p), true);
        if (! is_array($claims) || ($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expirado.');
        }

        return $claims;
    }

    private static function b64(string $dados): string
    {
        return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
    }

    private static function unb64(string $dados): string
    {
        return base64_decode(strtr($dados, '-_', '+/'));
    }
}
