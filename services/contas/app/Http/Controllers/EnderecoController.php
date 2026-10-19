<?php

namespace App\Http\Controllers;

use App\Auth\Usuario as UsuarioToken;
use App\Models\Endereco;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Endereços do usuário logado: /api/me/enderecos */
class EnderecoController extends Controller
{
    private const REGRAS = [
        'apelido'     => 'nullable|string|max:40',
        'cep'         => 'required|digits:8',
        'logradouro'  => 'required|string|max:150',
        'numero'      => 'required|string|max:20',
        'complemento' => 'nullable|string|max:100',
        'bairro'      => 'required|string|max:100',
        'cidade'      => 'required|string|max:100',
        'uf'          => 'required|alpha|size:2',
        'referencia'  => 'nullable|string|max:150',
        'principal'   => 'boolean',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->usuario($request)->enderecos()->orderByDesc('principal')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $dados = $request->validate(self::REGRAS);

        $endereco = DB::transaction(function () use ($usuario, $dados) {
            // O primeiro endereço vira principal automaticamente
            $principal = ($dados['principal'] ?? false) || ! $usuario->enderecos()->exists();
            if ($principal) {
                $usuario->enderecos()->update(['principal' => false]);
            }

            return $usuario->enderecos()->create([...$dados, 'uf' => strtoupper($dados['uf']), 'principal' => $principal]);
        });

        return response()->json(['data' => $endereco], 201);
    }

    /** Também usado pelo serviço de pedidos (com o token do cliente) para montar o snapshot. */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->endereco($request, $id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $endereco = $this->endereco($request, $id);
        $regras = array_map(fn ($r) => 'sometimes|' . $r, self::REGRAS);
        $dados = $request->validate($regras);

        DB::transaction(function () use ($endereco, $dados) {
            if (! empty($dados['principal'])) {
                Endereco::where('usuario_id', $endereco->usuario_id)->update(['principal' => false]);
            }
            if (isset($dados['uf'])) {
                $dados['uf'] = strtoupper($dados['uf']);
            }
            $endereco->update($dados);
        });

        return response()->json(['data' => $endereco->refresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->endereco($request, $id)->delete();

        return response()->json(null, 204);
    }

    private function usuario(Request $request): Usuario
    {
        return Usuario::findOrFail(UsuarioToken::atual($request)->id);
    }

    private function endereco(Request $request, int $id): Endereco
    {
        // Só encontra endereços do próprio usuário (404 para o de outra pessoa)
        return Endereco::where('usuario_id', UsuarioToken::atual($request)->id)->findOrFail($id);
    }
}
