<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Http, Log};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

if (!function_exists('check')) {
    require __DIR__ . '/fakes.php';
    require __DIR__ . '/../extension/AeStripe/AeStripe.php';
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
    function invoke($gateway, $method, ...$args) { return (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$args); }
    function eventRequest($object, $time = null, $type = 'payment_intent.succeeded') {
        $body = json_encode(['type' => $type, 'data' => ['object' => $object]]);
        $time ??= time();
        return new Request(body: $body, signature: 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, 'whsec_test'));
    }
}
$user = User::$row = new User(new Properties('cus_owner'));
$invoice = Invoice::$row = new Invoice($user);
ExtensionHelper::$payments = []; // This fixture exercises agreement persistence on an unfunded invoice.
Invoice::$rows = [];
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
Auth::$id = 7;
$gateway = new AeStripe(['stripe_webhook_secret' => 'whsec_test', 'stripe_secret_key' => 'fake-secret']);
$agreementMethod = (object) ['id' => 'pm_duplicate', 'type' => 'card', 'customer' => 'cus_owner',
    'card' => (object) ['brand' => 'visa', 'last4' => '4242', 'exp_year' => 2030, 'exp_month' => 5]];
$agreementSetup = (object) ['id' => 'seti_duplicate', 'status' => 'succeeded', 'payment_method' => $agreementMethod->id,
    'latest_attempt' => null, 'customer' => 'cus_owner', 'metadata' => (object) ['user_id' => '7',
        'is_billing_agreement' => '1', 'ae_stripe_gateway' => '1', 'ae_stripe_setup_consent' => 'off_session_v1',
        'ae_stripe_setup_consent_at' => '1700000000', 'ae_stripe_setup_consent_user' => '7']];
$agreementPaid = (object) ['id' => 'pi_duplicate', 'status' => 'succeeded', 'customer' => 'cus_owner',
    'client_secret' => 'pi_duplicate_secret', 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000,
    'payment_method' => $agreementMethod->id, 'payment_method_options' => (object) ['card' => (object) ['setup_future_usage' => 'off_session']],
    'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1']];
Http::$handler = function ($verb, $path, $data) use ($agreementMethod, $agreementSetup, $agreementPaid) {
    if ($path === '/payment_methods/' . $agreementMethod->id) return $agreementMethod;
    if ($path === '/setup_intents/' . $agreementSetup->id || $path === '/setup_intents') {
        if ($verb === 'post' && isset($data['metadata'])) foreach ($data['metadata'] as $key => $value) $agreementSetup->metadata->$key = $value;
        return $agreementSetup;
    }
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($path === '/payment_intents' || $path === '/payment_intents/' . $agreementPaid->id) return $agreementPaid;
    if ($path === '/customer_sessions') return ['client_secret' => 'cuss_duplicate_secret'];
    throw new RuntimeException('Unexpected agreement request: ' . $path);
};
$agreementRow = ['id' => 19, 'external_reference' => $agreementMethod->id, 'user_id' => 7, 'gateway_id' => 2,
    'gateway' => 'AeStripe', 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$scenario = getenv('AGREEMENT_SCENARIO') ?: 'all';
if (in_array($scenario, ['all', 'sequential'])) {
    ExtensionHelper::$agreements = [];
    $gateway->setupAgreement(new Request(['setup_intent' => $agreementSetup->id]));
    ExtensionHelper::$agreements[$agreementMethod->id]['deleted_at'] = '2026-10-23 11:18:14';
    $result = $gateway->setupAgreement(new Request(['setup_intent' => $agreementSetup->id]));
    check($result === ['type' => 'danger', 'message' => 'This payment method was removed. Add a new payment method.'],
        'Sequential removed-method retry redirects with a clear notification instead of HTTP 500');
    check(count(ExtensionHelper::$agreements) === 1 && ExtensionHelper::$agreements[$agreementMethod->id]['deleted_at'] !== null,
        'Sequential retry preserves the removed row');
    echo "PASS: sequential duplicate refusal\n";
}
if (in_array($scenario, ['all', 'interleaved'])) {
    // An earlier consistent read misses the agreement committed by another request.
    BillingAgreement::$snapshot = [];
    ExtensionHelper::$agreements = [$agreementMethod->id => $agreementRow];
    $result = $gateway->setupAgreement(new Request(['setup_intent' => $agreementSetup->id]));
    check($result['type'] === 'success' && count(ExtensionHelper::$agreements) === 1
        && ExtensionHelper::$agreements[$agreementMethod->id]['id'] === 19,
        'Redirect uses a current read after another request commits despite an older RR snapshot');
    BillingAgreement::$snapshot = null;
    echo "PASS: interleaved duplicate retry\n";
}
if ($scenario === 'all') {
    $service = \App\Models\Service::$row = new \App\Models\Service;
    $service->billing_agreement_id = 33;
    $service->auto_pay = false;
    $serviceBefore = serialize($service);
    $tablesBefore = DB::$tables;
    $entries = [
        'persistence' => fn () => invoke($gateway, 'persistAgreement', $user, $agreementMethod),
        'redirect' => fn () => $gateway->setupAgreement(new Request(['setup_intent' => $agreementSetup->id])),
        'setup webhook' => fn () => $gateway->webhook(eventRequest($agreementSetup, type: 'setup_intent.succeeded')),
        'payment webhook' => fn () => $gateway->webhook(eventRequest($agreementPaid)),
    ];
    foreach ($entries as $entry => $call) {
        foreach ([null, []] as $snapshot) {
            ExtensionHelper::$agreements = [];
            BillingAgreement::$snapshot = $snapshot;
            $call();
            $first = ExtensionHelper::$agreements;
            $call();
            check(count($first) === 1 && ExtensionHelper::$agreements === $first, "$entry saves and retries one agreement with visible or stale snapshot");
        }
    }
    BillingAgreement::$snapshot = null;
    ExtensionHelper::$agreements = [$agreementMethod->id => $agreementRow];
    $unchanged = ExtensionHelper::$agreements;
    $agreementSetup->status = 'requires_payment_method';
    $gateway->createBillingAgreement($user);
    $gateway->setupConsent(new Request(['intent' => $agreementSetup->id, 'consent' => true]));
    $agreementPaid->status = 'requires_payment_method'; $agreementPaid->amount_received = 0; $agreementPaid->confirmation_method = 'automatic';
    $user->props->values = [];
    $checkoutInvoice = clone $invoice; $checkoutInvoice->id = 915; Invoice::$rows[915] = $checkoutInvoice;
    $gateway->pay($checkoutInvoice, 20);
    unset(Invoice::$rows[915]);
    $gateway->webhook(eventRequest($agreementMethod, type: 'payment_method.attached'));
    check(ExtensionHelper::$agreements === $unchanged,
        'Creating setup, consenting, rendering pay and ignored payment_method.attached do not persist agreements');
    $agreementSetup->status = $agreementPaid->status = 'succeeded'; $agreementPaid->amount_received = 2000;
    foreach (['user_id' => 99, 'gateway_id' => 1, 'deleted_at' => '2026-10-23 11:18:14'] as $field => $value) {
        $conflict = array_replace($agreementRow, [$field => $value]);
        ExtensionHelper::$agreements = [$agreementMethod->id => $conflict];
        $before = count(Http::$calls);
        $result = $entries['redirect']();
        check($result['type'] === 'danger' && str_contains($result['message'], $field === 'deleted_at' ? 'removed' : "can't be used on this account"),
            "Redirect renders a clear refusal for $field");
        foreach (['setup webhook', 'payment webhook'] as $entry) {
            $warnings = count(Log::$warnings);
            check($entries[$entry]()['status'] === 200 && count(Log::$warnings) === $warnings + 1,
                "$entry acknowledges and logs permanent $field refusal");
            $context = end(Log::$warnings)[1];
            $intentKey = $entry === 'setup webhook' ? 'setup_intent_id' : 'payment_intent_id';
            $intentId = $entry === 'setup webhook' ? $agreementSetup->id : $agreementPaid->id;
            check(($context[$intentKey] ?? null) === $intentId && isset($context['reason']), "$entry refusal log identifies the intent and reason for $field");
            check(!str_contains($result['message'], $intentId), "$entry intent ID stays out of customer refusal text");
        }
        check(ExtensionHelper::$agreements === [$agreementMethod->id => $conflict], "Refusal preserves $field ownership and deletion");
        check(count(array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[0] === 'post')) === 0,
            "Refusal for $field never changes redisplay");
    }
    foreach (['user_id' => 7, 'other user' => 99, 'other gateway' => 1, 'removed' => '2026-10-23 11:18:14'] as $race => $value) {
        $field = match ($race) { 'other user' => 'user_id', 'other gateway' => 'gateway_id', 'removed' => 'deleted_at', default => 'user_id' };
        $committed = array_replace($agreementRow, [$field => $value]);
        ExtensionHelper::$agreements = [];
        BillingAgreement::$snapshot = [];
        BillingAgreement::$beforeInsert = function () use ($agreementMethod, $committed) {
            // Commit by a native writer between the guard and the insert.
            ExtensionHelper::$agreements[$agreementMethod->id] = $committed;
            DB::$concurrentAgreements = [$agreementMethod->id => $committed];
        };
        $result = $entries['redirect']();
        check($result['type'] === ($race === 'user_id' ? 'success' : 'danger'), "Unique-constraint recovery checks $race");
        check(ExtensionHelper::$agreements === [$agreementMethod->id => $committed], "Unique recovery preserves the committed $race row");
        DB::$concurrentAgreements = [];
    }
    BillingAgreement::$snapshot = null;
    foreach (['redirect', 'setup webhook', 'payment webhook'] as $entry) {
        ExtensionHelper::$agreements = [];
        $unrelated = new \Illuminate\Database\UniqueConstraintViolationException('Duplicate ulid');
        BillingAgreement::$beforeInsert = fn () => throw $unrelated;
        try {
            $entries[$entry]();
            throw new RuntimeException('Unrelated unique violation must propagate');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            check($e === $unrelated, "$entry preserves unrelated unique failures for retry");
        }
        ExtensionHelper::$agreementFailure = true;
        try {
            $entries[$entry]();
            throw new RuntimeException('Persistence failure must propagate');
        } catch (RuntimeException $e) {
            check($e->getMessage() === 'Interrupted agreement response', "$entry preserves transient persistence failure");
        }
        check(ExtensionHelper::$agreements === [], "$entry rolls back interrupted persistence");
        $entries[$entry]();
        check(count(ExtensionHelper::$agreements) === 1, "$entry recovers on retry");
        $handler = Http::$handler;
        Http::$handler = function ($verb, $path, $data) use ($handler) {
            if ($verb === 'post') throw new \Illuminate\Http\Client\ConnectionException('Synthetic connection failure');
            return $handler($verb, $path, $data);
        };
        try {
            $entries[$entry]();
            throw new RuntimeException('Request failure must propagate');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            check($e->getMessage() === 'Synthetic connection failure', "$entry preserves transient redisplay failure");
        } finally {
            Http::$handler = $handler;
        }
        $entries[$entry]();
        check(count(ExtensionHelper::$agreements) === 1, "$entry retries redisplay without a second agreement");
    }
    check(serialize($service) === $serviceBefore && DB::$tables === $tablesBefore,
        'All agreement entry points preserve service assignments, Auto Pay and other tables');
    echo "PASS: agreement entry points, ownership and unique recovery\n";
}
