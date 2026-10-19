<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use SoftDeletes;

    protected $fillable = ['nome', 'ordem'];

    protected $hidden = ['deleted_at']; // detalhe interno da exclusão lógica

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class);
    }
}
