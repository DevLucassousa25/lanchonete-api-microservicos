<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Rotas públicas de leitura + cotação usada internamente pelo serviço de pedidos. */
class CardapioController extends Controller
{
    /** GET /api/cardapio — categorias com produtos vendáveis e preço vigente (cache de 60s) */
    public function index(Request $request): JsonResponse
    {
        $cardapio = Cache::remember('cardapio', 60, fn () =>
            Categoria::orderBy('ordem')->orderBy('nome')
                ->with(['produtos' => fn ($q) => $q->vendaveis()->with('precoAtual')->orderBy('nome')])
                ->get()
                ->filter(fn ($c) => $c->produtos->isNotEmpty())
                ->map(fn ($c) => [
                    'id'       => $c->id,
                    'nome'     => $c->nome,
                    'produtos' => ProdutoResource::collection($c->produtos)->resolve($request),
                ])
                ->values()
                ->all()
        );

        return response()->json(['data' => $cardapio]);
    }

    /**
     * POST /api/produtos/cotacao — usado pelo serviço de Pedidos.
     * Recebe IDs e devolve nome e preço VIGENTES. Pedidos nunca confia em preço vindo do cliente.
     */
    public function cotacao(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];

        $produtos = Produto::whereIn('id', $ids)
            ->vendaveis()
            ->with('precoAtual')
            ->get()
            ->mapWithKeys(fn ($p) => [$p->id => [
                'id'             => $p->id,
                'nome'           => $p->nome,
                'preco_centavos' => $p->precoAtual->valor_centavos,
            ]]);

        return response()->json(['data' => $produtos]);
    }
}
