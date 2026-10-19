<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['Maria Souza', 'maria@teste.dev'], ['João Lima', 'joao@teste.dev']] as [$nome, $email]) {
            Usuario::create(['nome' => $nome, 'email' => $email, 'senha' => 'segredo123', 'telefone' => '85999990000']);
        }
    }

    #[Test]
    public function cliente_nao_lista_clientes(): void
    {
        $this->getJson('/api/clientes', $this->comToken(1))->assertForbidden();
    }

    #[Test]
    public function admin_lista_e_busca_clientes(): void
    {
        $this->getJson('/api/clientes', $this->comoAdmin())->assertOk()->assertJsonPath('total', 2);

        $this->getJson('/api/clientes?busca=MARIA', $this->comoAdmin())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.email', 'maria@teste.dev');
    }

    #[Test]
    public function admin_ve_cliente_com_enderecos(): void
    {
        $maria = Usuario::where('email', 'maria@teste.dev')->first();

        $this->getJson("/api/clientes/{$maria->id}", $this->comoAdmin())
            ->assertOk()
            ->assertJsonPath('data.nome', 'Maria Souza')
            ->assertJsonPath('data.enderecos', []);
    }

    #[Test]
    public function seeder_cria_admin_inicial_sem_duplicar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Usuario::where('papel', 'admin')->count());
        $this->postJson('/api/auth/login', ['email' => 'admin@lanchonete.dev', 'senha' => 'admin12345'])
            ->assertOk()
            ->assertJsonPath('usuario.papel', 'admin');
    }
}
