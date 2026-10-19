<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Gateway Pix: "fake" (simulado, confirma via webhook manual) ou "mercadopago"
    'pix' => [
        'gateway' => env('PAGAMENTO_GATEWAY', 'fake'),
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        // Em credenciais de TESTE, first_name "APRO" faz o Mercado Pago aprovar o Pix sozinho.
        // Deixe vazio em produção para usar o nome real do cliente.
        'test_payer_name' => env('MERCADOPAGO_TEST_PAYER_NAME'),
        'payer_email' => env('MERCADOPAGO_PAYER_EMAIL', 'test@testuser.com'),
        'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'), // URL pública (ngrok) para webhooks
    ],

    // Mesmo segredo em todos os serviços: contas emite, os outros validam
    'jwt' => [
        'secret' => env('JWT_SECRET'),
        'ttl' => (int) env('JWT_TTL_MINUTOS', 60),
        'revogacao_store' => env('JWT_REVOGACAO_STORE', 'jwt'),
    ],

];
