<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemPedido extends Model
{
    protected $table = 'itens_pedido';
    public $timestamps = false;

    protected $fillable = [
        'produto_id', 'nome_produto', 'preco_unitario_centavos', 'quantidade', 'observacao',
    ];
}
