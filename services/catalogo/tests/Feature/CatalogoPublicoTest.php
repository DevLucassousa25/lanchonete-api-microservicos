<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Produto;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogoPublicoTest extends TestCase
{
    use RefreshDatabase;

    private function produto(Categoria $cat, string $nome, ?int $preco, bool $disponivel = true): Produto
    {
        $p = $cat->produtos()->create(['nome' => $nome, 'disponivel' => $disponivel]);
        if ($preco !== null) {
            $p->precos()->create(['valor_centavos' => $preco, 'inicio_vigencia' => now()->subDay()]);
        }

        return $p;
    }

    #[Test]
    public function cardapio_e_publico_e_traz_preco_vigente(): void
    {
        $this->seed(DatabaseSeeder::class);

        $resp = $this->getJson('/api/cardapio')->assertOk();

        $this->assertSame(['Lanches', 'Bebidas'], array_column($resp->json('data'), 'nome'));
        $xTudo = collect($resp->json('data.0.produtos'))->firstWhere('nome', 'X-Tudo');
        $this->assertSame(2800, $xTudo['preco_centavos']);
        $this->assertSame('28,00', $xTudo['preco']);
    }

    #[Test]
    public function cardapio_esconde_indisponiveis_sem_preco_e_categorias_vazias(): void
    {
        $lanches = Categoria::create(['nome' => 'Lanches']);
        $vazia = Categoria::create(['nome' => 'Vazia']);
        $this->produto($lanches, 'Visível', 1000);
        $this->produto($lanches, 'Indisponível', 1000, disponivel: false);
        $this->produto($lanches, 'Sem preço', null);
        $this->produto($vazia, 'Também indisponível', 500, disponivel: false);

        $data = $this->getJson('/api/cardapio')->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(['Visível'], array_column($data[0]['produtos'], 'nome'));
    }

    #[Test]
    public function categorias_nao_expoem_campos_internos(): void
    {
        $cat = Categoria::create(['nome' => 'Lanches', 'ordem' => 1]);
        $this->produto($cat, 'X-Tudo', 2800);

        $this->getJson('/api/categorias')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Lanches')
            ->assertJsonMissingPath('data.0.produtos_count')
            ->assertJsonMissingPath('data.0.deleted_at');

        $this->getJson("/api/categorias/{$cat->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.produtos_count')
            ->assertJsonMissingPath('data.deleted_at');
    }

    #[Test]
    public function lista_produtos_com_filtros_e_paginacao(): void
    {
        $lanches = Categoria::create(['nome' => 'Lanches']);
        $bebidas = Categoria::create(['nome' => 'Bebidas']);
        $this->produto($lanches, 'X-Burguer', 1800);
        $this->produto($lanches, 'X-Tudo', 2800);
        $this->produto($bebidas, 'Suco', 800);

        $this->getJson('/api/produtos?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson("/api/produtos?categoria_id={$bebidas->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Suco');
        $this->getJson('/api/produtos?busca=x-t')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'X-Tudo');
    }

    #[Test]
    public function detalhe_do_produto_e_404s(): void
    {
        $p = $this->produto(Categoria::create(['nome' => 'Lanches']), 'X-Tudo', 2800);

        $this->getJson("/api/produtos/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.categoria.nome', 'Lanches')
            ->assertJsonPath('data.proximo_preco', null);

        $this->getJson('/api/produtos/999')->assertNotFound();
        $this->getJson('/api/produtos/abc')->assertNotFound();
    }

    #[Test]
    public function cotacao_interna_so_devolve_produtos_vendaveis(): void
    {
        $cat = Categoria::create(['nome' => 'Lanches']);
        $ok = $this->produto($cat, 'Vendável', 1500);
        $off = $this->produto($cat, 'Indisponível', 1500, disponivel: false);
        $semPreco = $this->produto($cat, 'Sem preço', null);

        $data = $this->postJson('/api/produtos/cotacao', ['ids' => [$ok->id, $off->id, $semPreco->id, 999]])
            ->assertOk()
            ->json('data');

        $this->assertSame([(string) $ok->id], array_map('strval', array_keys($data)));
        $this->assertSame(1500, $data[$ok->id]['preco_centavos']);
    }
}
