<?php

namespace App\Http\Controllers;

use App\Models\Preco;
use App\Models\Produto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Tabela de preços de um produto: /api/produtos/{id}/precos (admin) */
class PrecoController extends Controller
{
    /** GET — histórico completo, do mais novo para o mais antigo, marcando o vigente */
    public function index(Produto $produto): JsonResponse
    {
        $vigenteId = $produto->precoAtual()->value('precos.id');

        $precos = $produto->precos()->get()->map(fn (Preco $p) => [
            'id'              => $p->id,
            'valor_centavos'  => $p->valor_centavos,
            'inicio_vigencia' => $p->inicio_vigencia,
            'motivo'          => $p->motivo,
            'situacao'        => match (true) {
                $p->id === $vigenteId          => 'vigente',
                $p->inicio_vigencia->isFuture() => 'agendado',
                default                         => 'historico',
            },
            'criado_em' => $p->created_at,
        ]);

        return response()->json(['data' => $precos]);
    }

    /**
     * POST — novo preço. Sem "inicio_vigencia" vale a partir de agora;
     * com data futura, fica agendado e entra em vigor sozinho.
     */
    public function store(Request $request, Produto $produto): JsonResponse
    {
        $dados = $request->validate([
            'valor_centavos'  => 'required|integer|min:1|max:10000000',
            'inicio_vigencia' => 'nullable|date|after_or_equal:now',
            'motivo'          => 'nullable|string|max:120',
        ]);

        $preco = $produto->precos()->create([
            ...$dados,
            'inicio_vigencia' => $dados['inicio_vigencia'] ?? now(),
        ]);
        Cache::forget('cardapio');

        return response()->json(['data' => $preco], 201);
    }

    /** DELETE — só preços AGENDADOS podem ser cancelados (histórico é imutável) */
    public function destroy(Produto $produto, Preco $preco): JsonResponse
    {
        abort_if($preco->produto_id !== $produto->id, 404);

        if (! $preco->inicio_vigencia->isFuture()) {
            return response()->json(['message' => 'Só é possível cancelar preços agendados (futuros).'], 409);
        }

        $preco->delete();

        return response()->json(null, 204);
    }
}
