<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->string('gateway')->default('fake')->after('metodo');   // fake | mercadopago
            $t->longText('qr_code_base64')->nullable()->after('pix_copia_e_cola');
            $t->text('ticket_url')->nullable()->after('qr_code_base64');
            $t->text('erro')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->dropColumn(['gateway', 'qr_code_base64', 'ticket_url', 'erro']);
        });
    }
};
