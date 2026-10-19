<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Categoria::exists()) {
            return; // já populado
        }

        $cardapio = [
            'Lanches' => [
                ['X-Burguer', 'Pão, carne 120g, queijo', 1800],
                ['X-Tudo', 'Carne, ovo, bacon, presunto, queijo, salada', 2800],
                ['Misto Quente', 'Presunto e queijo na chapa', 900],
            ],
            'Bebidas' => [
                ['Suco de Cajá', '500ml', 800],
                ['Refrigerante lata', '350ml', 600],
            ],
        ];

        $ordem = 1;
        foreach ($cardapio as $nomeCategoria => $produtos) {
            $categoria = Categoria::create(['nome' => $nomeCategoria, 'ordem' => $ordem++]);

            foreach ($produtos as [$nome, $descricao, $preco]) {
                $produto = $categoria->produtos()->create(['nome' => $nome, 'descricao' => $descricao]);
                $produto->precos()->create([
                    'valor_centavos'  => $preco,
                    'inicio_vigencia' => now()->subMinute(),
                    'motivo'          => 'Preço inicial',
                ]);
            }
        }
    }
}
