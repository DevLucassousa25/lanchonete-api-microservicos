<?php

namespace Tests\Feature;

use App\Messaging\EventBus;
use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Worker de pedidos: reage aos eventos do serviço de pagamentos.
 * O RabbitMQ é substituído por um mock que entrega as mensagens diretamente aos handlers.
 */
class SagaPagamentoTest extends TestCase
{
    use RefreshDatabase;

    /** Roda "php artisan eventos:consumir" entregando as mensagens informadas. */
    private function consumir(array $mensagens): void
    {
        $bus = Mockery::mock(EventBus::class);
        $bus->shouldReceive('consumir')->once()->andReturnUsing(function (string $fila, array $handlers) use ($mensagens) {
            $this->assertSame('pedidos.fila', $fila);
            foreach ($mensagens as [$evento, $dados, $id]) {
                $handlers[$evento]($dados, ['id' => $id, 'evento' => $evento]);
            }
        });
        $this->app->instance(EventBus::class, $bus);

        $this->artisan('eventos:consumir')->assertSuccessful();
    }

    private function pedido(string $status = 'aguardando_pagamento'): Pedido
    {
        return Pedido::create([
            'codigo' => (string) Str::uuid(), 'cliente_id' => 1, 'cliente_nome' => 'Maria', 'cliente_telefone' => '85999990000',
            'tipo_entrega' => 'retirada', 'total_centavos' => 1800, 'status' => $status,
        ]);
    }

    #[Test]
    public function pagamento_aprovado_marca_pedido_como_pago(): void
    {
        $p = $this->pedido();

        $this->consumir([['pagamento.aprovado', ['pedido_codigo' => $p->codigo], (string) Str::uuid()]]);

        $this->assertSame('pago', $p->fresh()->status);
    }

    #[Test]
    public function pagamento_recusado_cancela_o_pedido(): void
    {
        $p = $this->pedido();

        $this->consumir([['pagamento.recusado', ['pedido_codigo' => $p->codigo], (string) Str::uuid()]]);

        $this->assertSame('cancelado', $p->fresh()->status);
    }

    #[Test]
    public function mensagem_repetida_e_processada_uma_vez_so(): void
    {
        $p = $this->pedido();
        $id = (string) Str::uuid();

        // RabbitMQ entrega a mesma mensagem duas vezes; no meio, a cozinha já avançou o pedido
        $this->consumir([['pagamento.aprovado', ['pedido_codigo' => $p->codigo], $id]]);
        $p->update(['status' => 'em_preparo']);
        $this->consumir([['pagamento.aprovado', ['pedido_codigo' => $p->codigo], $id]]);

        $this->assertSame('em_preparo', $p->fresh()->status);
        $this->assertDatabaseCount('eventos_processados', 1);
    }

    #[Test]
    public function evento_atrasado_nao_volta_pedido_que_ja_andou(): void
    {
        $p = $this->pedido('cancelado');

        $this->consumir([['pagamento.aprovado', ['pedido_codigo' => $p->codigo], (string) Str::uuid()]]);

        $this->assertSame('cancelado', $p->fresh()->status);
    }
}
