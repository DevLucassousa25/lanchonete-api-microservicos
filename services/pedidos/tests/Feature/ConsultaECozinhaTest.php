<?php

namespace Tests\Feature;

use App\Messaging\EventBus;
use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConsultaECozinhaTest extends TestCase
{
    use RefreshDatabase;

    private array $publicados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $bus = Mockery::mock(EventBus::class);
        $bus->shouldReceive('publicar')->andReturnUsing(fn ($e, $d) => $this->publicados[] = [$e, $d]);
        $this->app->instance(EventBus::class, $bus);
    }

    private function criarPedido(int $clienteId, string $status = 'aguardando_pagamento'): Pedido
    {
        $p = Pedido::create([
            'codigo' => (string) Str::uuid(), 'cliente_id' => $clienteId, 'cliente_nome' => "Cliente {$clienteId}",
            'cliente_telefone' => '85999990000', 'tipo_entrega' => 'retirada', 'total_centavos' => 1800, 'status' => $status,
        ]);
        $p->itens()->create(['produto_id' => 1, 'nome_produto' => 'X-Burguer', 'preco_unitario_centavos' => 1800, 'quantidade' => 1]);

        return $p;
    }

    #[Test]
    public function cliente_ve_so_os_proprios_pedidos(): void
    {
        $meu = $this->criarPedido(1);
        $this->criarPedido(2);

        $this->getJson('/api/pedidos', $this->comToken(1))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.codigo', $meu->codigo);

        $this->getJson('/api/pedidos', $this->comoAdmin())->assertJsonPath('total', 2);
    }

    #[Test]
    public function filtra_por_status(): void
    {
        $this->criarPedido(1);
        $this->criarPedido(1, 'pago');

        $this->getJson('/api/pedidos?status=pago', $this->comToken(1))->assertJsonPath('total', 1);
    }

    #[Test]
    public function detalhe_so_para_dono_ou_admin(): void
    {
        $p = $this->criarPedido(1);

        $this->getJson("/api/pedidos/{$p->codigo}", $this->comToken(1))->assertOk()->assertJsonPath('data.itens.0.nome_produto', 'X-Burguer');
        $this->getJson("/api/pedidos/{$p->codigo}", $this->comToken(2))->assertForbidden();
        $this->getJson("/api/pedidos/{$p->codigo}", $this->comoAdmin())->assertOk();
    }

    #[Test]
    public function codigo_invalido_ou_inexistente_e_404_e_token_ruim_e_401(): void
    {
        $this->getJson('/api/pedidos/nao-e-uuid', $this->comToken())->assertNotFound();
        $this->getJson('/api/pedidos/' . Str::uuid(), $this->comToken())->assertNotFound();
        $this->getJson('/api/pedidos/' . Str::uuid(), ['Authorization' => 'Bearer x.y.z'])->assertUnauthorized();
    }

    #[Test]
    public function cozinha_mostra_so_pedidos_pagos_e_em_andamento(): void
    {
        $this->criarPedido(1, 'aguardando_pagamento');
        $this->criarPedido(1, 'cancelado');
        $this->criarPedido(1, 'entregue');
        $pago = $this->criarPedido(1, 'pago');
        $preparo = $this->criarPedido(2, 'em_preparo');

        $data = $this->getJson('/api/cozinha', $this->comoAdmin())->assertOk()->json('data');

        $this->assertSame([$pago->codigo], array_column($data['pago'], 'codigo'));
        $this->assertSame([$preparo->codigo], array_column($data['em_preparo'], 'codigo'));
        $this->assertSame([], $data['pronto']);
    }

    #[Test]
    public function cozinha_e_so_para_admin(): void
    {
        $p = $this->criarPedido(1, 'pago');

        $this->getJson('/api/cozinha', $this->comToken(1))->assertForbidden();
        $this->postJson("/api/pedidos/{$p->codigo}/avancar", [], $this->comToken(1))->assertForbidden();
    }

    #[Test]
    public function avanca_o_fluxo_ate_entregue_e_publica_eventos(): void
    {
        $p = $this->criarPedido(1, 'pago');

        foreach (['em_preparo', 'pronto', 'entregue'] as $esperado) {
            $this->postJson("/api/pedidos/{$p->codigo}/avancar", [], $this->comoAdmin())
                ->assertOk()
                ->assertJsonPath('data.status', $esperado);
        }

        $this->postJson("/api/pedidos/{$p->codigo}/avancar", [], $this->comoAdmin())->assertStatus(409);
        $this->assertSame(['pedido.em_preparo', 'pedido.pronto', 'pedido.entregue'], array_column($this->publicados, 0));
    }

    #[Test]
    public function pedido_nao_pago_nao_entra_na_cozinha(): void
    {
        $p = $this->criarPedido(1, 'aguardando_pagamento');

        $this->postJson("/api/pedidos/{$p->codigo}/avancar", [], $this->comoAdmin())->assertStatus(409);
        $this->assertSame('aguardando_pagamento', $p->fresh()->status);
    }
}
