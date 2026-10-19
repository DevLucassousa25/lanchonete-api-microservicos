<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    /** GET /api/categorias (público) */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Categoria::orderBy('ordem')->orderBy('nome')->get()]);
    }

    /** GET /api/categorias/{id} (público) */
    public function show(Categoria $categoria): JsonResponse
    {
        return response()->json(['data' => $categoria]);
    }

    /** POST /api/categorias (admin) */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome'  => ['required', 'string', 'max:80', Rule::unique('categorias', 'nome')->whereNull('deleted_at')],
            'ordem' => 'integer|min:0|max:1000',
        ]);

        $categoria = Categoria::create($dados);
        Cache::forget('cardapio');

        return response()->json(['data' => $categoria], 201);
    }

    /** PATCH /api/categorias/{id} (admin) */
    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $dados = $request->validate([
            'nome'  => ['sometimes', 'required', 'string', 'max:80', Rule::unique('categorias', 'nome')->whereNull('deleted_at')->ignore($categoria->id)],
            'ordem' => 'sometimes|integer|min:0|max:1000',
        ]);

        $categoria->update($dados);
        Cache::forget('cardapio');

        return response()->json(['data' => $categoria]);
    }

    /** DELETE /api/categorias/{id} (admin) — só se não tiver produtos ativos (soft delete) */
    public function destroy(Categoria $categoria): JsonResponse
    {
        if ($categoria->produtos()->exists()) {
            return response()->json(['message' => 'A categoria tem produtos. Mova ou remova os produtos antes.'], 409);
        }

        $categoria->delete();
        Cache::forget('cardapio');

        return response()->json(null, 204);
    }
}
