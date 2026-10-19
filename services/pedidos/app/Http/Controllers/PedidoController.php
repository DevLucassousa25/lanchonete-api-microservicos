<?php

namespace App\Http\Controllers;

use App\Auth\Usuario;
use App\Clients\CatalogoClient;
use App\Clients\ContasClient;
use App\Messaging\EventBus;
use App\Models\Pedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    /** POST /api/pedidos — cliente logado faz um pedido */
    public function store(Request $request, CatalogoClient $catalogo, ContasClient $contas, EventBus $bus): JsonResponse
    {
        $usuario = Usuario::atual($request);

        $dados = $request->validate([
            'tipo_entrega'       => 'required|in:retirada,delivery',
            'endereco_id'        => 'required_if:tipo_entrega,delivery|nullable|integer',
            'observacao'         => 'nullable|string|max:300',
            'itens'              => 'required|array|min:1|max:50',
            'itens.*.produto_id' => 'required|integer|distinct',
            'itens.*.quantidade' => 'required|integer|min:1|max:20',
            'itens.*.observacao' => 'nullable|string|max:200',
        ]);

        // 1) Endereço vem do serviço de Contas (snapshot: se o cliente mudar depois, o pedido não muda)
        $endereco = null;
        if ($dados['tipo_entrega'] === 'delivery') {
            $endereco = $contas->endereco($dados['endereco_id'], $usuario->token)['texto'];
        }

        // 2) Preço vem do Catálogo, nunca do cliente
        $produtos = $catalogo->cotar(array_column($dados['itens'], 'produto_id'));

        $itens = collect($dados['itens'])->map(function ($item) use ($produtos) {
            $p = $produtos[$item['produto_id']] ?? throw ValidationException::withMessages([
                'itens' => "Produto {$item['produto_id']} indisponível.",
            ]);

            return [
                'produto_id'              => $p['id'],
                'nome_produto'            => $p['nome'],
                'preco_unitario_centavos' => $p['preco_centavos'],
                'quantidade'              => $item['quantidade'],
                'observacao'              => $item['observacao'] ?? null,
            ];
        });

        // 3) Grava no banco DESTE serviço
        $pedido = DB::transaction(function () use ($dados, $itens, $usuario, $endereco) {
            $pedido = Pedido::create([
                'codigo'           => (string) Str::uuid(),
                'cliente_id'       => $usuario->id,
                'cliente_nome'     => $usuario->nome,
                'cliente_telefone' => $usuario->telefone ?? '',
                'tipo_entrega'     => $dados['tipo_entrega'],
                'endereco'         => $endereco,
                'endereco_id'      => $dados['endereco_id'] ?? null,
                'observacao'       => $dados['observacao'] ?? null,
                'total_centavos'   => $itens->sum(fn ($i) => $i['preco_unitario_centavos'] * $i['quantidade']),
            ]);
            $pedido->itens()->createMany($itens);

            return $pedido;
        });

        // 4) Avisa o mundo. Pagamentos vai gerar o Pix.
        //    (Em produção: padrão Outbox para não perder o evento se o RabbitMQ cair aqui.)
        $bus->publicar('pedido.criado', [
            'codigo'         => $pedido->codigo,
            'cliente_id'     => $pedido->cliente_id,
            'total_centavos' => $pedido->total_centavos,
            'cliente_nome'   => $pedido->cliente_nome,
        ]);

        return response()->json(['data' => $pedido->refresh()->load('itens')], 201);
    }

    /** GET /api/pedidos — pedidos do cliente logado (admin vê todos; filtro ?status=) */
    public function index(Request $request): JsonResponse
    {
        $usuario = Usuario::atual($request);
        $request->validate(['status' => 'string', 'per_page' => 'integer|min:1|max:100']);

        $pedidos = Pedido::with('itens')
            ->when(! $usuario->isAdmin(), fn ($q) => $q->where('cliente_id', $usuario->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($pedidos);
    }

    /** GET /api/pedidos/{codigo} — dono ou admin */
    public function show(Request $request, Pedido $pedido): JsonResponse
    {
        Usuario::atual($request)->autorizarDono($pedido->cliente_id);

        return response()->json(['data' => $pedido->load('itens')]);
    }

    /** GET /api/cozinha — fila de pedidos PAGOS (admin) */
    public function cozinha(): JsonResponse
    {
        $pedidos = Pedido::with('itens')
            ->whereIn('status', ['pago', 'em_preparo', 'pronto'])
            ->oldest()
            ->get()
            ->groupBy('status');

        return response()->json(['data' => [
            'pago'       => $pedidos['pago'] ?? [],
            'em_preparo' => $pedidos['em_preparo'] ?? [],
            'pronto'     => $pedidos['pronto'] ?? [],
        ]]);
    }

    /** POST /api/pedidos/{codigo}/avancar — pago → em_preparo → pronto → entregue (admin) */
    public function avancar(Pedido $pedido, EventBus $bus): JsonResponse
    {
        $proximo = Pedido::STATUS_FLUXO[$pedido->status] ?? null;
        if (! $proximo) {
            return response()->json(['message' => "Pedido com status '{$pedido->status}' não pode avançar."], 409);
        }

        $pedido->update(['status' => $proximo]);
        $bus->publicar("pedido.{$proximo}", ['codigo' => $pedido->codigo, 'cliente_id' => $pedido->cliente_id]);

        return response()->json(['data' => $pedido]);
    }
}
