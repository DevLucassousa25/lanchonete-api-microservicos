<?php

use App\Http\Middleware\AutenticarJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Serviço só de API: sem rotas web, erros sempre em JSON
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['auth.jwt' => AutenticarJwt::class]);
        $middleware->trustProxies(at: '*'); // IP real do cliente vem do gateway (X-Forwarded-For)
        // Autentica ANTES de resolver models da rota (nada é consultado sem token válido)
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: AutenticarJwt::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
