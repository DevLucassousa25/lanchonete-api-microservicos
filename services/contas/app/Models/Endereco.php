<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Endereco extends Model
{
    protected $fillable = [
        'apelido', 'cep', 'logradouro', 'numero', 'complemento',
        'bairro', 'cidade', 'uf', 'referencia', 'principal',
    ];

    protected $casts = ['principal' => 'boolean'];

    protected $hidden = ['usuario_id'];

    protected $appends = ['texto'];

    /** Endereço em uma linha — é isso que o serviço de pedidos guarda como snapshot. */
    public function getTextoAttribute(): string
    {
        return sprintf('%s, %s%s - %s, %s/%s - CEP %s%s',
            $this->logradouro, $this->numero,
            $this->complemento ? " ({$this->complemento})" : '',
            $this->bairro, $this->cidade, $this->uf,
            substr($this->cep, 0, 5) . '-' . substr($this->cep, 5),
            $this->referencia ? " - Ref.: {$this->referencia}" : '',
        );
    }
}
