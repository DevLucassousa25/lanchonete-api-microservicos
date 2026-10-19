<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preço deixa de ser uma coluna do produto e vira uma tabela com histórico:
 * cada linha vale a partir de "inicio_vigencia". Preço atual = a linha mais recente já vigente.
 * Permite agendar reajustes e consultar o histórico.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('precos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('produto_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('valor_centavos');
            $t->timestamp('inicio_vigencia')->useCurrent();
            $t->string('motivo')->nullable();          // "Reajuste anual", "Promoção"...
            $t->timestamps();
            $t->index(['produto_id', 'inicio_vigencia']);
        });

        // Leva os preços existentes para a tabela nova (bancos já populados não perdem nada)
        if (Schema::hasColumn('produtos', 'preco_centavos')) {
            DB::table('produtos')->orderBy('id')->each(function ($p) {
                DB::table('precos')->insert([
                    'produto_id'      => $p->id,
                    'valor_centavos'  => $p->preco_centavos,
                    'inicio_vigencia' => $p->created_at ?? now(),
                    'motivo'          => 'Preço inicial',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            });

            Schema::table('produtos', fn (Blueprint $t) => $t->dropColumn('preco_centavos'));
        }

        Schema::table('produtos', fn (Blueprint $t) => $t->softDeletes());
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $t) {
            $t->dropSoftDeletes();
            $t->unsignedInteger('preco_centavos')->default(0);
        });
        Schema::dropIfExists('precos');
    }
};
