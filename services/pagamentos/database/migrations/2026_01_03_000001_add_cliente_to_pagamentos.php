<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->unsignedBigInteger('cliente_id')->nullable()->after('pedido_codigo')->index();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->dropIndex(['status']);
            $t->dropColumn('cliente_id');
        });
    }
};
