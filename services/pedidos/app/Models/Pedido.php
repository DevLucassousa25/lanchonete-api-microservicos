<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    public const STATUS_FLUXO = [
        'pago'       => 'em_preparo',
        'em_preparo' => 'pronto',
        'pronto'     => 'entregue',
    ];

    protected $fillable = [
        'codigo', 'cliente_id', 'cliente_nome', 'cliente_telefone', 'tipo_entrega',
        'endereco', 'endereco_id', 'observacao', 'total_centavos', 'status',
    ];

    protected $hidden = ['id'];   // o identificador público é o "codigo" (UUID)

    public function itens(): HasMany
    {
        return $this->hasMany(ItemPedido::class);
    }

    public function getRouteKeyName(): string
    {
        return 'codigo';
    }
}
