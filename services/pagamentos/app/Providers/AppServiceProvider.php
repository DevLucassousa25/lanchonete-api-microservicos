<?php

namespace App\Providers;

use App\Gateways\FakeGateway;
use App\Gateways\GatewayPix;
use App\Gateways\MercadoPagoGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Troca de provedor só pelo .env: PAGAMENTO_GATEWAY=fake | mercadopago
        $this->app->singleton(GatewayPix::class, fn () => match (config('services.pix.gateway')) {
            'mercadopago' => new MercadoPagoGateway(
                accessToken: (string) config('services.mercadopago.access_token'),
                testPayerName: config('services.mercadopago.test_payer_name'),
                payerEmail: config('services.mercadopago.payer_email'),
                notificationUrl: config('services.mercadopago.notification_url'),
            ),
            default => new FakeGateway(),
        });
    }

    public function boot(): void
    {
        //
    }
}
