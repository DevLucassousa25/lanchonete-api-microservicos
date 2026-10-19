<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $t) {
            $t->id();
            $t->string('nome');
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->timestamps();
        });

        Schema::create('produtos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('categoria_id')->constrained();
            $t->string('nome');
            $t->text('descricao')->nullable();
            $t->unsignedInteger('preco_centavos'); // dinheiro sempre em inteiro
            $t->string('imagem_url')->nullable();
            $t->boolean('disponivel')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos');
        Schema::dropIfExists('categorias');
    }
};
