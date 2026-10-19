<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Confirma Pix pendentes consultando o provedor (container pagamentos-scheduler: php artisan schedule:work)
Illuminate\Support\Facades\Schedule::command('pagamentos:sincronizar')
    ->everyTenSeconds()
    ->withoutOverlapping();
