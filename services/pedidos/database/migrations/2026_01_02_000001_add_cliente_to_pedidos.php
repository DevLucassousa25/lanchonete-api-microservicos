<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $t) {
            // id do usuário no serviço "contas" (sem foreign key: é outro banco)
            $t->unsignedBigInteger('cliente_id')->nullable()->after('codigo')->index();
            $t->unsignedBigInteger('endereco_id')->nullable()->after('endereco');
            $t->string('observacao', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', fn (Blueprint $t) => $t->dropColumn(['cliente_id', 'endereco_id', 'observacao']));
    }
};
