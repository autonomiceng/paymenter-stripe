<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\StripeSaved\StripeSaved;

Route::post('/extensions/stripe-saved/webhook', [StripeSaved::class, 'webhook'])->withoutMiddleware([VerifyCsrfToken::class])->name('extensions.gateways.stripe-saved.webhook');
Route::get('/extensions/stripe-saved/setup-agreement', [StripeSaved::class, 'setupAgreement'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.stripe-saved.setup-agreement');

Route::post('/extensions/stripe-saved/consent', [StripeSaved::class, 'consent'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.stripe-saved.consent');
