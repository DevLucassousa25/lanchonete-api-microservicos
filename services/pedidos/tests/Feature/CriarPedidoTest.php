<?php

namespace Tests\Feature;

use App\Messaging\EventBus;
use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CriarPedidoTest extends TestCase
{
    use RefreshDatabase;

    /** Eventos publicados durante o teste (no lugar do RabbitMQ). */
    private array $publicados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $bus = Mockery::mock(EventBus::class);
        $bus->shouldReceive('publicar')->andReturnUsing(function ($evento, $dados) {
            $this->publicados[] = [$evento, $dados];
        });
        $this->app->instance(EventBus::class, $bus);

        // Serviços vizinhos simulados
        Http::fake([
            'catalogo.test/api/produtos/cotacao' => Http::response(['data' => [
                1 => ['id' => 1, 'nome' => 'X-Burguer', 'preco_centavos' => 1800],
                2 => ['id' => 2, 'nome' => 'X-Tudo', 'preco_centavos' => 2800],
            ]]),
            'contas.test/api/me/enderecos/10' => Http::response(['data' => [
                'id' => 10, 'texto' => 'Rua das Flores, 123 - Centro, Fortaleza/CE - CEP 60000-000',
            ]]),
            'contas.test/api/me/enderecos/*' => Http::response(['message' => 'Not found'], 404),
        ]);
    }

    private function pedido(array $extra = []): array
    {
        return ['tipo_entrega' => 'retirada', 'itens' => [['produto_id' => 2, 'quantidade' => 2], ['produto_id' => 1, 'quantidade' => 1]], ...$extra];
    }

    #[Test]
    public function exige_login(): void
    {
        $this->postJson('/api/pedidos', $this->pedido())->assertUnauthorized();
    }

    #[Test]
    public function cria_pedido_com_preco_do_catalogo_e_publica_evento(): void
    {
        $resp = $this->postJson('/api/pedidos', $this->pedido(), $this->comToken(7));

        $resp->assertCreated()
            ->assertJsonPath('data.status', 'aguardando_pagamento')
            ->assertJsonPath('data.total_centavos', 2 * 2800 + 1800)
            ->assertJsonPath('data.cliente_id', 7)
            ->assertJsonPath('data.cliente_nome', 'Usuário 7')
            ->assertJsonCount(2, 'data.itens')
            ->assertJsonMissingPath('data.id');

        $this->assertCount(1, $this->publicados);
        [$evento, $dados] = $this->publicados[0];
        $this->assertSame('pedido.criado', $evento);
        $this->assertSame(['codigo' => $resp->json('data.codigo'), 'cliente_id' => 7, 'total_centavos' => 7400, 'cliente_nome' => 'Usuário 7'], $dados);
    }

    #[Test]
    public function ignora_preco_enviado_pelo_cliente(): void
    {
        $this->postJson('/api/pedidos', $this->pedido(['itens' => [
            ['produto_id' => 2, 'quantidade' => 1, 'preco_centavos' => 1, 'preco_unitario_centavos' => 1],
        ], 'total_centavos' => 1]), $this->comToken())
            ->assertCreated()
            ->assertJsonPath('data.total_centavos', 2800)
            ->assertJsonPath('data.itens.0.preco_unitario_centavos', 2800);
    }

    #[Test]
    public function guarda_snapshot_do_produto(): void
    {
        $codigo = $this->postJson('/api/pedidos', $this->pedido(), $this->comToken())->json('data.codigo');

        $this->assertDatabaseHas('itens_pedido', ['nome_produto' => 'X-Tudo', 'preco_unitario_centavos' => 2800, 'quantidade' => 2]);
        $this->assertNotNull(Pedido::where('codigo', $codigo)->first());
    }

    #[Test]
    public function produto_indisponivel_e_rejeitado_sem_publicar_evento(): void
    {
        $this->postJson('/api/pedidos', $this->pedido(['itens' => [['produto_id' => 99, 'quantidade' => 1]]]), $this->comToken())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('itens');

        $this->assertSame(0, Pedido::count());
        $this->assertSame([], $this->publicados);
    }

    #[Test]
    public function delivery_busca_endereco_no_servico_de_contas_com_o_token_do_cliente(): void
    {
        $token = $this->tokenPara(7);

        $this->postJson('/api/pedidos', $this->pedido(['tipo_entrega' => 'delivery', 'endereco_id' => 10]), ['Authorization' => "Bearer {$token}"])
            ->assertCreated()
            ->assertJsonPath('data.endereco', 'Rua das Flores, 123 - Centro, Fortaleza/CE - CEP 60000-000');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/me/enderecos/10')
            && $r->hasHeader('Authorization', "Bearer {$token}"));
    }

    #[Test]
    public function delivery_exige_endereco_da_propria_conta(): void
    {
        $this->postJson('/api/pedidos', $this->pedido(['tipo_entrega' => 'delivery']), $this->comToken())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endereco_id');

        $this->postJson('/api/pedidos', $this->pedido(['tipo_entrega' => 'delivery', 'endereco_id' => 55]), $this->comToken())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endereco_id');
    }

    #[Test]
    public function valida_itens(): void
    {
        $this->postJson('/api/pedidos', ['tipo_entrega' => 'retirada', 'itens' => []], $this->comToken())
            ->assertJsonValidationErrors('itens');

        $this->postJson('/api/pedidos', $this->pedido(['itens' => [['produto_id' => 1, 'quantidade' => 0], ['produto_id' => 1, 'quantidade' => 50]]]), $this->comToken())
            ->assertJsonValidationErrors(['itens.0.quantidade', 'itens.1.quantidade', 'itens.0.produto_id']);
    }
}
