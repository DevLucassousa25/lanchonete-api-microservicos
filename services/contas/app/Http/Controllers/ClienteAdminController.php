<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta de clientes — só admin. */
class ClienteAdminController extends Controller
{
    /** GET /api/clientes?busca=maria&per_page=20 */
    public function index(Request $request): JsonResponse
    {
        $busca = mb_strtolower((string) $request->query('busca'));

        $clientes = Usuario::where('papel', 'cliente')
            ->when($busca, fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(nome) LIKE ?', ["%{$busca}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$busca}%"])
                ->orWhere('telefone', 'like', "%{$busca}%")))
            ->orderBy('nome')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json($clientes);
    }

    /** GET /api/clientes/{id} */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => Usuario::where('papel', 'cliente')->with('enderecos')->findOrFail($id)]);
    }
}
