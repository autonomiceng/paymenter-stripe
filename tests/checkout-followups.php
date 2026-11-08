<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Properties, Service, User};
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

global $count;
$case = $checkoutCase ?? getenv('PAY_CHECKOUT_CASE');
$user = User::$row = new User(new Properties('cus_owner'));
Gateway::$rows = null; Gateway::$bundled = Gateway::$bundledEnabled = Gateway::$deleted = false;
Gateway::$enabled = Gateway::$present = true;
BillingAgreement::$snapshot = null;
DB::$tables['services'] = []; Service::$row = null;
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$row = fn ($id, $pm) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card', 'allow_redisplay' => 'always',
    'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new'), 'pm_added' => $card('pm_added')];
$writes = []; $fail = false;
Http::$handler = function ($verb, $path, $data) use (&$methods, &$writes, &$fail) {
    check(DB::transactionLevel() === 0, 'Follow-up provider I/O stays outside owner transactions');
    if ($verb !== 'get') $writes[] = $path;
    if ($fail && $path === '/payment_methods/pm_new/detach') throw new RequestException(503);
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id . '/detach') $method->customer = null;
        elseif ($path !== '/payment_methods/' . $id) continue;
        elseif ($verb === 'post' && isset($data['card'])) foreach ($data['card'] as $field => $value) $method->card->$field = $value;
        return json_decode(json_encode($method));
    }
    throw new RuntimeException('Unexpected follow-up provider request');
};
$reserve = function ($kept = 'pm_old') use ($user) {
    $user->props->values['ae_stripe_duplicate_pm_new'] = json_encode(['customer' => 'cus_owner', 'gateway' => 2, 'kept' => $kept]);
};
$index = function ($state, $customer = 'cus_owner', $gateway = 2) use ($user) {
    $user->props->values['ae_stripe_method_pm_old'] = json_encode(['customer' => $customer, 'gateway_id' => $gateway] + $state);
};
if (in_array($case, ['pending-survivor', 'removed-survivor', 'reserved-survivor'], true)) {
    $index($case === 'removed-survivor' ? ['removed' => true] : ['removing' => 'ae_stripe_removal_pm_old']);
    if ($case === 'reserved-survivor') $reserve();
    foreach ([false, true] as $apply) {
        $result = $gateway->deduplicateCards($apply, fn ($line) => null);
        check(!$writes && $methods['pm_new']->customer === 'cus_owner' && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
            'Cleanup never detaches a replacement for an ineligible survivor: ' . $case);
        if ($case === 'reserved-survivor') check($result > 0, 'A reservation naming an ineligible survivor fails visibly');
    }
    if ($case === 'pending-survivor') {
        $index(['removing' => 'other-owner'], 'cus_other');
        check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $methods['pm_new']->customer === null, 'Other-customer indexes do not block valid cleanup');
    }
} elseif ($case === 'notice-during-cleanup') {
    $handler = Http::$handler;
    Http::$handler = function ($verb, $path, $data) use ($handler, $user) {
        $result = $handler($verb, $path, $data);
        if (str_ends_with($path, '/detach')) $user->props->values['ae_stripe_notice_fixture'] = json_encode(['consumed' => true]);
        return $result;
    };
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null,
        'Consuming an unrelated checkout notice cannot interrupt a detached cleanup plan');
} elseif (str_starts_with($case, 'bound-reservation')) {
    $reserve();
    foreach ([false, true] as $apply) {
        $bound = false;
        $bind = function () use ($user, &$bound) { $bound = true; $user->props->values['ae_stripe_auto_pay_card'] = json_encode(['user_id' => 7, 'agreement_id' => 20]); };
        if ($case === 'bound-reservation-late') {
            unset($user->props->values['ae_stripe_auto_pay_card']);
            $result = $gateway->deduplicateCards($apply, function ($line) use ($bind) { if (str_contains($line, 'duplicates')) DB::$beforeUserLock = $bind; });
            if (!$apply) continue;
        } else { $bind(); $result = $gateway->deduplicateCards($apply, fn ($line) => null); }
        check($bound && $result > 0 && !$writes, 'Bound unresolved reservations never report cleanup success');
    }
} elseif ($case === 'orphan-reservation') {
    $reserve(); unset(ExtensionHelper::$agreements['pm_old']);
    $lines = [];
    check($gateway->deduplicateCards(false, fn ($line) => null) > 0, 'Dry run reports a reservation whose survivor was removed');
    check($gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; }) === 0, 'Apply cancels an unused reservation when its original card is still attached');
    check(!isset($user->props->values['ae_stripe_duplicate_pm_new']) && !$writes && $methods['pm_new']->customer === 'cus_owner', 'Cancelling an attached plan leaves the original usable without provider writes');
    $response = $gateway->saveAutoPayDefault(new Illuminate\Http\Request(['agreement_id' => 20]));
    check($response['status'] === 200, 'A cancelled unused cleanup plan no longer blocks card selection');
} elseif (str_starts_with($case, 'removal-')) {
    $service = Service::$row = new Service;
    $service->subscription_id = null; $service->billing_agreement_id = 19;
    $user->props->values['ae_stripe_auto_pay_card'] = json_encode(['user_id' => 7, 'agreement_id' => 19]);
    $user->props->values['ae_stripe_saved_fixture'] = json_encode(['incoming' => 'pm_old', 'consent' => ['user_id' => 7]]);
    $fail = true;
    $remove = fn () => $gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_new']));
    check(!$remove() && $methods['pm_old']->customer === null, 'Partial customer-requested removal retains its failed outcome');
    if ($case === 'removal-partial') {
        check($service->billing_agreement_id === null && ExtensionHelper::$agreements['pm_old']['deleted_at'] !== null, 'A provider failure immediately reconciles already-detached owned copies');
    } else {
        // Recreate the unresolved local state left by the preceding release, using its durable journal.
        ExtensionHelper::$agreements['pm_old']['deleted_at'] = null; $service->billing_agreement_id = 19;
        $user->props->values['ae_stripe_auto_pay_card'] = json_encode(['user_id' => 7, 'agreement_id' => 19]);
        $changed = substr($case, 8);
        if ($changed === 'missing') unset(ExtensionHelper::$agreements['pm_new']);
        else ExtensionHelper::$agreements['pm_new'][$changed === 'owner' ? 'user_id' : ($changed === 'gateway' ? 'gateway_id' : 'id')] = 99;
        // Retry through the original authorized clicked row, as a stale native request can do.
        $writes = [];
        $result = $gateway->cancelBillingAgreement(new BillingAgreement($row(20, 'pm_new')));
        check(!$result && !$writes && $service->billing_agreement_id === null && ExtensionHelper::$agreements['pm_old']['deleted_at'] !== null,
            'Refused retry reconciles proven detached owned copies without modifying a changed row: ' . $changed);
    }
    check(!isset($user->props->values['ae_stripe_auto_pay_card']), 'Removal recovery clears the detached account card');
    check(json_decode($user->props->values['ae_stripe_saved_fixture'], true)['removed'] ?? false, 'Removal recovery retires the detached receipt');
} elseif (in_array($case, ['save-reserved', 'save-reserved-new', 'save-reserved-expiry', 'save-replay'], true)) {
    if ($case === 'save-replay') {
        invoke($gateway, 'persistAgreement', $user, $methods['pm_new'], ['source' => 'seti_replay', 'user_id' => 7]);
        $reserve(); $writes = [];
        try { invoke($gateway, 'persistAgreement', $user, $methods['pm_new'], ['source' => 'seti_replay', 'user_id' => 7]); throw new RuntimeException('Expected reserved receipt refusal'); }
        catch (Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(!$writes, 'Replaying a receipt cannot report success for its reserved agreement'); }
        echo "PASS: $case, $count checkout follow-up assertions\n";
        return;
    }
    $reserve();
    if ($case === 'save-reserved-expiry') $methods['pm_added']->card->exp_year = 2035;
    if ($case === 'save-reserved-new') unset(ExtensionHelper::$agreements['pm_old']);
    foreach (['seti_fresh', 'pi_fresh'] as $source) {
        $result = invoke($gateway, 'persistAgreement', $user, $methods['pm_added'], ['source' => $source, 'user_id' => 7, 'accepted_at' => time()]);
        $receipt = json_decode($user->props->values['ae_stripe_saved_' . hash('sha256', $source . ':pm_added')], true);
        check(($receipt['reference'] ?? null) !== 'pm_new', 'Fresh Add card and payment saves never select a cleanup-reserved agreement');
        check(isset($user->props->values['ae_stripe_duplicate_pm_new']), 'Fresh save preserves recovery evidence');
        check($result === true || is_array($result), 'A bindable matching or fresh card is saved');
    }
    if ($case === 'save-reserved-expiry') {
        check($methods['pm_new']->card->exp_year === 2035 && ExtensionHelper::$agreements['pm_new']['expiry'] === '2035-05-31', 'Reserved attached copies still receive remote and local expiry updates');
        check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $methods['pm_new']->customer === null, 'Fresh saves preserve same-card cleanup recovery after an expiry update');
    }
} elseif ($case === 'legacy-reserved-survivor') {
    $reserve(); $methods['pm_new']->customer = null;
    $user->props->values['ae_stripe_duplicate_pm_old'] = json_encode(['customer' => 'cus_owner', 'gateway' => 2, 'kept' => 'pm_other']);
    try { invoke($gateway, 'persistAgreement', $user, $methods['pm_new'], ['source' => 'seti_legacy', 'user_id' => 7]); throw new RuntimeException('Expected unavailable legacy survivor refusal'); }
    catch (Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(!$writes, 'A legacy detached receipt cannot claim a reserved survivor is usable'); }
} elseif ($case === 'removing-message') {
    $index(['removing' => 'ae_stripe_removal_pm_old']);
    try { invoke($gateway, 'persistAgreement', $user, $methods['pm_old'], ['source' => 'seti_pending', 'user_id' => 7]); throw new RuntimeException('Expected pending removal refusal'); }
    catch (Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) {
        check($e->getMessage() === 'This payment method is being removed. Retry the removal, or add the card again.', 'Pending removal offers a concrete retry or fresh save');
    }
} else throw new RuntimeException('Unknown checkout follow-up case');
DB::$tables['services'] = []; DB::$beforeUserLock = null; Service::$row = null; Gateway::$bundled = true;
echo "PASS: $case, $count checkout follow-up assertions\n";
