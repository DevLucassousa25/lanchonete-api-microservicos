<?php

namespace Tests\Unit;

use App\Auth\Jwt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class JwtTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste-0123456789abcdef';

    #[Test]
    public function codifica_e_decodifica_as_claims(): void
    {
        $token = Jwt::encode(['sub' => '7', 'papel' => 'admin', 'exp' => time() + 60], self::SEGREDO);

        $claims = Jwt::decode($token, self::SEGREDO);

        $this->assertSame('7', $claims['sub']);
        $this->assertSame('admin', $claims['papel']);
        $this->assertCount(3, explode('.', $token));
    }

    #[Test]
    public function rejeita_token_com_segredo_diferente(): void
    {
        $token = Jwt::encode(['sub' => '1', 'exp' => time() + 60], self::SEGREDO);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Assinatura inválida');
        Jwt::decode($token, 'outro-segredo-qualquer-0123456789');
    }

    #[Test]
    public function rejeita_payload_adulterado(): void
    {
        [$h, , $s] = explode('.', Jwt::encode(['sub' => '1', 'papel' => 'cliente', 'exp' => time() + 60], self::SEGREDO));
        // Tenta virar admin trocando o payload e mantendo a assinatura antiga
        $falso = rtrim(strtr(base64_encode(json_encode(['sub' => '1', 'papel' => 'admin', 'exp' => time() + 60])), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);
        Jwt::decode("$h.$falso.$s", self::SEGREDO);
    }

    #[Test]
    public function rejeita_token_expirado(): void
    {
        $token = Jwt::encode(['sub' => '1', 'exp' => time() - 1], self::SEGREDO);

        $this->expectExceptionMessage('Token expirado');
        Jwt::decode($token, self::SEGREDO);
    }

    #[Test]
    public function rejeita_algoritmo_none(): void
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $token = $b64(['alg' => 'none', 'typ' => 'JWT']) . '.' . $b64(['sub' => '1', 'papel' => 'admin', 'exp' => time() + 60]) . '.';

        $this->expectExceptionMessage('Algoritmo não suportado');
        Jwt::decode($token, self::SEGREDO);
    }

    #[Test]
    public function rejeita_token_malformado(): void
    {
        $this->expectException(RuntimeException::class);
        Jwt::decode('nao-e-um-jwt', self::SEGREDO);
    }
}
