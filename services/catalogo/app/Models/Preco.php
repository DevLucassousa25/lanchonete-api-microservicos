<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Preco extends Model
{
    protected $fillable = ['valor_centavos', 'inicio_vigencia', 'motivo'];

    protected $casts = ['inicio_vigencia' => 'datetime'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
