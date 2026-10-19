<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogoAdminTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function escrita_exige_admin(): void
    {
        $cat = Categoria::create(['nome' => 'Lanches']);

        $this->postJson('/api/categorias', ['nome' => 'X'])->assertUnauthorized();
        $this->postJson('/api/categorias', ['nome' => 'X'], $this->comToken())->assertForbidden();
        $this->postJson('/api/produtos', ['nome' => 'X'], $this->comToken())->assertForbidden();
        $this->patchJson("/api/categorias/{$cat->id}", ['nome' => 'Y'], $this->comToken())->assertForbidden();
        $this->deleteJson("/api/categorias/{$cat->id}", [], $this->comToken())->assertForbidden();
    }

    #[Test]
    public function admin_gerencia_categorias(): void
    {
        $id = $this->postJson('/api/categorias', ['nome' => 'Sobremesas', 'ordem' => 3], $this->comoAdmin())
            ->assertCreated()
            ->json('data.id');

        $this->postJson('/api/categorias', ['nome' => 'Sobremesas'], $this->comoAdmin())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nome');

        $this->patchJson("/api/categorias/{$id}", ['nome' => 'Doces'], $this->comoAdmin())
            ->assertOk()
            ->assertJsonPath('data.nome', 'Doces');

        $this->deleteJson("/api/categorias/{$id}", [], $this->comoAdmin())->assertNoContent();
        $this->getJson("/api/categorias/{$id}")->assertNotFound();

        // nome de categoria removida pode ser reutilizado
        $this->postJson('/api/categorias', ['nome' => 'Doces'], $this->comoAdmin())->assertCreated();
    }

    #[Test]
    public function nao_remove_categoria_com_produtos_ativos(): void
    {
        $cat = Categoria::create(['nome' => 'Lanches']);
        $p = $cat->produtos()->create(['nome' => 'X-Tudo']);

        $this->deleteJson("/api/categorias/{$cat->id}", [], $this->comoAdmin())->assertStatus(409);

        // depois de remover o produto (soft delete), a categoria pode sair
        $this->deleteJson("/api/produtos/{$p->id}", [], $this->comoAdmin())->assertNoContent();
        $this->deleteJson("/api/categorias/{$cat->id}", [], $this->comoAdmin())->assertNoContent();
    }

    #[Test]
    public function cria_produto_com_preco_inicial(): void
    {
        $cat = Categoria::create(['nome' => 'Lanches']);

        $this->postJson('/api/produtos', ['categoria_id' => $cat->id, 'nome' => 'X-Bacon'], $this->comoAdmin())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preco_centavos');

        $resp = $this->postJson('/api/produtos', [
            'categoria_id' => $cat->id, 'nome' => 'X-Bacon', 'descricao' => 'Com bacon', 'preco_centavos' => 3200,
        ], $this->comoAdmin());

        $resp->assertCreated()
            ->assertJsonPath('data.preco_centavos', 3200)
            ->assertJsonPath('data.preco', '32,00')
            ->assertJsonPath('data.disponivel', true);

        $this->assertDatabaseHas('precos', ['produto_id' => $resp->json('data.id'), 'valor_centavos' => 3200]);
    }

    #[Test]
    public function nao_cria_produto_em_categoria_removida(): void
    {
        $cat = Categoria::create(['nome' => 'Antiga']);
        $cat->delete();

        $this->postJson('/api/produtos', ['categoria_id' => $cat->id, 'nome' => 'X', 'preco_centavos' => 100], $this->comoAdmin())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categoria_id');
    }

    #[Test]
    public function atualizar_produto_nao_mexe_no_preco(): void
    {
        $p = Categoria::create(['nome' => 'Lanches'])->produtos()->create(['nome' => 'X-Tudo']);
        $p->precos()->create(['valor_centavos' => 2800, 'inicio_vigencia' => now()->subDay()]);

        $this->patchJson("/api/produtos/{$p->id}", ['nome' => 'X-Tudo Especial', 'preco_centavos' => 1, 'disponivel' => false], $this->comoAdmin())
            ->assertOk()
            ->assertJsonPath('data.nome', 'X-Tudo Especial')
            ->assertJsonPath('data.disponivel', false)
            ->assertJsonPath('data.preco_centavos', 2800);
    }

    #[Test]
    public function produto_removido_sai_das_listagens_mas_continua_no_banco(): void
    {
        $p = Categoria::create(['nome' => 'Lanches'])->produtos()->create(['nome' => 'X-Tudo']);

        $this->deleteJson("/api/produtos/{$p->id}", [], $this->comoAdmin())->assertNoContent();

        $this->getJson("/api/produtos/{$p->id}")->assertNotFound();
        $this->assertSoftDeleted('produtos', ['id' => $p->id]);
        $this->assertNotNull(Produto::withTrashed()->find($p->id));
    }
}
