<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Cria o admin inicial (credenciais do .env). Não sobrescreve se já existir. */
    public function run(): void
    {
        Usuario::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@lanchonete.dev')],
            [
                'nome'     => 'Administrador',
                'senha'    => env('ADMIN_PASSWORD', 'admin12345'),
                'papel'    => 'admin',
                'telefone' => '00000000000',
            ],
        );
    }
}
