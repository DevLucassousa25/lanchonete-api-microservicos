<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Login: 5 tentativas/min por e-mail+IP (anti força bruta sem bloquear o IP inteiro)
        RateLimiter::for('login', fn (Request $r) =>
            Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')) . '|' . $r->ip())
                ->response(fn () => response()->json(['message' => 'Muitas tentativas de login. Aguarde 1 minuto.'], 429))
        );

        // Cadastro: 10 contas/min por IP
        RateLimiter::for('registro', fn (Request $r) =>
            Limit::perMinute(10)->by($r->ip())
                ->response(fn () => response()->json(['message' => 'Muitos cadastros a partir deste IP. Aguarde 1 minuto.'], 429))
        );
    }
}
