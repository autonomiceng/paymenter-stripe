<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

Gateway::$enabled = true;
$oldHandler = Http::$handler;
$optIntent = (object) ['id' => 'pi_options', 'status' => 'requires_confirmation', 'customer' => 'cus_owner',
    'payment_method' => 'pm_options', 'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1']];
Http::$handler = fn () => $optIntent;
foreach ([true, false] as $save) {
    $gateway->consent(new Request(['intent' => 'pi_options', 'save' => $save]));
    check(end(Http::$calls)[2] === [
        'setup_future_usage' => '',
        'payment_method_options' => ['card' => ['setup_future_usage' => $save ? 'off_session' : ''],
            'link' => ['setup_future_usage' => $save ? 'off_session' : '']],
        'metadata' => ['ae_stripe_consent' => $save ? '1' : '0'],
    ], $save ? 'Saving sets card and Link usage and clears legacy usage' : 'Clearing removes legacy, card and Link usage');
}
$optIntent->status = 'requires_action';
$optIntent->metadata->ae_stripe_consent = '1';
$before = count(Http::$calls);
$gateway->consent(new Request(['intent' => 'pi_options', 'save' => true]));
check(count(Http::$calls) === $before + 1, '3DS resumes without modifying per-method consent');
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_options', 'save' => false])), 'HTTP 409');
$optIntent->setup_future_usage = 'off_session'; // Legacy top-level consent is insufficient.
$optIntent->payment_method_options = (object) [
    'card' => (object) ['setup_future_usage' => 'off_session'],
    'link' => (object) ['setup_future_usage' => 'off_session'],
];
foreach (['sepa_debit', 'us_bank_account', 'card', 'link'] as $type) {
    $optMethod = (object) ['id' => 'pm_options_' . $type, 'type' => $type, 'customer' => 'cus_owner',
        'card' => (object) ['brand' => 'visa', 'last4' => '4242', 'exp_year' => 2030, 'exp_month' => 5], 'link' => (object) []];
    Http::$handler = fn () => $optMethod;
    $before = count(ExtensionHelper::$agreements);
    invoke($gateway, 'savePaidMethod', $optIntent);
    check(count(ExtensionHelper::$agreements) === $before + (int) in_array($type, ['card', 'link']),
        'Checkout opt-in method scope: ' . $type);
    if (in_array($type, ['card', 'link'])) {
        $optIntent->payment_method_options->{$type}->setup_future_usage = '';
        $before = count(Http::$calls);
        invoke($gateway, 'savePaidMethod', $optIntent);
        check(count(Http::$calls) === $before + 1, 'Saving reads actual method option despite other method and top-level opt-in: ' . $type);
        $optIntent->payment_method_options->{$type}->setup_future_usage = 'off_session';
    }
}
Http::$handler = $oldHandler;
Gateway::$enabled = false;
