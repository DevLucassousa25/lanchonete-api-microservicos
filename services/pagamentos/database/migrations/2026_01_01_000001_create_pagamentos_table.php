<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pagamentos', function (Blueprint $t) {
            $t->id();
            $t->uuid('pedido_codigo')->unique();    // referência "solta" ao outro serviço
            $t->unsignedInteger('valor_centavos');
            $t->string('metodo')->default('pix');
            $t->text('pix_copia_e_cola')->nullable();
            $t->string('gateway_id')->nullable();   // id no Mercado Pago, Asaas, etc.
            $t->string('status')->default('pendente'); // pendente | aprovado | recusado
            $t->timestamps();
        });

        Schema::create('eventos_processados', function (Blueprint $t) {
            $t->uuid('evento_id')->primary();
            $t->timestamp('processado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_processados');
        Schema::dropIfExists('pagamentos');
    }
};
