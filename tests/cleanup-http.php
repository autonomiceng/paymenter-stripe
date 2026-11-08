<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, User};
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Session};
use Paymenter\Extensions\Gateways\AeStripe\{AeStripe, DeduplicateCardsCommand};

global $count;
$case = $cleanupCase ?? getenv('PAY_CLEANUP_CASE');
$user = User::$row = new User(new Properties('cus_owner'));
Invoice::$row = new Invoice($user);
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
BillingAgreement::$snapshot = null;
DB::$tables['services'] = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$row = fn ($id, $pm) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card', 'allow_redisplay' => 'limited',
    'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$methods['pm_new']->allow_redisplay = 'always';
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
$reads = $writes = 0;
$mutate = null;
Http::$handler = function ($verb, $path, $data) use (&$methods, &$reads, &$writes, &$mutate, $case) {
    if ($case === 'http') check(DB::transactionLevel() === 0 && !DB::$userLocked, 'Cleanup Stripe HTTP runs without a transaction or owner lock');
    if ($verb === 'get') ++$reads; else ++$writes;
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id . '/detach') $method->customer = null;
        elseif ($path !== '/payment_methods/' . $id) continue;
        elseif ($verb === 'post') $method->allow_redisplay = $data['allow_redisplay'] ?? $method->allow_redisplay;
        if ($mutate) { $hook = $mutate; $mutate = null; $hook($verb, $path); }
        return json_decode(json_encode($method));
    }
    throw new RuntimeException('Unexpected cleanup fixture request: ' . $path);
};
if ($case === 'http') {
    $before = serialize([ExtensionHelper::$agreements, $user->props->values, $methods]);
    check($gateway->deduplicateCards(false, fn ($line) => null) === 0 && $reads > 0 && !$writes, 'Dry run verifies outside locks and sends no writes');
    check(serialize([ExtensionHelper::$agreements, $user->props->values, $methods]) === $before, 'Dry run leaves all state unchanged');
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $writes === 2, 'Apply refreshes redisplay and detaches outside locks');
    check(ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null && $methods['pm_new']->customer === null, 'Apply removes the verified duplicate');
} elseif ($case === 'contention') {
    require_once __DIR__ . '/../extension/AeStripe/DeduplicateCardsCommand.php';
    Gateway::$rows = ['AeStripe' => [new App\Models\Extension(['id' => 2, 'settings' => ['stripe_secret_key' => 'fake-secret']])]];
    Cache::$locks['ae-stripe-save-method-7'] = true;
    foreach ([false, true] as $apply) {
        $command = new DeduplicateCardsCommand;
        $command->options = ['apply' => $apply];
        check($command->handle() === 1, 'Contention preserves the nonzero skipped result');
        $text = implode(' ', $command->output);
        check(str_contains($text, 'Retry after the saved payment method update finishes or its lock expires.'), 'Contention explains when to retry');
        check(str_contains($text, '2 saved card(s) skipped.') && !str_contains($text, 'Review them manually.'), 'Contention summary refers to per-card reasons');
    }
    check(!$reads && !$writes, 'Contended cleanup sends no provider calls');
    unset(Cache::$locks['ae-stripe-save-method-7']);
    Gateway::$rows = null;
} elseif (str_starts_with($case, 'state-')) {
    $change = substr($case, 6);
    $entered = false;
    $gateway->deduplicateCards(true, function ($line) use ($change, &$entered, $user, $row) {
        if (!str_contains($line, 'duplicates') || $entered) return;
        $entered = true;
        DB::$beforeUserLock = function () use ($change, $user, $row) {
            switch ($change) {
                case 'customer': $user->props->customer = 'cus_other'; break;
                case 'owner': ExtensionHelper::$agreements['pm_new']['user_id'] = 99; break;
                case 'gateway': ExtensionHelper::$agreements['pm_old']['gateway_id'] = 9; break;
                case 'id': ExtensionHelper::$agreements['pm_new']['id'] = 99; break;
                case 'expiry': ExtensionHelper::$agreements['pm_old']['expiry'] = '2035-05-31'; break;
                case 'receipt': $user->props->values['ae_stripe_duplicate_pm_new'] = json_encode(['customer' => 'cus_owner', 'gateway' => 2, 'kept' => 'pm_foreign']); break;
                case 'service': DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 20]]; break;
                case 'account': $user->props->values['ae_stripe_auto_pay_card'] = json_encode(['user_id' => 7, 'agreement_id' => 20]); break;
            }
        };
    });
    check($entered, 'The state change occurs between verified discovery and apply');
    check(!$writes && $methods['pm_new']->customer === 'cus_owner' && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
        'Cleanup refuses changed exact state before any provider write: ' . $change);
} elseif ($case === 'post-state') {
    $handler = Http::$handler;
    $changed = false;
    Http::$handler = function ($verb, $path, $data) use ($handler, &$changed, $user) {
        $result = $handler($verb, $path, $data);
        if (!$changed && $verb === 'post') { $changed = true; $user->props->values['ae_stripe_save_sequence'] = '17'; }
        return $result;
    };
    check($gateway->deduplicateCards(true, fn ($line) => null) > 0 && $changed, 'A change during provider writes refuses final local cleanup');
    check(ExtensionHelper::$agreements['pm_new']['deleted_at'] === null && $user->props->values['ae_stripe_save_sequence'] === '17', 'Final cleanup preserves changed local state');
    Http::$handler = $handler;
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null, 'A reserved detached cleanup finishes safely on retry');
} elseif ($case === 'retry') {
    $methods['pm_alias'] = $card('pm_alias');
    $user->props->values['ae_stripe_saved_pending'] = json_encode(['incoming' => 'pm_alias', 'consent' => ['user_id' => 7]]);
    $handler = Http::$handler;
    $failed = $reserved = false;
    Http::$handler = function ($verb, $path, $data) use ($handler, &$failed, &$reserved, $user) {
        if (!$reserved && $verb === 'post') {
            $reserved = true;
            check(isset($user->props->values['ae_stripe_removal_pm_new']), 'Removal records the plan durably before any detach');
        }
        if ($path === '/payment_methods/pm_new/detach' && !$failed) {
            $failed = true;
            throw new RequestException(503);
        }
        return $handler($verb, $path, $data);
    };
    $remove = fn () => $gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_new']));
    check(!$remove() && ExtensionHelper::$agreements['pm_old']['deleted_at'] === null, 'Partial failure preserves local rows for retry');
    try { invoke($gateway, 'persistAgreement', $user, $methods['pm_alias'], ['source' => 'seti_late', 'user_id' => 7]); throw new RuntimeException('Expected removal refusal'); }
    catch (App\Exceptions\DisplayException $e) { check(str_contains($e->getMessage(), 'removed'), 'An interrupted removal cannot be undone by a delayed save'); }
    Http::$handler = function ($verb, $path, $data) use ($handler) {
        if ($verb === 'get' && $path === '/payment_methods/pm_alias') throw new RequestException(404, ['error' => ['code' => 'resource_missing']]);
        return $handler($verb, $path, $data);
    };
    check($remove(), 'A partial removal resumes receipt-only copies even if they now return 404');
    $journal = json_decode($user->props->values['ae_stripe_removal_pm_new'], true);
    check($journal['complete'] && count($journal['detached']) === 3, 'The resumed journal accounts for every copy');
} elseif (str_starts_with($case, 'removal-')) {
    $change = substr($case, 8);
    $service = App\Models\Service::$row = new App\Models\Service;
    $service->subscription_id = null; $service->billing_agreement_id = 19;
    $user->props->values['ae_stripe_saved_pending'] = json_encode(['incoming' => 'pm_new', 'consent' => ['user_id' => 7]]);
    $handler = Http::$handler;
    $changed = false;
    Http::$handler = function ($verb, $path, $data) use ($handler, &$changed, $change, $user) {
        $result = $handler($verb, $path, $data);
        if (!$changed && $path === '/payment_methods/pm_new/detach') {
            $changed = true;
            switch ($change) {
                case 'customer': $user->props->customer = 'cus_other'; break;
                case 'owner': ExtensionHelper::$agreements['pm_new']['user_id'] = 99; break;
                case 'gateway': ExtensionHelper::$agreements['pm_new']['gateway_id'] = 9; break;
                case 'id': ExtensionHelper::$agreements['pm_new']['id'] = 99; break;
            }
        }
        return $result;
    };
    $result = $gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_new']));
    check($changed && !$result, 'Final validation refuses the concurrent customer/agreement change');
    check(ExtensionHelper::$agreements['pm_old']['deleted_at'] !== null && $service->billing_agreement_id === null,
        'Already detached owned copies are reconciled and their services unlinked');
    check($change === 'customer' ? ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null : ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
        'Reconciliation removes the original owned clicked row but preserves reassigned/replaced rows');
    check(json_decode($user->props->values['ae_stripe_saved_pending'], true)['removed'] ?? false, 'Pending receipts cannot replay detached references');
    $journal = json_decode($user->props->values['ae_stripe_removal_pm_new'] ?? 'null', true);
    check(is_array($journal) && count($journal['detached'] ?? []) === 2, 'Durable removal progress records both detached copies');
} elseif ($case === 'notice-first') {
    $setup = (object) ['id' => 'seti_first', 'status' => 'succeeded', 'customer' => 'cus_owner', 'payment_method' => 'pm_new', 'latest_attempt' => null,
        'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v1',
            'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1700000000']];
    $handler = Http::$handler;
    Http::$handler = fn ($verb, $path, $data) => $path === '/setup_intents/seti_first' ? $setup : $handler($verb, $path, $data);
    ExtensionHelper::$agreements = []; $user->props->values = []; Session::$flashes = Session::$values = [];
    $expected = $gateway->setupAgreement(new Request(['setup_intent' => 'seti_first']));
    ExtensionHelper::$agreements = []; $user->props->values = []; Session::$flashes = Session::$values = [];
    $intent = (object) ['id' => 'pi_first', 'customer' => 'cus_owner', 'payment_method' => 'pm_new',
        'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1'],
        'payment_method_options' => (object) ['card' => (object) ['setup_future_usage' => 'off_session']]];
    invoke($gateway, 'saveConsentedMethod', $intent);
    $actual = Session::$values['ae_stripe_saved_notice_12']['notice'] ?? Session::$flashes['notification'] ?? null;
    check($actual === $expected, 'Independent first saves through Add card and checkout deliver the same notice');
    foreach (['no-consent', 'wrong-customer'] as $refusal) {
        Session::$flashes = Session::$values = [];
        if ($refusal === 'no-consent') $intent->metadata->ae_stripe_consent = '0';
        else { $intent->metadata->ae_stripe_consent = '1'; $methods['pm_new']->customer = 'cus_other'; }
        invoke($gateway, 'saveConsentedMethod', $intent);
        check(Session::$flashes === [] && Session::$values === [], 'A false/null save does not publish a success notice');
    }
}
App\Models\Service::$row = null;
DB::$beforeUserLock = DB::$afterTransaction = null;
DB::$tables['services'] = [];
echo "PASS: $case, $count cleanup assertions\n";
