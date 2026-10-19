<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerfilEEnderecosTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(string $email = 'maria@teste.dev', string $papel = 'cliente'): Usuario
    {
        return Usuario::create([
            'nome' => 'Maria', 'email' => $email, 'senha' => 'segredo123', 'papel' => $papel, 'telefone' => '85999990000',
        ]);
    }

    private function endereco(array $extra = []): array
    {
        return ['cep' => '60000000', 'logradouro' => 'Rua das Flores', 'numero' => '123', 'bairro' => 'Centro',
                'cidade' => 'Fortaleza', 'uf' => 'ce', ...$extra];
    }

    #[Test]
    public function mostra_e_atualiza_o_perfil(): void
    {
        $u = $this->criarUsuario();
        $h = $this->comToken($u->id);

        $this->getJson('/api/me', $h)->assertOk()->assertJsonPath('data.email', 'maria@teste.dev');

        $this->patchJson('/api/me', ['nome' => 'Maria Souza', 'cpf' => '12345678901'], $h)
            ->assertOk()
            ->assertJsonPath('data.nome', 'Maria Souza')
            ->assertJsonPath('data.cpf', '12345678901');
    }

    #[Test]
    public function nao_deixa_usar_email_de_outro_usuario(): void
    {
        $this->criarUsuario('outra@teste.dev');
        $u = $this->criarUsuario();

        $this->patchJson('/api/me', ['email' => 'outra@teste.dev'], $this->comToken($u->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function altera_senha_somente_com_a_senha_atual_correta(): void
    {
        $u = $this->criarUsuario();
        $h = $this->comToken($u->id);

        $this->putJson('/api/me/senha', ['senha_atual' => 'errada', 'nova_senha' => 'novasenha1', 'nova_senha_confirmation' => 'novasenha1'], $h)
            ->assertUnprocessable();

        $this->putJson('/api/me/senha', ['senha_atual' => 'segredo123', 'nova_senha' => 'novasenha1', 'nova_senha_confirmation' => 'novasenha1'], $h)
            ->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'maria@teste.dev', 'senha' => 'novasenha1'])->assertOk();
    }

    #[Test]
    public function primeiro_endereco_vira_principal_e_so_existe_um_principal(): void
    {
        $u = $this->criarUsuario();
        $h = $this->comToken($u->id);

        $primeiro = $this->postJson('/api/me/enderecos', $this->endereco(), $h)
            ->assertCreated()
            ->assertJsonPath('data.principal', true)
            ->assertJsonPath('data.uf', 'CE')
            ->json('data.id');

        $this->postJson('/api/me/enderecos', $this->endereco(['apelido' => 'Trabalho', 'principal' => true]), $h)->assertCreated();

        $lista = $this->getJson('/api/me/enderecos', $h)->assertOk()->json('data');
        $this->assertCount(2, $lista);
        $this->assertCount(1, array_filter($lista, fn ($e) => $e['principal']));
        $this->assertFalse(collect($lista)->firstWhere('id', $primeiro)['principal']);
    }

    #[Test]
    public function endereco_tem_texto_formatado_para_o_pedido(): void
    {
        $u = $this->criarUsuario();

        $this->postJson('/api/me/enderecos', $this->endereco(['complemento' => 'Apto 2']), $this->comToken($u->id))
            ->assertJsonPath('data.texto', 'Rua das Flores, 123 (Apto 2) - Centro, Fortaleza/CE - CEP 60000-000');
    }

    #[Test]
    public function cliente_nao_acessa_endereco_de_outro_cliente(): void
    {
        $dono = $this->criarUsuario('dono@teste.dev');
        $intruso = $this->criarUsuario('intruso@teste.dev');
        $id = $this->postJson('/api/me/enderecos', $this->endereco(), $this->comToken($dono->id))->json('data.id');

        $h = $this->comToken($intruso->id);
        $this->getJson("/api/me/enderecos/{$id}", $h)->assertNotFound();
        $this->patchJson("/api/me/enderecos/{$id}", ['numero' => '1'], $h)->assertNotFound();
        $this->deleteJson("/api/me/enderecos/{$id}", [], $h)->assertNotFound();

        $this->getJson("/api/me/enderecos/{$id}", $this->comToken($dono->id))->assertOk();
    }

    #[Test]
    public function atualiza_e_remove_endereco(): void
    {
        $u = $this->criarUsuario();
        $h = $this->comToken($u->id);
        $id = $this->postJson('/api/me/enderecos', $this->endereco(), $h)->json('data.id');

        $this->patchJson("/api/me/enderecos/{$id}", ['numero' => '500'], $h)->assertOk()->assertJsonPath('data.numero', '500');
        $this->deleteJson("/api/me/enderecos/{$id}", [], $h)->assertNoContent();
        $this->getJson('/api/me/enderecos', $h)->assertJsonCount(0, 'data');
    }

    #[Test]
    public function valida_endereco(): void
    {
        $u = $this->criarUsuario();

        $this->postJson('/api/me/enderecos', $this->endereco(['cep' => '123', 'uf' => 'CEA']), $this->comToken($u->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cep', 'uf']);
    }
}
