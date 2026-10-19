<?php

namespace App\Auth;

use Illuminate\Http\Request;

/** Usuário autenticado, montado a partir das claims do JWT. */
final class Usuario
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly string $papel,      // cliente | admin
        public readonly ?string $telefone,
        public readonly string $token,      // repassado em chamadas a outros serviços
        public readonly string $jti,
        public readonly int $exp,
    ) {}

    public static function fromClaims(array $c, string $token): self
    {
        return new self((int) $c['sub'], $c['nome'], $c['email'], $c['papel'], $c['telefone'] ?? null, $token, $c['jti'], $c['exp']);
    }

    /** Usuário da requisição atual (definido pelo middleware auth.jwt). */
    public static function atual(Request $request): self
    {
        return $request->attributes->get('usuario') ?? abort(401, 'Não autenticado.');
    }

    public function isAdmin(): bool
    {
        return $this->papel === 'admin';
    }

    /** Dono do recurso ou admin; senão 403. */
    public function autorizarDono(?int $donoId): void
    {
        if (! $this->isAdmin() && $donoId !== $this->id) {
            abort(403, 'Você não tem acesso a este recurso.');
        }
    }
}
