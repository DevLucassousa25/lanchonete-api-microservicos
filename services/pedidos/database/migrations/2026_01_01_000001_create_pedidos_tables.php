<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $t) {
            $t->id();
            $t->uuid('codigo')->unique();          // id público (não expõe sequência)
            $t->string('cliente_nome');
            $t->string('cliente_telefone');
            $t->string('tipo_entrega');            // retirada | delivery
            $t->string('endereco')->nullable();
            $t->unsignedInteger('total_centavos');
            // aguardando_pagamento → pago → em_preparo → pronto → entregue | cancelado
            $t->string('status')->default('aguardando_pagamento')->index();
            $t->timestamps();
        });

        // Snapshot do produto: nome e preço CONGELADOS no momento da compra.
        // Não há foreign key para "produtos" — essa tabela mora em outro banco.
        Schema::create('itens_pedido', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pedido_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('produto_id');
            $t->string('nome_produto');
            $t->unsignedInteger('preco_unitario_centavos');
            $t->unsignedSmallInteger('quantidade');
            $t->string('observacao')->nullable();
        });

        // Idempotência: RabbitMQ pode entregar a mesma mensagem 2x
        Schema::create('eventos_processados', function (Blueprint $t) {
            $t->uuid('evento_id')->primary();
            $t->timestamp('processado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_processados');
        Schema::dropIfExists('itens_pedido');
        Schema::dropIfExists('pedidos');
    }
};
