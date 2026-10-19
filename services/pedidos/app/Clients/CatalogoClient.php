<?php

namespace App\Clients;

use Illuminate\Support\Facades\Http;

/** Chamada síncrona ao serviço de Catálogo (precisamos do preço AGORA). */
class CatalogoClient
{
    /** @return array<int, array{id:int, nome:string, preco_centavos:int}> */
    public function cotar(array $produtoIds): array
    {
        return Http::baseUrl(env('CATALOGO_URL'))
            ->timeout(3)
            ->retry(2, 200)           // tenta de novo se o catálogo piscar
            ->acceptJson()
            ->post('/api/produtos/cotacao', ['ids' => array_values(array_unique($produtoIds))])
            ->throw()
            ->json('data');
    }
}
