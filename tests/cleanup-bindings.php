<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, Service, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;
global $count;
$case = $bindingCase ?? getenv('PAY_BINDING_CASE');
$user = User::$row = new User(new Properties('cus_owner'));
Invoice::$row = new Invoice($user);
Gateway::$rows = null; Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = Gateway::$bundled = Gateway::$bundledEnabled = false;
BillingAgreement::$snapshot = null; Service::$row = null;
DB::$tables['services'] = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$row = fn ($id, $pm) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card', 'allow_redisplay' => 'always',
    'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new'), 'pm_added' => $card('pm_added')];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
$record = ['version' => AeStripe::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => 1700000000, 'agreement_id' => 20];
$choice = ['enabled' => true, 'agreement_id' => 20, 'consent' => true, 'version' => AeStripe::AUTO_PAY_CONSENT];
$entered = false; $bind = null;
Http::$handler = function ($verb, $path, $data) use (&$methods, &$entered, &$bind) {
    check(DB::transactionLevel() === 0, 'Binding reconciliation keeps provider HTTP outside transactions');
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id . '/detach') {
            $method->customer = null;
            if (!$entered && $bind) { $entered = true; $bind(); }
        } elseif ($path !== '/payment_methods/' . $id) continue;
        elseif ($verb === 'post') $method->allow_redisplay = $data['allow_redisplay'] ?? $method->allow_redisplay;
        return json_decode(json_encode($method));
    }
    throw new RuntimeException('Unexpected binding fixture request');
};
if (in_array($case, ['account-during', 'reserved-account'], true)) {
    $attempt = function () use ($gateway, $choice, $user) {
        $before = $user->props->values;
        foreach ([$choice, ['agreement_id' => 20]] as $selection) {
        $response = $gateway->saveAutoPayDefault(new Request($selection));
        check(($response['status'] ?? null) === 409 && str_contains($response['data']['message'] ?? '', 'Try again'), 'A competing account binding receives a clear retry response');
        check($user->props->values === $before, 'A refused account binding changes no choice or consent');
        }
    };
    if ($case === 'reserved-account') {
        $user->props->values['ae_stripe_duplicate_pm_new'] = json_encode(['customer' => 'cus_owner', 'gateway' => 2, 'kept' => 'pm_old']);
        $attempt();
    } else {
        $bind = $attempt;
        check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $entered, 'Cleanup completes while a concurrent account binding is refused');
    }
} elseif (in_array($case, ['repair-account', 'repair-service', 'lost-detach', 'unreconciled', 'survivor-change', 'missing-identity', 'missing-customer'], true)) {
    $bind = function () use ($case, $user, $record, &$methods) {
        if ($case !== 'repair-service') foreach (['ae_stripe_auto_pay_default', 'ae_stripe_auto_pay_card'] as $key) $user->props->values[$key] = json_encode($record);
        DB::$tables['services'] = [['id' => 41, 'user_id' => $case === 'unreconciled' ? 99 : 7, 'billing_agreement_id' => 20, 'auto_pay' => true]];
        if ($case === 'survivor-change') $methods['pm_old']->card->fingerprint = 'fp_other';
        if ($case === 'missing-identity') unset($methods['pm_new']->card->fingerprint);
        if ($case === 'missing-customer') $user->props->customer = '';
        if (in_array($case, ['lost-detach', 'missing-identity', 'missing-customer'], true)) throw new ConnectionException('Synthetic lost detach response');
    };
    $lines = [];
    $result = $gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; });
    if (in_array($case, ['unreconciled', 'survivor-change', 'missing-identity', 'missing-customer'], true)) {
        check($result > 0 && $entered, 'Unsafe post-detach reconciliation reports a failure');
        $lines = [];
        check($gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; }) > 0, 'Rerun cannot silently keep an unreconciled detached agreement');
        check(ExtensionHelper::$agreements['pm_new']['deleted_at'] === null && DB::$tables['services'][0]['billing_agreement_id'] === 20, 'Unsafe reconciliation preserves the changed owner/identity for review');
    } else {
        if ($case === 'lost-detach') {
            check($result > 0 && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null, 'Lost detach response retains a resumable plan');
            $result = $gateway->deduplicateCards(true, fn ($line) => null);
        }
        check($entered && $result === 0 && ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null, 'Cleanup finishes an already detached duplicate even if it became bound');
        check(DB::$tables['services'][0]['billing_agreement_id'] === 19 && DB::$tables['services'][0]['auto_pay'] === true, 'Service selection moves to the same-card survivor without changing its settings');
        if ($case !== 'repair-service') foreach (['ae_stripe_auto_pay_default', 'ae_stripe_auto_pay_card'] as $key) {
            check(json_decode($user->props->values[$key], true) === array_replace($record, ['agreement_id' => 19]), 'Account choice and its exact consent survive on the verified survivor');
        }
        check($gateway->deduplicateCards(true, fn ($line) => null) === 0, 'Completed reconciliation remains idempotent');
    }
} elseif ($case === 'inherit-during') {
    $user->props->values['ae_stripe_auto_pay_default'] = json_encode(array_replace($record, ['agreement_id' => 19]));
    $service = Service::$row = new Service;
    $service->subscription_id = null; $service->plan->type = 'recurring';
    $bind = function () use ($gateway, $service) {
        try { invoke($gateway, 'applyNewServiceAutoPay', $service); throw new RuntimeException('Expected inheritance contention'); }
        catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(str_contains($e->getMessage(), 'Try again'), 'Service inheritance uses the shared binding lock'); }
        check($service->billing_agreement_id === null, 'Contended inheritance does not choose a card');
    };
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $entered, 'Cleanup can finish while new-service inheritance is refused');
    invoke($gateway, 'applyNewServiceAutoPay', $service);
    check($service->billing_agreement_id === 19, 'Retry inherits the exact existing account choice after cleanup');
} elseif ($case === 'turn-off') {
    $user->props->values['ae_stripe_auto_pay_default'] = json_encode(array_replace($record, ['agreement_id' => 19]));
    $bind = fn () => $gateway->saveAutoPayDefault(new Request(['enabled' => false]));
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $entered, 'Turning Auto Pay off stays available during cleanup');
    check($gateway->autoPayDefault($user) === null && $gateway->autoPayCard($user) === 19, 'Cleanup preserves Off and its exact chosen survivor');
} elseif ($case === 're-add') {
    $handler = Http::$handler;
    Http::$handler = function ($verb, $path, $data) use ($handler) {
        if ($path === '/payment_methods/pm_old/detach') throw new RequestException(503);
        return $handler($verb, $path, $data);
    };
    check(!$gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_new'])), 'A failed removal stays retryable');
    Http::$handler = $handler;
    $result = invoke($gateway, 'persistAgreement', $user, $methods['pm_added'], ['source' => 'seti_new_consent', 'user_id' => 7, 'accepted_at' => time()]);
    check($result === true && isset(ExtensionHelper::$agreements['pm_added']), 'A fresh save of the same card can replace a pending failed removal');
    check($methods['pm_added']->customer === 'cus_owner', 'The fresh provider reference stays usable');
    check($gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_new'])), 'Explicitly retrying removal still removes every matching copy');
}
DB::$tables['services'] = []; Service::$row = null; Gateway::$bundled = true;
echo "PASS: $case, $count binding assertions\n";
