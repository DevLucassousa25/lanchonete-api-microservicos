<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pagamento extends Model
{
    protected $fillable = [
        'pedido_codigo', 'cliente_id', 'valor_centavos', 'metodo', 'gateway', 'pix_copia_e_cola',
        'qr_code_base64', 'ticket_url', 'gateway_id', 'status', 'erro',
    ];

    protected $hidden = ['erro'];
}
