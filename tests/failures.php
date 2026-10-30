<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{Http, Log};

Gateway::$enabled = true;
$oldHandler = Http::$handler;
$originalInvoice = $invoice; $invoice = clone $invoice; $invoice->id = 916; Invoice::$rows[916] = $invoice;
$oldProps = $user->props->values;
$user->props->values = [];
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['deleted' => true];
    throw new RequestException(503);
};
$before = count(Http::$calls);
rejects(fn () => $gateway->pay($invoice, 20), 'Could not prepare your payment. Try again.');
check(count(array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[1] === '/payment_intents')) === 0,
    'Customer failure never creates an anonymous intent');
check(end(Log::$warnings) === ['AeStripe customer setup failed', ['invoice_id' => 916, 'error' => RequestException::class]],
    'Customer failure logs the invoice and error class only');
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/payment_intents') return ['id' => 'pi_session', 'status' => 'requires_payment_method', 'confirmation_method' => 'automatic', 'currency' => 'usd', 'customer' => 'cus_owner', 'client_secret' => 'pi_secret'];
    if ($path === '/customer_sessions') throw new RequestException(503);
    throw new RuntimeException('Unexpected request: ' . $path);
};
$view = $gateway->pay($invoice, 20);
check($view['data']['customerSessionSecret'] === null && $view['data']['intent']->id === 'pi_session',
    'Session request failure still renders a customer-bound intent for a new method');
check(end(Log::$warnings) === ['AeStripe customer session unavailable', ['invoice_id' => 916, 'http_status' => 503]],
    'Session failure logs no request payload or secrets');
$user->props->values = [];
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/payment_intents') return ['id' => 'pi_connection', 'status' => 'requires_payment_method', 'confirmation_method' => 'automatic', 'currency' => 'usd', 'customer' => 'cus_owner',
        'client_secret' => 'pi_connection_secret'];
    if ($path === '/customer_sessions') throw new ConnectionException('private connection error');
    throw new RuntimeException('Unexpected request: ' . $path);
};
$view = $gateway->pay($invoice, 20);
check($view['data']['customerSessionSecret'] === null && $view['data']['intent']->id === 'pi_connection',
    'Customer Session connection failure still renders checkout');
check(($view['data']['intent']->client_secret ?? null) === 'pi_connection_secret',
    'Customer Session connection failure passes the PaymentIntent client secret to checkout unchanged');
check(end(Log::$warnings) === ['AeStripe customer session unavailable', ['invoice_id' => 916, 'http_status' => null]],
    'Connection failure logs invoice and absent status without exception payload');
$user->props->values = [];
Http::$handler = function ($verb, $path) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/payment_intents') return ['id' => 'pi_session', 'status' => 'requires_payment_method'];
    throw new RuntimeException('Unexpected programming failure');
};
rejects(fn () => $gateway->pay($invoice, 20), 'Unexpected programming failure');
$user->props->values = $oldProps;
unset(Invoice::$rows[916]); $invoice = $originalInvoice;

$paid = (object) ['id' => 'pi_save_retry', 'amount' => 2000, 'amount_received' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner',
    'payment_method' => 'pm_retry', 'payment_method_options' => (object) ['link' => (object) ['setup_future_usage' => 'off_session']],
    'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1']];
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
$retryMethod->id = 'pm_retry_local';
$paid->payment_method = $retryMethod->id;
$countBefore = count(ExtensionHelper::$agreements);
ExtensionHelper::$agreementFailure = true;
try { $gateway->webhook(eventRequest($paid)); throw new RuntimeException('Expected agreement failure'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Interrupted agreement response', 'Interrupted agreement persistence propagates for retry'); }
check(!isset(ExtensionHelper::$agreements['pm_retry_local']), 'Interrupted first agreement creation rolls back');
$gateway->webhook(eventRequest($paid));
$gateway->webhook(eventRequest($paid));
check(count(ExtensionHelper::$agreements) === $countBefore + 1 && ExtensionHelper::$applications === $before,
    'First-creation retry creates one agreement without recording payment again');
// Account setup uses the same safe persistence guard, without swallowing request failures.
check(invoke($gateway, 'persistAgreement', $user, (object) ['customer' => 'cus_other']) === false,
    'Setup agreement twin rejects permanent method mismatch safely');
$subscriptionSetup = (object) ['id' => 'seti_mismatch', 'customer' => 'cus_other', 'metadata' => (object) ['invoice_id' => 12]];
Http::$handler = fn () => ['id' => 'cus_owner'];
$before = count(Http::$calls);
invoke($gateway, 'setupSubscription', $subscriptionSetup);
check(count(Http::$calls) === $before + 1, 'Subscription setup twin rejects wrong customer before mutations');
$subscriptionSetup->customer = 'cus_owner';
$subscriptionSetup->payment_method = 'pm_foreign';
Http::$handler = function ($verb, $path) {
    if ($verb !== 'get') throw new RuntimeException('Foreign method must not mutate Stripe');
    return match ($path) {
        '/customers/cus_owner' => ['id' => 'cus_owner'],
        '/payment_methods/pm_foreign' => ['id' => 'pm_foreign', 'customer' => 'cus_other'],
        default => throw new RuntimeException('Unexpected ownership request: ' . $path),
    };
};
$before = count(Http::$calls);
invoke($gateway, 'setupSubscription', $subscriptionSetup);
check(array_column(array_slice(Http::$calls, $before), 1) === ['/customers/cus_owner', '/payment_methods/pm_foreign']
    && array_column(array_slice(Http::$calls, $before), 0) === ['get', 'get'],
    'Subscription setup refuses another customer method before customer update or schedule creation');
Http::$handler = $oldHandler;
Gateway::$enabled = false;
