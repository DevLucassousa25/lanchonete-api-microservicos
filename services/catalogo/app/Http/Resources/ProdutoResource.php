<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Formato público de um produto (o mesmo em todas as rotas). */
class ProdutoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'categoria_id'   => $this->categoria_id,
            'nome'           => $this->nome,
            'descricao'      => $this->descricao,
            'imagem_url'     => $this->imagem_url,
            'disponivel'     => $this->disponivel,
            'preco_centavos' => $this->precoAtual?->valor_centavos,
            'preco'          => $this->precoAtual ? number_format($this->precoAtual->valor_centavos / 100, 2, ',', '.') : null,
            'proximo_preco'  => $this->whenLoaded('proximoPreco', fn () => $this->proximoPreco ? [
                'valor_centavos'  => $this->proximoPreco->valor_centavos,
                'inicio_vigencia' => $this->proximoPreco->inicio_vigencia,
            ] : null),
            'categoria'      => $this->whenLoaded('categoria', fn () => $this->categoria ? [
                'id' => $this->categoria->id, 'nome' => $this->categoria->nome,
            ] : null),
            'criado_em'      => $this->created_at,
            'atualizado_em'  => $this->updated_at,
        ];
    }
}
