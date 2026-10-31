<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

Route::post('/extensions/ae-stripe/webhook', [AeStripe::class, 'webhook'])->withoutMiddleware([VerifyCsrfToken::class])->name('extensions.gateways.ae-stripe.webhook');
Route::post('/extensions/stripe-saved/webhook', [AeStripe::class, 'webhook'])->withoutMiddleware([VerifyCsrfToken::class])->name('extensions.gateways.stripe-saved.webhook');
Route::get('/extensions/ae-stripe/setup-agreement', [AeStripe::class, 'setupAgreement'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.setup-agreement');
Route::get('/extensions/stripe-saved/setup-agreement', [AeStripe::class, 'setupAgreement'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.stripe-saved.setup-agreement');

Route::post('/extensions/ae-stripe/consent', [AeStripe::class, 'consent'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.consent');

Route::post('/extensions/ae-stripe/setup-consent', [AeStripe::class, 'setupConsent'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.setup-consent');

Route::post('/extensions/ae-stripe/disable-core', [AeStripe::class, 'disableCore'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.disable-core');

Route::post('/extensions/ae-stripe/confirm', [AeStripe::class, 'confirmPayment'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.confirm');

Route::get('/extensions/ae-stripe/payment-return', [AeStripe::class, 'finishPayment'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.payment-return');

Route::post('/extensions/ae-stripe/auto-pay-default', [AeStripe::class, 'saveAutoPayDefault'])
    ->middleware(['web', 'auth'])
    ->name('extensions.gateways.ae-stripe.auto-pay-default');
