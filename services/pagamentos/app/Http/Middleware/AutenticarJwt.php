<?php

namespace App\Http\Middleware;

use App\Auth\Jwt;
use App\Auth\RevogacaoTokens;
use App\Auth\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso nas rotas:
 *   ->middleware('auth.jwt')         qualquer usuário logado
 *   ->middleware('auth.jwt:admin')   só admin
 */
class AutenticarJwt
{
    public function handle(Request $request, Closure $next, string ...$papeis): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return $this->erro(401, 'Token ausente. Envie o header Authorization: Bearer <token>.');
        }

        try {
            $claims = Jwt::decode($token, (string) config('services.jwt.secret'));
        } catch (\RuntimeException $e) {
            return $this->erro(401, $e->getMessage());
        }

        // Logout/refresh: o serviço contas grava o jti revogado no Redis compartilhado
        if (RevogacaoTokens::revogado($claims['jti'])) {
            return $this->erro(401, 'Token revogado. Faça login novamente.');
        }

        $usuario = Usuario::fromClaims($claims, $token);
        if ($papeis && ! in_array($usuario->papel, $papeis, true)) {
            return $this->erro(403, 'Permissão insuficiente.');
        }

        $request->attributes->set('usuario', $usuario);

        return $next($request);
    }

    private function erro(int $status, string $mensagem): Response
    {
        return response()->json(['message' => $mensagem], $status);
    }
}
