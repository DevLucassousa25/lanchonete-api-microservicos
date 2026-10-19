<?php

namespace Tests\Feature;

use App\Auth\Jwt;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutenticacaoTest extends TestCase
{
    use RefreshDatabase;

    private function dadosCadastro(array $extra = []): array
    {
        return [
            'nome' => 'Maria Souza', 'email' => 'maria@teste.dev', 'telefone' => '85999990000',
            'senha' => 'segredo123', 'senha_confirmation' => 'segredo123', ...$extra,
        ];
    }

    #[Test]
    public function cliente_se_cadastra_e_recebe_token(): void
    {
        $resp = $this->postJson('/api/auth/registrar', $this->dadosCadastro());

        $resp->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('usuario.papel', 'cliente')
            ->assertJsonMissingPath('usuario.senha');

        $claims = Jwt::decode($resp->json('access_token'), config('services.jwt.secret'));
        $this->assertSame('cliente', $claims['papel']);
        $this->assertSame('Maria Souza', $claims['nome']);

        // senha guardada com hash, nunca em texto puro
        $this->assertNotSame('segredo123', Usuario::first()->senha);
    }

    #[Test]
    public function cadastro_nao_permite_virar_admin(): void
    {
        $this->postJson('/api/auth/registrar', $this->dadosCadastro(['papel' => 'admin']))
            ->assertCreated()
            ->assertJsonPath('usuario.papel', 'cliente');
    }

    #[Test]
    public function cadastro_valida_campos(): void
    {
        $this->postJson('/api/auth/registrar', $this->dadosCadastro(['senha_confirmation' => 'diferente', 'email' => 'invalido']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['senha', 'email']);
    }

    #[Test]
    public function email_e_cpf_sao_unicos(): void
    {
        $this->postJson('/api/auth/registrar', $this->dadosCadastro(['cpf' => '12345678901']))->assertCreated();

        $this->postJson('/api/auth/registrar', $this->dadosCadastro(['cpf' => '12345678901']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'cpf']);
    }

    #[Test]
    public function login_com_credenciais_corretas(): void
    {
        $this->postJson('/api/auth/registrar', $this->dadosCadastro());

        $this->postJson('/api/auth/login', ['email' => 'maria@teste.dev', 'senha' => 'segredo123'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'usuario' => ['id', 'email']]);
    }

    #[Test]
    public function login_com_senha_errada_ou_email_inexistente_da_a_mesma_resposta(): void
    {
        $this->postJson('/api/auth/registrar', $this->dadosCadastro());

        $senhaErrada = $this->postJson('/api/auth/login', ['email' => 'maria@teste.dev', 'senha' => 'errada']);
        $semConta = $this->postJson('/api/auth/login', ['email' => 'ninguem@teste.dev', 'senha' => 'errada']);

        $senhaErrada->assertUnauthorized();
        $semConta->assertUnauthorized();
        $this->assertSame($senhaErrada->json('message'), $semConta->json('message'));
    }

    #[Test]
    public function login_bloqueia_apos_5_tentativas_por_minuto(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/auth/login', ['email' => 'alvo@teste.dev', 'senha' => 'x'])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => 'alvo@teste.dev', 'senha' => 'x'])->assertStatus(429);

        // outro e-mail do mesmo IP não é bloqueado (limite é por e-mail + IP)
        $this->postJson('/api/auth/login', ['email' => 'outro@teste.dev', 'senha' => 'x'])->assertUnauthorized();
    }

    #[Test]
    public function rotas_protegidas_exigem_token_valido(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/me', ['Authorization' => 'Bearer lixo'])->assertUnauthorized();
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $this->tokenPara(1, 'cliente', ['exp' => time() - 10])])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token expirado.');
    }

    #[Test]
    public function logout_revoga_o_token(): void
    {
        $token = $this->postJson('/api/auth/registrar', $this->dadosCadastro())->json('access_token');
        $h = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/me', $h)->assertOk();
        $this->postJson('/api/auth/logout', [], $h)->assertOk();
        $this->getJson('/api/me', $h)->assertUnauthorized()->assertJsonPath('message', 'Token revogado. Faça login novamente.');
    }

    #[Test]
    public function refresh_emite_token_novo_e_revoga_o_antigo(): void
    {
        $antigo = $this->postJson('/api/auth/registrar', $this->dadosCadastro())->json('access_token');

        $novo = $this->postJson('/api/auth/refresh', [], ['Authorization' => "Bearer {$antigo}"])
            ->assertOk()
            ->json('access_token');

        $this->assertNotSame($antigo, $novo);
        $this->getJson('/api/me', ['Authorization' => "Bearer {$antigo}"])->assertUnauthorized();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$novo}"])->assertOk();
    }
}
