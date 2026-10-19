<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id();
            $t->string('nome');
            $t->string('email')->unique();
            $t->string('senha');                       // hash bcrypt
            $t->string('papel')->default('cliente');   // cliente | admin
            $t->string('telefone', 20)->nullable();
            $t->string('cpf', 11)->nullable()->unique();
            $t->date('data_nascimento')->nullable();
            $t->timestamps();
        });

        Schema::create('enderecos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $t->string('apelido', 40)->nullable();     // "Casa", "Trabalho"
            $t->string('cep', 8);
            $t->string('logradouro');
            $t->string('numero', 20);
            $t->string('complemento')->nullable();
            $t->string('bairro');
            $t->string('cidade');
            $t->char('uf', 2);
            $t->string('referencia')->nullable();
            $t->boolean('principal')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enderecos');
        Schema::dropIfExists('usuarios');
    }
};
