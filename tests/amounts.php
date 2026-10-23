<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{BillingAgreement, Service, Gateway};
use Illuminate\Support\Facades\Http;

foreach ([[0.29, 29], ['19.99', 1999], [0.1 + 0.2, 30], ['1.005', 101], ['-1.005', -101], ['0.0049', 0]] as [$value, $expected]) {
    check(invoke($gateway, 'toMinorUnits', $value, 'USD') === $expected, 'Exact integer minor units: ' . $value);
}
foreach (['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','VND','VUV','XAF','XOF','XPF'] as $currency) {
    check(invoke($gateway, 'toMinorUnits', '123.5', $currency) === 124, $currency . ' has no fractional minor units');
    check(invoke($gateway, 'fromMinorUnits', 124, $currency) === 124.0, $currency . ' reverse conversion');
}
check(invoke($gateway, 'fromMinorUnits', 1999, 'usd') === 19.99, 'Reverse cents conversion');
check(invoke($gateway, 'toMinorUnits', '19.99', 'isk') === 2000, 'ISK API keeps two-decimal representation');
foreach (['isk', 'ugx'] as $currency) {
    check(invoke($gateway, 'toMinorUnits', 500, $currency) === 50000, $currency . ' API retains two-decimal compatibility');
    check(invoke($gateway, 'fromMinorUnits', 50000, $currency) === 500.0, $currency . ' reverses compatibility scale');
}
foreach (['malformed' => 'not-an-amount', 'NaN' => NAN, 'infinity' => INF, 'negative infinity' => -INF,
    'oversized decimal' => '9999999999999999999999999999', 'oversized float' => PHP_FLOAT_MAX] as $label => $invalid) {
    try {
        invoke($gateway, 'toMinorUnits', $invalid, 'usd');
        throw new RuntimeException('Accepted invalid amount: ' . $label);
    } catch (InvalidArgumentException $e) {
        check(true, 'InvalidArgumentException rejects ' . $label);
    }
}
$previousHandler = Http::$handler;
Gateway::$enabled = true;
Http::$handler = function ($verb, $path, $data) {
    return match (true) {
        $path === '/customers/cus_owner' => ['id' => 'cus_owner'],
        $path === '/payment_intents' => ['id' => 'pi_amount', 'client_secret' => 'pi_secret'],
        $path === '/customer_sessions' => ['client_secret' => 'cuss_secret'],
        $path === '/subscriptions/sub_test' => ['schedule' => null, 'items' => ['data' => [
            ['id' => 'si_test', 'price' => ['product' => 'prod_test']]]]],
        $path === '/subscription_items/si_test' => [],
        default => throw new RuntimeException('Unexpected amount test request: ' . $path),
    };
};
$user->props->values = [];
$gateway->pay($invoice, 0.29);
check(Http::$calls[count(Http::$calls) - 2][2]['amount'] === 29, 'Checkout sends integer cents');
$service = new Service;
$gateway->updateSubscription($service);
check(end(Http::$calls)[2]['price_data']['unit_amount'] === 5997, 'Subscription update retains quantity > 1');
$gateway->charge($invoice, '19.99', new BillingAgreement);
check(end(Http::$calls)[2]['amount'] === 1999, 'Off-session charge sends integer cents');
$user->props->values = [];
$invoice->currency_code = 'JPY';
$gateway->pay($invoice, 123);
check(Http::$calls[count(Http::$calls) - 2][2]['amount'] === 123, 'JPY checkout sends yen');
$invoice->currency_code = 'usd';
Http::$handler = $previousHandler;
Gateway::$enabled = false;
