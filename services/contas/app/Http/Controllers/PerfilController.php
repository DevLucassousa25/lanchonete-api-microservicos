<?php

namespace App\Http\Controllers;

use App\Auth\Usuario as UsuarioToken;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PerfilController extends Controller
{
    /** GET /api/me */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->usuario($request)->load('enderecos')]);
    }

    /** PATCH /api/me — atualiza dados cadastrais (envie só o que mudou) */
    public function update(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

        $dados = $request->validate([
            'nome'            => 'sometimes|required|string|max:120',
            'email'           => ['sometimes', 'required', 'email', 'max:180', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'telefone'        => 'sometimes|required|string|min:10|max:20',
            'cpf'             => ['sometimes', 'nullable', 'digits:11', Rule::unique('usuarios', 'cpf')->ignore($usuario->id)],
            'data_nascimento' => 'sometimes|nullable|date|before:today',
        ]);

        $usuario->update($dados);

        return response()->json([
            'data'    => $usuario,
            'message' => 'Dados atualizados. Nome/telefone no token mudam no próximo login ou refresh.',
        ]);
    }

    /** PUT /api/me/senha */
    public function alterarSenha(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);

        $dados = $request->validate([
            'senha_atual' => 'required|string',
            'nova_senha'  => 'required|string|min:8|confirmed|different:senha_atual',
        ]);

        if (! Hash::check($dados['senha_atual'], $usuario->senha)) {
            return response()->json(['message' => 'Senha atual incorreta.'], 422);
        }

        $usuario->update(['senha' => $dados['nova_senha']]);

        return response()->json(['message' => 'Senha alterada.']);
    }

    private function usuario(Request $request): Usuario
    {
        return Usuario::findOrFail(UsuarioToken::atual($request)->id);
    }
}
