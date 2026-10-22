<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
require __DIR__ . '/fakes.php';
require __DIR__ . '/../extension/StripeSaved/StripeSaved.php';

use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Event, Http, Log, Route};
use Paymenter\Extensions\Gateways\StripeSaved\StripeSaved;

$count = 0;
function check($value, $label) {
    global $count;
    if (!$value) throw new RuntimeException($label);
    ++$count;
}
function rejects($callback, $message) {
    try { $callback(); } catch (RuntimeException $e) { check($e->getMessage() === $message, $message); return; }
    throw new RuntimeException('Expected: ' . $message);
}
function invoke($gateway, $method, ...$args) { return (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$args); }
function eventRequest($intent, $time = null, $type = 'payment_intent.succeeded') {
    $body = json_encode(['type' => $type, 'data' => ['object' => $intent]]);
    $time ??= time();
    return new Request(body: $body, signature: 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, 'whsec_test'));
}
$user = User::$row = new User(new Properties('cus_owner'));
$invoice = Invoice::$row = new Invoice($user);
$gateway = new StripeSaved(['stripe_webhook_secret' => 'whsec_test', 'stripe_secret_key' => 'fake-secret']);
$intent = (object) ['id' => 'pi_test', 'customer' => 'cus_owner', 'status' => 'requires_payment_method',
    'client_secret' => 'pi_test_secret', 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000, 'payment_method' => 'pm_test',
    'metadata' => (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1']];
$method = (object) ['id' => 'pm_test', 'customer' => 'cus_owner', 'type' => 'card',
    'card' => (object) ['brand' => 'visa', 'last4' => '4242', 'exp_year' => 2030, 'exp_month' => 5]];
Http::$handler = function ($verb, $path, $data) use (&$intent, &$method) {
    return match (true) {
        $path === '/customers/cus_owner' => ['id' => 'cus_owner'],
        $path === '/payment_intents' => $intent,
        $path === '/customer_sessions' => ['client_secret' => 'cuss_test_secret'],
        $path === '/payment_intents/pi_test' && $verb === 'get' => $intent,
        $path === '/payment_intents/pi_test' && $verb === 'post' => (function () use (&$intent, $data) {
            $intent->payment_method_options = json_decode(json_encode($data['payment_method_options']));
            $intent->metadata->stripe_saved_consent = $data['metadata']['stripe_saved_consent'];
            return $intent;
        })(),
        $path === '/payment_methods/' . $method->id => $method,
        $path === '/setup_intents/seti_test' => ['id' => 'seti_test', 'status' => 'succeeded',
            'payment_method' => $method->id, 'latest_attempt' => null,
            'customer' => 'cus_owner',
            'metadata' => ['user_id' => 7, 'is_billing_agreement' => '1',
                'stripe_saved_setup_consent' => 'off_session_v1', 'stripe_saved_setup_consent_at' => '1700000000',
                'stripe_saved_setup_consent_user' => '7']],
        default => throw new RuntimeException('Unexpected HTTP request: ' . $path),
    };
};

$gateway->boot();
check(Event::$listeners === [], 'Bundled row retains subscription callbacks even when disabled');
check(Route::$routes === ['/extensions/stripe-saved/webhook', '/extensions/stripe-saved/setup-agreement',
    '/extensions/stripe-saved/consent', '/extensions/stripe-saved/setup-consent'], 'Distinct routes are still registered');
Gateway::$bundled = false;
$gateway->boot();
check(count(Event::$listeners) === 2, 'Standalone fork registers legacy subscription callbacks');
Gateway::$bundled = true;

$view = $gateway->pay($invoice, 20);
check($view['data']['customerSessionSecret'] === 'cuss_test_secret', 'Session secret passed to Elements');
$calls = Http::$calls;
check($calls[1][2]['customer'] === $calls[2][2]['customer'], 'Intent and session share the invoice customer');
check(!isset($calls[1][2]['setup_future_usage']), 'No saving on initial intent');
check($calls[2][2]['components']['payment_element']['features'] === [
    'payment_method_redisplay' => 'enabled', 'payment_method_save' => 'disabled', 'payment_method_remove' => 'disabled',
], 'Stripe draws saved methods; native consent/removal disabled');
Auth::$id = 99;
rejects(fn () => $gateway->pay($invoice, 20), 'HTTP 403');
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 403');
Auth::$id = 7;
$intent->customer = 'cus_other';
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 403');
$intent->customer = 'cus_owner';
$intent->metadata->stripe_saved_gateway = '0';
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 403');
$intent->metadata->stripe_saved_gateway = '1';
$gateway->consent(new Request(['intent' => 'pi_test', 'save' => false]));
check($intent->payment_method_options->card->setup_future_usage === '' && $intent->payment_method_options->link->setup_future_usage === '' && $intent->metadata->stripe_saved_consent === '0', 'Unchecked clears future usage');
$gateway->webhook(eventRequest($intent));
check(ExtensionHelper::$agreements === [], 'Unchecked never creates agreement');
$gateway->consent(new Request(['intent' => 'pi_test', 'save' => true]));
check($intent->payment_method_options->card->setup_future_usage === 'off_session' && $intent->payment_method_options->link->setup_future_usage === 'off_session' && $intent->metadata->stripe_saved_consent === '1', 'Explicit consent enables future usage');
$gateway->webhook(eventRequest($intent));
$gateway->webhook(eventRequest($intent));
check(count(ExtensionHelper::$agreements) === 1, 'Retried success has one agreement per method');
check(count(ExtensionHelper::$payments) === 1, 'Retried success uses the same native transaction key');
$gateway->webhook(eventRequest($intent, type: 'payment_intent.processing'));
$gateway->webhook(eventRequest($intent, type: 'payment_intent.payment_failed'));
check(ExtensionHelper::$payments['12:pi_test']['status'] === 'succeeded', 'Delayed failure/processing cannot downgrade success');
check(ExtensionHelper::$agreements['pm_test']['name'] === 'Visa **** 4242', 'Card label preserved');
check(ExtensionHelper::$agreements['pm_test']['gateway'] === 'StripeSaved', 'Independent native gateway');
check(ExtensionHelper::$agreements['pm_test']['expiry'] === '2030-05-31', 'Card expiry preserved');
check(end(Http::$calls)[2] === ['allow_redisplay' => 'always'], 'Redisplay opt-in reaches Stripe');
$method->customer = 'cus_other';
$warningCount = count(Log::$warnings);
$callCount = count(Http::$calls);
invoke($gateway, 'savePaidMethod', $intent);
check(count(Log::$warnings) === $warningCount + 1 && str_contains(end(Log::$warnings)[0], 'customer mismatch'), 'Payment method customer mismatch adds its own warning');
check(count(array_filter(array_slice(Http::$calls, $callCount), fn ($call) => $call[0] === 'post')) === 0, 'Payment method mismatch cannot update redisplay');
$method->customer = 'cus_owner';
$intent->customer = 'cus_other';
$warningCount = count(Log::$warnings);
$callCount = count(Http::$calls);
invoke($gateway, 'savePaidMethod', $intent);
check(count(Log::$warnings) === $warningCount + 1 && str_contains(end(Log::$warnings)[0], 'customer mismatch'), 'Intent customer mismatch adds its own warning');
check(count(Http::$calls) === $callCount, 'Intent customer mismatch returns before method lookup');
$intent->customer = 'cus_owner';
$intent->status = 'succeeded';
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 409');
$intent->metadata->stripe_saved_consent = '0';
$before = count(Http::$calls);
invoke($gateway, 'savePaidMethod', $intent);
check(count(Http::$calls) === $before, 'Future usage without consent metadata cannot save');

$method = (object) ['id' => 'pm_link', 'type' => 'link', 'customer' => 'cus_owner',
    'link' => (object) ['email' => 'saved@example.test']];
$setup = (object) ['id' => 'seti_test', 'metadata' => (object) ['user_id' => 7, 'is_billing_agreement' => '1', 'stripe_saved_gateway' => '1']];
$gateway->webhook(eventRequest($setup, type: 'setup_intent.succeeded'));
$gateway->setupAgreement(new Request(['setup_intent' => 'seti_test']));
check(count(ExtensionHelper::$agreements) === 2, 'Setup webhook plus redirect retry creates one agreement');
check(ExtensionHelper::$agreements['pm_link']['name'] === 'Link · saved@example.test', 'Native setup labels Link truthfully');
check(end(Http::$calls)[2] === ['allow_redisplay' => 'always'], 'Account setup grants redisplay after authorization');
Auth::$id = 99;
rejects(fn () => $gateway->setupAgreement(new Request(['setup_intent' => 'seti_test'])), 'HTTP 403');
Auth::$id = 7;

$link = (object) ['type' => 'link', 'link' => (object) ['email' => 'link@example.test']];
check(invoke($gateway, 'getPaymentDetails', $link)['name'] === 'Link · link@example.test', 'Stripe Link email');
$link = (object) ['type' => 'link', 'billing_details' => (object) ['email' => 'bill@example.test']];
check(invoke($gateway, 'getPaymentDetails', $link)['name'] === 'Link · bill@example.test', 'Stripe billing email fallback');
check(invoke($gateway, 'getPaymentDetails', (object) ['type' => 'link'])['name'] === 'Link', 'Missing email never invented');

$valid = eventRequest($intent);
check($gateway->webhook(new Request(body: $valid->body))['status'] === 400, 'Missing signature rejected');
check($gateway->webhook(new Request(body: $valid->body . ' ', signature: $valid->signature))['status'] === 400, 'Tampering rejected');
check($gateway->webhook(eventRequest($intent, time() - 301))['status'] === 400, 'Old replay rejected');
check($gateway->webhook(eventRequest($intent, time() + 301))['status'] === 400, 'Future timestamp rejected');
check(invoke($gateway, 'isValidSignature', $valid->body, $valid->signature . ',v1=invalid', 'whsec_test'), 'Any matching rotated signature accepted');
check(!invoke($gateway, 'isValidSignature', $valid->body, $valid->signature, ''), 'Empty secret rejected');
$invalid = eventRequest($intent);
$invalid->body = '{}';
$invalidTime = time();
$invalid->signature = 't=' . $invalidTime . ',v1=' . hash_hmac('sha256', $invalidTime . '.{}', 'whsec_test');
check($gateway->webhook($invalid)['status'] === 400, 'Signed invalid event shape rejected');
Gateway::$enabled = false;
$before = count(Http::$calls);
$invalid->signature = 't=' . $invalidTime . ',v1=forged';
check($gateway->webhook($invalid)['status'] === 400 && Log::$lines === [], 'Disabled webhook rejects a forged signature');
check($gateway->webhook($valid)['status'] === 503, 'Disabled webhook is not acknowledged, so Stripe retries');
check(count(Http::$calls) === $before, 'Disabled webhook makes no HTTP requests');
check(Log::$lines === ['StripeSaved webhook deferred: gateway disabled or missing'], 'Disabled log contains no payload or secrets');
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 409');
$before = count(Http::$calls);
rejects(fn () => $gateway->pay($invoice, 20), 'HTTP 409');
check(count(Http::$calls) === $before, 'Disabled checkout rejects before any HTTP request');
$oldHandler = Http::$handler;
$disabledSetupId = 'seti_abcdefghijklmnopqrstuvwxyz';
$disabledSetupIntent = (object) ['id' => $disabledSetupId, 'status' => 'succeeded', 'customer' => 'cus_owner',
    'payment_method' => 'pm_disabled', 'latest_attempt' => null,
    'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1',
        'stripe_saved_setup_consent' => 'off_session_v1', 'stripe_saved_setup_consent_at' => '1700000000',
        'stripe_saved_setup_consent_user' => '7']];
Http::$handler = function ($verb, $path) use ($disabledSetupIntent, $disabledSetupId) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/setup_intents') return ['id' => 'seti_created'];
    if ($path === '/setup_intents/' . $disabledSetupId) return $disabledSetupIntent;
    if ($path === '/payment_methods/pm_disabled') return ['id' => 'pm_disabled', 'type' => 'card',
        'customer' => 'cus_owner', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_year' => 2030, 'exp_month' => 5]];
    throw new RuntimeException('Unexpected disabled setup request: ' . $path);
};
$before = count(Http::$calls);
rejects(fn () => $gateway->createBillingAgreement($user), 'HTTP 409');
check(count(Http::$calls) === $before, 'Disabled agreement creation makes no HTTP requests');
$before = count(Http::$calls);
rejects(fn () => $gateway->setupAgreement(new Request(['setup_intent' => $disabledSetupId])), 'HTTP 409');
check(count(Http::$calls) === $before, 'Disabled setup redirect makes no HTTP requests');
$disabledSetupIntent->status = 'requires_payment_method';
$before = count(Http::$calls);
rejects(fn () => $gateway->setupConsent(new Request(['intent' => $disabledSetupId, 'consent' => true])), 'HTTP 409');
check(count(Http::$calls) === $before, 'Disabled setup consent makes no HTTP requests');
Http::$handler = $oldHandler;
require __DIR__ . '/subscription-listeners.php';
require __DIR__ . '/adoption.php';
require __DIR__ . '/adoption-store.php';
require __DIR__ . '/views.php';
require __DIR__ . '/amounts.php';
require __DIR__ . '/settlement-regressions.php';
require __DIR__ . '/handlers.php';
require __DIR__ . '/retries.php';
require __DIR__ . '/setup-consent.php';
require __DIR__ . '/ownership.php';
require __DIR__ . '/checkout-owner.php';
require __DIR__ . '/failures.php';
require __DIR__ . '/payment-regressions.php';
require __DIR__ . '/method-consent.php';
require __DIR__ . '/native-upgrade.php';
echo "PASS: $count offline assertions\n";
