<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TabelaDePrecosTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));

        $this->produto = Categoria::create(['nome' => 'Lanches'])->produtos()->create(['nome' => 'X-Tudo']);
        $this->produto->precos()->create(['valor_centavos' => 2800, 'inicio_vigencia' => now()->subMonth(), 'motivo' => 'Preço inicial']);
    }

    private function precoAtual(): ?int
    {
        Cache::flush();

        return $this->getJson("/api/produtos/{$this->produto->id}")->json('data.preco_centavos');
    }

    #[Test]
    public function novo_preco_sem_data_vale_imediatamente(): void
    {
        $this->postJson("/api/produtos/{$this->produto->id}/precos", ['valor_centavos' => 3000, 'motivo' => 'Reajuste'], $this->comoAdmin())
            ->assertCreated();

        $this->assertSame(3000, $this->precoAtual());
    }

    #[Test]
    public function preco_agendado_so_entra_em_vigor_na_data(): void
    {
        $this->postJson("/api/produtos/{$this->produto->id}/precos", [
            'valor_centavos' => 3500, 'inicio_vigencia' => '2026-11-01 00:00:00',
        ], $this->comoAdmin())->assertCreated();

        // ainda vale o preço antigo, e o próximo aparece como agendado
        $this->getJson("/api/produtos/{$this->produto->id}")
            ->assertJsonPath('data.preco_centavos', 2800)
            ->assertJsonPath('data.proximo_preco.valor_centavos', 3500);

        // chegou a data: passa a valer sozinho, sem ninguém fazer nada
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0, 1));
        $this->assertSame(3500, $this->precoAtual());

        // e a cotação usada pelos pedidos também muda
        $this->assertSame(3500, $this->postJson('/api/produtos/cotacao', ['ids' => [$this->produto->id]])
            ->json("data.{$this->produto->id}.preco_centavos"));
    }

    #[Test]
    public function historico_marca_vigente_agendado_e_historico(): void
    {
        $this->postJson("/api/produtos/{$this->produto->id}/precos", ['valor_centavos' => 3000], $this->comoAdmin());
        $this->postJson("/api/produtos/{$this->produto->id}/precos", ['valor_centavos' => 3500, 'inicio_vigencia' => '2026-12-01'], $this->comoAdmin());

        $historico = $this->getJson("/api/produtos/{$this->produto->id}/precos", $this->comoAdmin())->assertOk()->json('data');

        $this->assertSame(['agendado', 'vigente', 'historico'], array_column($historico, 'situacao'));
        $this->assertSame([3500, 3000, 2800], array_column($historico, 'valor_centavos'));
    }

    #[Test]
    public function nao_aceita_data_no_passado_nem_valor_invalido(): void
    {
        $this->postJson("/api/produtos/{$this->produto->id}/precos", ['valor_centavos' => 0, 'inicio_vigencia' => '2020-01-01'], $this->comoAdmin())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['valor_centavos', 'inicio_vigencia']);
    }

    #[Test]
    public function so_cancela_preco_agendado(): void
    {
        $vigente = $this->produto->precos()->first();
        $agendado = $this->postJson("/api/produtos/{$this->produto->id}/precos", [
            'valor_centavos' => 3500, 'inicio_vigencia' => '2026-12-01',
        ], $this->comoAdmin())->json('data.id');

        $this->deleteJson("/api/produtos/{$this->produto->id}/precos/{$vigente->id}", [], $this->comoAdmin())->assertStatus(409);
        $this->deleteJson("/api/produtos/{$this->produto->id}/precos/{$agendado}", [], $this->comoAdmin())->assertNoContent();

        $this->getJson("/api/produtos/{$this->produto->id}")->assertJsonPath('data.proximo_preco', null);
    }

    #[Test]
    public function nao_mexe_em_preco_de_outro_produto(): void
    {
        $outro = Categoria::first()->produtos()->create(['nome' => 'Outro']);
        $agendado = $this->postJson("/api/produtos/{$this->produto->id}/precos", [
            'valor_centavos' => 3500, 'inicio_vigencia' => '2026-12-01',
        ], $this->comoAdmin())->json('data.id');

        $this->deleteJson("/api/produtos/{$outro->id}/precos/{$agendado}", [], $this->comoAdmin())->assertNotFound();
    }

    #[Test]
    public function tabela_de_precos_e_so_para_admin(): void
    {
        $this->getJson("/api/produtos/{$this->produto->id}/precos", $this->comToken())->assertForbidden();
        $this->postJson("/api/produtos/{$this->produto->id}/precos", ['valor_centavos' => 1], $this->comToken())->assertForbidden();
    }
}
