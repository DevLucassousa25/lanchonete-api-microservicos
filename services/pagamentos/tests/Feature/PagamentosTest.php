<?php

namespace Tests\Feature;

use App\Gateways\FakeGateway;
use App\Gateways\GatewayPix;
use App\Messaging\EventBus;
use App\Models\Pagamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PagamentosTest extends TestCase
{
    use RefreshDatabase;

    private array $publicados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->usarBus();
    }

    /** Mock do RabbitMQ: guarda o que foi publicado e entrega as mensagens informadas ao worker. */
    private function usarBus(array $mensagens = []): void
    {
        $bus = Mockery::mock(EventBus::class);
        $bus->shouldReceive('publicar')->andReturnUsing(fn ($e, $d) => $this->publicados[] = [$e, $d]);
        $bus->shouldReceive('consumir')->andReturnUsing(function ($fila, $handlers) use ($mensagens) {
            foreach ($mensagens as [$evento, $dados, $id]) {
                $handlers[$evento]($dados, ['id' => $id]);
            }
        });
        $this->app->instance(EventBus::class, $bus);
    }

    private function pedidoCriado(string $codigo, int $clienteId = 7, ?string $id = null): array
    {
        return ['pedido.criado', ['codigo' => $codigo, 'cliente_id' => $clienteId, 'total_centavos' => 6400, 'cliente_nome' => 'Maria'], $id ?? (string) Str::uuid()];
    }

    private function pagamento(int $clienteId = 7, string $status = 'pendente'): Pagamento
    {
        return Pagamento::create([
            'pedido_codigo' => (string) Str::uuid(), 'cliente_id' => $clienteId, 'valor_centavos' => 6400,
            'gateway' => 'fake', 'gateway_id' => 'fake_' . Str::random(6), 'pix_copia_e_cola' => '000201...', 'status' => $status,
        ]);
    }

    // ------------------------------------------------------------ worker

    #[Test]
    public function pedido_criado_gera_cobranca_pix_do_cliente(): void
    {
        $codigo = (string) Str::uuid();
        $this->usarBus([$this->pedidoCriado($codigo)]);

        $this->artisan('eventos:consumir')->assertSuccessful();

        $p = Pagamento::where('pedido_codigo', $codigo)->first();
        $this->assertNotNull($p);
        $this->assertSame(7, (int) $p->cliente_id);
        $this->assertSame(6400, (int) $p->valor_centavos);
        $this->assertSame('pendente', $p->status);
        $this->assertStringStartsWith('fake_', $p->gateway_id);
    }

    #[Test]
    public function mensagem_repetida_nao_gera_duas_cobrancas(): void
    {
        $codigo = (string) Str::uuid();
        $id = (string) Str::uuid();
        $this->usarBus([$this->pedidoCriado($codigo, id: $id), $this->pedidoCriado($codigo, id: $id), $this->pedidoCriado($codigo)]);

        $this->artisan('eventos:consumir')->assertSuccessful();

        $this->assertSame(1, Pagamento::where('pedido_codigo', $codigo)->count());
    }

    #[Test]
    public function falha_no_provedor_recusa_o_pagamento_e_cancela_o_pedido(): void
    {
        $gw = Mockery::mock(GatewayPix::class);
        $gw->shouldReceive('nome')->andReturn('mercadopago');
        $gw->shouldReceive('criarCobranca')->andThrow(new \RuntimeException('Mercado Pago fora do ar'));
        $this->app->instance(GatewayPix::class, $gw);

        $codigo = (string) Str::uuid();
        $this->usarBus([$this->pedidoCriado($codigo)]);
        $this->artisan('eventos:consumir')->assertSuccessful();

        $p = Pagamento::where('pedido_codigo', $codigo)->first();
        $this->assertSame('recusado', $p->status);
        $this->assertSame('Mercado Pago fora do ar', $p->erro);
        $this->assertSame([['pagamento.recusado', ['pedido_codigo' => $codigo, 'valor_centavos' => 6400]]], $this->publicados);
    }

    // ------------------------------------------------------------ consulta

    #[Test]
    public function dono_ve_o_pix_e_outro_cliente_nao(): void
    {
        $p = $this->pagamento(clienteId: 7);

        $this->getJson("/api/pagamentos/{$p->pedido_codigo}", $this->comToken(7))
            ->assertOk()
            ->assertJsonPath('data.pix_copia_e_cola', '000201...')
            ->assertJsonMissingPath('data.erro');

        $this->getJson("/api/pagamentos/{$p->pedido_codigo}", $this->comToken(8))->assertForbidden();
        $this->getJson("/api/pagamentos/{$p->pedido_codigo}", $this->comoAdmin())->assertOk();
        $this->getJson("/api/pagamentos/{$p->pedido_codigo}")->assertUnauthorized();
    }

    #[Test]
    public function pix_ainda_nao_gerado_responde_404_com_mensagem(): void
    {
        $this->getJson('/api/pagamentos/' . Str::uuid(), $this->comToken())
            ->assertNotFound()
            ->assertJsonPath('message', 'Cobrança ainda sendo gerada. Tente novamente em instantes.');

        $this->getJson('/api/pagamentos/nao-e-uuid', $this->comToken())->assertNotFound();
    }

    // ------------------------------------------------------------ webhook

    #[Test]
    public function webhook_aprova_e_publica_evento_uma_unica_vez(): void
    {
        $p = $this->pagamento();
        $corpo = ['gateway_id' => $p->gateway_id, 'status' => 'aprovado'];

        $this->postJson('/api/webhooks/pix', $corpo)->assertOk();
        $this->postJson('/api/webhooks/pix', $corpo)->assertOk();                                       // provedor reenviou
        $this->postJson('/api/webhooks/pix', [...$corpo, 'status' => 'recusado'])->assertOk();         // tentativa de reverter

        $this->assertSame('aprovado', $p->fresh()->status);
        $this->assertSame([['pagamento.aprovado', ['pedido_codigo' => $p->pedido_codigo, 'valor_centavos' => 6400]]], $this->publicados);
    }

    #[Test]
    public function webhook_valida_corpo(): void
    {
        $this->postJson('/api/webhooks/pix', ['gateway_id' => 'x', 'status' => 'qualquer'])->assertUnprocessable();
        $this->postJson('/api/webhooks/pix', ['gateway_id' => 'nao-existe', 'status' => 'aprovado'])->assertNotFound();
    }

    #[Test]
    public function webhook_do_mercado_pago_reconsulta_o_status_na_api(): void
    {
        $gw = Mockery::mock(GatewayPix::class);
        $gw->shouldReceive('nome')->andReturn('mercadopago');
        $gw->shouldReceive('consultarStatus')->with('ORD01')->once()->andReturn(GatewayPix::APROVADO);
        $this->app->instance(GatewayPix::class, $gw);

        $p = Pagamento::create(['pedido_codigo' => (string) Str::uuid(), 'cliente_id' => 1, 'valor_centavos' => 100,
                                'gateway' => 'mercadopago', 'gateway_id' => 'ORD01', 'pix_copia_e_cola' => 'x']);

        // o corpo diz "recusado", mas quem manda é o status consultado na API
        $this->postJson('/api/webhooks/pix', ['type' => 'order', 'data' => ['id' => 'ORD01'], 'status' => 'recusado'])->assertOk();
        $this->postJson('/api/webhooks/pix', ['type' => 'order', 'data' => ['id' => 'ORD-DE-OUTRO-SISTEMA']])->assertOk();

        $this->assertSame('aprovado', $p->fresh()->status);
    }

    // ------------------------------------------------------------ agendador

    #[Test]
    public function sincronizacao_confirma_pendentes_no_provedor(): void
    {
        $gw = Mockery::mock(GatewayPix::class);
        $gw->shouldReceive('nome')->andReturn('mercadopago');
        $gw->shouldReceive('consultarStatus')->with('ORD-PAGO')->andReturn(GatewayPix::APROVADO);
        $gw->shouldReceive('consultarStatus')->with('ORD-AGUARDANDO')->andReturn(GatewayPix::PENDENTE);
        $gw->shouldReceive('consultarStatus')->with('ORD-VELHO')->never();
        $this->app->instance(GatewayPix::class, $gw);

        $novo = fn ($gid, $dias = 0) => Pagamento::forceCreate(['pedido_codigo' => (string) Str::uuid(), 'cliente_id' => 1, 'valor_centavos' => 100,
            'gateway' => 'mercadopago', 'gateway_id' => $gid, 'pix_copia_e_cola' => 'x', 'status' => 'pendente',
            'created_at' => now()->subDays($dias), 'updated_at' => now()]);
        $pago = $novo('ORD-PAGO');
        $aguardando = $novo('ORD-AGUARDANDO');
        $velho = $novo('ORD-VELHO', 3);   // mais de 24h: não consulta mais

        $this->artisan('pagamentos:sincronizar')->assertSuccessful();

        $this->assertSame('aprovado', $pago->fresh()->status);
        $this->assertSame('pendente', $aguardando->fresh()->status);
        $this->assertSame('pendente', $velho->fresh()->status);
        $this->assertSame(['pagamento.aprovado'], array_column($this->publicados, 0));
    }

    #[Test]
    public function sincronizacao_nao_faz_nada_no_modo_fake(): void
    {
        $this->assertInstanceOf(FakeGateway::class, app(GatewayPix::class));
        $p = $this->pagamento();

        $this->artisan('pagamentos:sincronizar')->assertSuccessful();

        $this->assertSame('pendente', $p->fresh()->status);
    }
}
