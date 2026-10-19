<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Garante que bancos criados na versão antiga (preço como coluna do produto)
 * não perdem dados ao migrar para a tabela de preços.
 */
class MigracaoDePrecosTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migra_precos_antigos_para_a_tabela_de_precos(): void
    {
        // volta o banco para a versão antiga (só a primeira migration)
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('produtos', 'preco_centavos'));

        $catId = DB::table('categorias')->insertGetId(['nome' => 'Lanches', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('produtos')->insert([
            ['categoria_id' => $catId, 'nome' => 'X-Burguer', 'preco_centavos' => 1800, 'created_at' => now(), 'updated_at' => now()],
            ['categoria_id' => $catId, 'nome' => 'X-Tudo', 'preco_centavos' => 2800, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertFalse(Schema::hasColumn('produtos', 'preco_centavos'));
        $this->assertSame([1800, 2800], DB::table('precos')->orderBy('valor_centavos')->pluck('valor_centavos')->map(fn ($v) => (int) $v)->all());
        $this->getJson('/api/cardapio')->assertJsonPath('data.0.produtos.1.preco_centavos', 2800);
    }
}
