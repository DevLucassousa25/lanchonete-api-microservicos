<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Usuario extends Model
{
    protected $table = 'usuarios';

    protected $fillable = ['nome', 'email', 'senha', 'papel', 'telefone', 'cpf', 'data_nascimento'];

    protected $hidden = ['senha'];

    protected $casts = [
        'senha'           => 'hashed',   // Hash::make automático ao atribuir
        'data_nascimento' => 'date:Y-m-d',
    ];

    public function enderecos(): HasMany
    {
        return $this->hasMany(Endereco::class);
    }

    public function isAdmin(): bool
    {
        return $this->papel === 'admin';
    }
}
