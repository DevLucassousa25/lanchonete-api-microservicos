<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProdutoController extends Controller
{
    /**
     * GET /api/produtos (público)
     * Filtros: ?categoria_id=1&busca=x&disponivel=1&per_page=20
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'categoria_id' => 'integer',
            'busca'        => 'string|max:80',
            'disponivel'   => 'boolean',
            'per_page'     => 'integer|min:1|max:100',
        ]);

        $produtos = Produto::with(['precoAtual', 'categoria'])
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->integer('categoria_id')))
            ->when($request->filled('busca'), fn ($q) => $q->whereRaw('LOWER(nome) LIKE ?', ['%' . mb_strtolower($request->query('busca')) . '%']))
            ->when($request->has('disponivel'), fn ($q) => $q->where('disponivel', $request->boolean('disponivel')))
            ->orderBy('nome')
            ->paginate($request->integer('per_page', 20));

        return ProdutoResource::collection($produtos);
    }

    /** GET /api/produtos/{id} (público) */
    public function show(Produto $produto): ProdutoResource
    {
        return new ProdutoResource($produto->load(['precoAtual', 'proximoPreco', 'categoria']));
    }

    /** POST /api/produtos (admin) — cria o produto já com o preço inicial */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'categoria_id'   => 'required|integer|exists:categorias,id,deleted_at,NULL',
            'nome'           => 'required|string|max:120',
            'descricao'      => 'nullable|string|max:500',
            'imagem_url'     => 'nullable|url|max:500',
            'disponivel'     => 'boolean',
            'preco_centavos' => 'required|integer|min:1|max:10000000',
        ]);

        $produto = DB::transaction(function () use ($dados) {
            $produto = Produto::create(collect($dados)->except('preco_centavos')->all());
            $produto->precos()->create([
                'valor_centavos'  => $dados['preco_centavos'],
                'inicio_vigencia' => now(),
                'motivo'          => 'Preço inicial',
            ]);

            return $produto;
        });

        Cache::forget('cardapio');

        // refresh: traz os valores padrão do banco (ex.: disponivel = true)
        return (new ProdutoResource($produto->refresh()->load(['precoAtual', 'categoria'])))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/produtos/{id} (admin) — dados cadastrais.
     * Preço NÃO muda aqui: use POST /api/produtos/{id}/precos (mantém histórico).
     */
    public function update(Request $request, Produto $produto): ProdutoResource
    {
        $dados = $request->validate([
            'categoria_id' => 'sometimes|required|integer|exists:categorias,id,deleted_at,NULL',
            'nome'         => 'sometimes|required|string|max:120',
            'descricao'    => 'sometimes|nullable|string|max:500',
            'imagem_url'   => 'sometimes|nullable|url|max:500',
            'disponivel'   => 'sometimes|boolean',
        ]);

        $produto->update($dados);
        Cache::forget('cardapio');

        return new ProdutoResource($produto->load(['precoAtual', 'categoria']));
    }

    /** DELETE /api/produtos/{id} (admin) — soft delete: pedidos antigos continuam íntegros */
    public function destroy(Produto $produto): JsonResponse
    {
        $produto->delete();
        Cache::forget('cardapio');

        return response()->json(null, 204);
    }
}
