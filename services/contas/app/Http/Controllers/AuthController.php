<?php

namespace App\Http\Controllers;

use App\Auth\Usuario as UsuarioToken;
use App\Models\Usuario;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private TokenService $tokens) {}

    /** POST /api/auth/registrar — cadastro de cliente (já devolve o token) */
    public function registrar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome'            => 'required|string|max:120',
            'email'           => 'required|email|max:180|unique:usuarios,email',
            'senha'           => 'required|string|min:8|confirmed',
            'telefone'        => 'required|string|min:10|max:20',
            'cpf'             => ['nullable', 'digits:11', Rule::unique('usuarios', 'cpf')],
            'data_nascimento' => 'nullable|date|before:today',
        ]);

        $usuario = Usuario::create([...$dados, 'papel' => 'cliente']);

        return response()->json($this->tokens->emitir($usuario), 201);
    }

    /** POST /api/auth/login */
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => 'required|email',
            'senha' => 'required|string',
        ]);

        $usuario = Usuario::where('email', $dados['email'])->first();

        // Mesma mensagem para e-mail inexistente e senha errada (não revela quem tem conta)
        if (! $usuario || ! Hash::check($dados['senha'], $usuario->senha)) {
            return response()->json(['message' => 'E-mail ou senha inválidos.'], 401);
        }

        return response()->json($this->tokens->emitir($usuario));
    }

    /** POST /api/auth/refresh — troca o token atual por um novo (o antigo é revogado) */
    public function refresh(Request $request): JsonResponse
    {
        $atual = UsuarioToken::atual($request);
        $usuario = Usuario::findOrFail($atual->id);

        $this->tokens->revogar($atual);

        return response()->json($this->tokens->emitir($usuario));
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revogar(UsuarioToken::atual($request));

        return response()->json(['message' => 'Logout realizado.']);
    }
}
