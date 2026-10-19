<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produtos removidos continuam no banco (soft delete) para preservar histórico de preços.
 * Por isso a categoria também é removida de forma lógica — senão a foreign key impediria.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('categorias', fn (Blueprint $t) => $t->softDeletes());
    }

    public function down(): void
    {
        Schema::table('categorias', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
