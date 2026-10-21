<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\{Http, Log};

Gateway::$enabled = true;
$oldHandler = Http::$handler;
$oldProps = $user->props->values;
$user->props->values = [];
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['deleted' => true];
    throw new RequestException(503);
};
$before = count(Http::$calls);
rejects(fn () => $gateway->pay($invoice, 20), 'Could not prepare your payment. Please try again.');
check(count(array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[1] === '/payment_intents')) === 0,
    'Customer failure never creates an anonymous intent');
check(end(Log::$warnings) === ['StripeSaved customer setup failed', ['invoice_id' => 12, 'error' => RequestException::class]],
    'Customer failure logs the invoice and error class only');
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/payment_intents') return ['id' => 'pi_session', 'status' => 'requires_payment_method', 'client_secret' => 'pi_secret'];
    if ($path === '/customer_sessions') throw new RequestException(503);
    throw new RuntimeException('Unexpected request: ' . $path);
};
$view = $gateway->pay($invoice, 20);
check($view['data']['customerSessionSecret'] === null && $view['data']['intent']->id === 'pi_session',
    'Session request failure still renders a customer-bound intent for a new method');
check(end(Log::$warnings) === ['StripeSaved customer session unavailable', ['invoice_id' => 12, 'http_status' => 503]],
    'Session failure logs no request payload or secrets');
$user->props->values = [];
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/payment_intents') return ['id' => 'pi_session', 'status' => 'requires_payment_method'];
    throw new RuntimeException('Unexpected programming failure');
};
rejects(fn () => $gateway->pay($invoice, 20), 'Unexpected programming failure');
$user->props->values = $oldProps;

$paid = (object) ['id' => 'pi_save_retry', 'amount' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner',
    'payment_method' => 'pm_retry', 'setup_future_usage' => 'off_session',
    'metadata' => (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1', 'stripe_saved_consent' => '1']];
$retryMethod = (object) ['id' => 'pm_retry', 'customer' => 'cus_other', 'type' => 'link', 'link' => (object) []];
Http::$handler = fn () => $retryMethod;
$before = ExtensionHelper::$applications;
check($gateway->webhook(eventRequest($paid))['status'] === 200 && ExtensionHelper::$applications === $before + 1,
    'Permanent method mismatch acknowledges the recorded payment');
check(!isset(ExtensionHelper::$agreements['pm_retry']), 'Mismatched customer method is never saved');
$retryMethod->customer = 'cus_owner';
$fail = true;
Http::$handler = function ($verb) use (&$fail, $retryMethod) {
    if ($verb === 'post' && $fail) { $fail = false; throw new RequestException(503); }
    return $retryMethod;
};
try { $gateway->webhook(eventRequest($paid)); throw new RuntimeException('Expected request failure'); }
catch (RequestException $e) { check($e->response->status() === 503, 'Transient saving request failure asks Stripe to retry'); }
$before = ExtensionHelper::$applications;
$gateway->webhook(eventRequest($paid));
$gateway->webhook(eventRequest($paid));
check(ExtensionHelper::$applications === $before && isset(ExtensionHelper::$agreements['pm_retry']),
    'Saving retry persists one agreement without recording payment again');
$countBefore = count(ExtensionHelper::$agreements);
ExtensionHelper::$agreementFailure = true;
try { $gateway->webhook(eventRequest($paid)); throw new RuntimeException('Expected agreement failure'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Interrupted agreement response', 'Interrupted agreement persistence propagates for retry'); }
$gateway->webhook(eventRequest($paid));
check(count(ExtensionHelper::$agreements) === $countBefore && ExtensionHelper::$applications === $before,
    'Agreement retry after local completion creates no duplicate agreement or payment');
// Account setup uses the same safe persistence guard, without swallowing request failures.
check(invoke($gateway, 'persistAgreement', $user, (object) ['customer' => 'cus_other']) === false,
    'Setup agreement twin rejects permanent method mismatch safely');
$subscriptionSetup = (object) ['id' => 'seti_mismatch', 'customer' => 'cus_other', 'metadata' => (object) ['invoice_id' => 12]];
Http::$handler = fn () => ['id' => 'cus_owner'];
$before = count(Http::$calls);
invoke($gateway, 'setupSubscription', $subscriptionSetup);
check(count(Http::$calls) === $before + 1, 'Subscription setup twin rejects wrong customer before mutations');
Http::$handler = $oldHandler;
Gateway::$enabled = false;
