<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Produto extends Model
{
    use SoftDeletes;

    protected $fillable = ['categoria_id', 'nome', 'descricao', 'imagem_url', 'disponivel'];

    protected $casts = ['disponivel' => 'boolean'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /** Histórico completo (inclui preços agendados para o futuro). */
    public function precos(): HasMany
    {
        return $this->hasMany(Preco::class)->orderByDesc('inicio_vigencia')->orderByDesc('id');
    }

    /** Preço vigente agora: o mais recente com inicio_vigencia <= agora. */
    public function precoAtual(): HasOne
    {
        return $this->hasOne(Preco::class)->ofMany(
            ['inicio_vigencia' => 'max', 'id' => 'max'],
            fn ($q) => $q->where('inicio_vigencia', '<=', now()),
        );
    }

    /** Próximo reajuste agendado, se houver. */
    public function proximoPreco(): HasOne
    {
        return $this->hasOne(Preco::class)->ofMany(
            ['inicio_vigencia' => 'min', 'id' => 'max'],
            fn ($q) => $q->where('inicio_vigencia', '>', now()),
        );
    }

    /** Produtos que podem ser vendidos: disponíveis e com preço vigente. */
    public function scopeVendaveis($query)
    {
        return $query->where('disponivel', true)->whereHas('precoAtual');
    }
}
