<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

(function () {
    $user = User::$row = new User(new Properties('cus_owner'));
    Invoice::$row = new Invoice($user);
    Gateway::$rows = null;
    Gateway::$enabled = Gateway::$present = true;
    Gateway::$deleted = false;
    BillingAgreement::$snapshot = null;
    DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 19, 'auto_pay' => true]];
    $gateway = new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test']);
    $card = fn ($id, $month = 5) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
        'allow_redisplay' => 'always', 'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa',
            'last4' => '4242', 'exp_month' => $month, 'exp_year' => 2030]];
    $link = fn ($id, $email = 'saved@example.com') => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'link',
        'allow_redisplay' => 'always', 'link' => (object) ['email' => $email]];
    $row = fn ($id, $pm, $type) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
        'name' => $type === 'link' ? 'Link' : 'Visa **** 4242', 'type' => $type, 'expiry' => $type === 'link' ? null : '2030-05-31', 'deleted_at' => null];
    foreach (['card', 'link'] as $type) foreach (['setup', 'checkout'] as $entry) {
        if (($case = getenv('PAY_DEDUPE_CASE')) && $case !== $type . '-' . $entry) continue;
        $heldLocks = [];
        $user->props->values = [];
        $methods = $type === 'card' ? ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new', 6)]
            : ['pm_old' => $link('pm_old'), 'pm_new' => $link('pm_new', 'SAVED@example.com')];
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', $type)];
        $tables = DB::$tables;
        $setup = (object) ['id' => 'seti_dedupe_new', 'status' => 'succeeded', 'customer' => 'cus_owner',
            'payment_method' => 'pm_new', 'latest_attempt' => null, 'metadata' => (object) ['user_id' => '7',
                'is_billing_agreement' => '1', 'ae_stripe_gateway' => '1', 'ae_stripe_setup_consent' => 'off_session_v2',
                'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1794071700']];
        $intent = (object) ['id' => 'pi_dedupe_new', 'status' => 'succeeded', 'customer' => 'cus_owner',
            'payment_method' => 'pm_new', 'payment_method_options' => (object) [$type => (object) ['setup_future_usage' => 'off_session']],
            'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']];
        Http::$handler = function ($verb, $path, $data) use (&$methods, $setup, &$heldLocks) {
            $heldLocks[] = DB::$userLocked;
            if ($path === '/setup_intents/' . $setup->id) return $setup;
            foreach ($methods as $id => $method) {
                if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
                if ($path !== '/payment_methods/' . $id) continue;
                if ($verb === 'post') {
                    foreach ($data['card'] ?? [] as $key => $value) $method->card->$key = $value;
                    if (isset($data['allow_redisplay'])) $method->allow_redisplay = $data['allow_redisplay'];
                }
                return $method;
            }
            throw new RuntimeException('Unexpected dedupe request');
        };
        $save = $entry === 'setup' ? fn () => $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]))
            : fn () => invoke($gateway, 'savePaidMethod', $intent);
        $result = $save();
        check(count(ExtensionHelper::$agreements) === 1 && isset(ExtensionHelper::$agreements['pm_old']), "$entry $type reuses the agreement");
        if ($entry === 'setup') check(str_contains($result['message'], $type === 'card' ? 'expiry to 06/2030' : 'Link account is already saved'), 'Duplicate notices describe the update');
        check($methods['pm_new']->customer === 'cus_owner' && DB::$tables === $tables, 'Saving never detaches a referenced method or changes Auto Pay');
        if ($type === 'card') check(ExtensionHelper::$agreements['pm_old']['expiry'] === '2030-06-30', 'The saved expiry is refreshed');
        $save();
        check(count(ExtensionHelper::$agreements) === 1, 'Repeated delivery stays idempotent');
        check(!in_array(true, $heldLocks, true), 'Saving never performs HTTP under the owner lock');
    }
    if (!getenv('PAY_DEDUPE_PR69_CASE') || getenv('PAY_DEDUPE_PR69_CASE') === 'brands') {
        foreach (['setup', 'checkout'] as $entry) {
            $user->props->values = ['ae_stripe_auto_pay_default' => json_encode(['version' => AeStripe::AUTO_PAY_CONSENT,
                'user_id' => 7, 'accepted_at' => 1794071700, 'agreement_id' => 19])];
            $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new', 6)];
            foreach ($methods as $remote) $remote->card->brand = 'eftpos_au';
            ExtensionHelper::$agreements = ['pm_old' => array_replace($row(19, 'pm_old', 'eftpos_au'), ['name' => 'Card **** 4242'])];
            $setup->id = 'seti_brand_' . $entry;
            $intent->id = 'pi_brand_' . $entry;
            $intent->payment_method_options = (object) ['card' => (object) ['setup_future_usage' => 'off_session']];
            $entry === 'setup' ? $gateway->setupAgreement(new Request(['setup_intent' => $setup->id])) : invoke($gateway, 'savePaidMethod', $intent);
            check(count(ExtensionHelper::$agreements) === 1 && $gateway->autoPayCard($user) === 19, "$entry keeps the selected eftpos agreement instead of hiding a stale duplicate");
            check($methods['pm_old']->card->exp_month === 6 && ExtensionHelper::$agreements['pm_old']['expiry'] === '2030-06-30',
                "$entry refreshes the eftpos copy that Auto Pay still charges");
        }
    }
    if (!getenv('PAY_DEDUPE_PR69_CASE') || getenv('PAY_DEDUPE_PR69_CASE') === 'aliases') {
        foreach (['setup', 'checkout'] as $entry) {
            $user->props->values = [];
            $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
            ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card')];
            $setup->id = 'seti_alias_first_' . $entry;
            $setup->payment_method = $intent->payment_method = 'pm_new';
            $intent->id = 'pi_alias_first_' . $entry;
            $intent->payment_method_options = (object) ['card' => (object) ['setup_future_usage' => 'off_session']];
            $setup->metadata->ae_stripe_setup_consent_at = $intent->metadata->ae_stripe_consent_at = '1794071700';
            $save = $entry === 'setup' ? fn () => $gateway->setupAgreement(new Request(['setup_intent' => $setup->id])) : fn () => invoke($gateway, 'savePaidMethod', $intent);
            $save();
            check(!isset(ExtensionHelper::$agreements['pm_new']) && $methods['pm_new']->customer === 'cus_owner', 'The subscription-referenced incoming copy has no local agreement');
            // A Stripe subscription can retain this attached reference independently of the local agreement.
            $subscriptionMethod = 'pm_new';
            $methods['pm_fresh'] = $card('pm_fresh', 6);
            $setup->id = 'seti_alias_refresh_' . $entry;
            $intent->id = 'pi_alias_refresh_' . $entry;
            $setup->payment_method = $intent->payment_method = 'pm_fresh';
            $setup->metadata->ae_stripe_setup_consent_at = $intent->metadata->ae_stripe_consent_at = '1794071800';
            $save();
            check($methods[$subscriptionMethod]->card->exp_month === 6, "$entry refreshes the receipt-only method a Stripe subscription can still charge");
            check(count(ExtensionHelper::$agreements) === 1 && ExtensionHelper::$agreements['pm_old']['expiry'] === '2030-06-30', 'Alias refresh retains one local agreement');
            $latest = json_decode($user->props->values['ae_stripe_method_pm_new'], true);
            check($latest['consent']['accepted_at'] === 1794071800, 'The receipt-only copy records its refreshed consent');
            $methods['pm_new']->card->exp_month = 7;
            $user->props->values['ae_stripe_method_pm_new'] = json_encode(array_replace($latest, ['consent' => ['accepted_at' => 1794071900, 'sequence' => 99]]));
            $methods['pm_later'] = $card('pm_later', 8);
            $setup->id = 'seti_alias_order_' . $entry;
            $intent->id = 'pi_alias_order_' . $entry;
            $setup->payment_method = $intent->payment_method = 'pm_later';
            $setup->metadata->ae_stripe_setup_consent_at = $intent->metadata->ae_stripe_consent_at = '1794071850';
            $save();
            check($methods['pm_new']->card->exp_month === 7 && json_decode($user->props->values['ae_stripe_method_pm_new'], true)['consent']['accepted_at'] === 1794071900,
                'Receipt-only copies preserve their own newer consent and expiry');
        }
        // Restore the shared source fixture for the remaining lifecycle checks.
        $setup->payment_method = $intent->payment_method = 'pm_new';
        $setup->metadata->ae_stripe_setup_consent_at = $intent->metadata->ae_stripe_consent_at = '1794071700';
    }
    foreach (['foreign user', 'foreign gateway', 'removed'] as $conflict) {
        $user->props->values = ['ae_stripe_saved_alias_fixture' => json_encode(['incoming' => 'pm_alias', 'reference' => 'pm_old', 'consent' => ['user_id' => 7]])];
        $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new', 6), 'pm_alias' => $card('pm_alias')];
        $alias = $row(21, 'pm_alias', 'card');
        if ($conflict === 'foreign user') $alias['user_id'] = 99;
        if ($conflict === 'foreign gateway') $alias['gateway_id'] = 1;
        if ($conflict === 'removed') $alias['deleted_at'] = '2026-11-08';
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card'), 'pm_alias' => $alias];
        $before = ExtensionHelper::$agreements;
        $setup->id = 'seti_alias_guard_' . str_replace(' ', '_', $conflict);
        $calls = count(Http::$calls);
        $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        check($result['type'] === 'danger' && ExtensionHelper::$agreements === $before,
            'Receipt alias refresh refuses a ' . $conflict . ' reference');
        check(!array_filter(array_slice(Http::$calls, $calls), fn ($call) => $call[0] === 'post'), 'Conflicting alias references never authorize provider writes');
    }
    if (!getenv('PAY_DEDUPE_CR_CASE') || getenv('PAY_DEDUPE_CR_CASE') === 'remove') {
        $user->props->values = ['ae_stripe_auto_pay_default' => json_encode(['agreement_id' => 19])];
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card'), 'pm_new' => $row(20, 'pm_new', 'card')];
        $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
        $clicked = new BillingAgreement(ExtensionHelper::$agreements['pm_new']);
        $native = Http::$handler;
        $failed = false;
        Http::$handler = function ($verb, $path, $data) use ($native, &$failed) {
            if (!$failed && $path === '/payment_methods/pm_new/detach') { $failed = true; throw new \Illuminate\Http\Client\RequestException(503); }
            return $native($verb, $path, $data);
        };
        check(!$gateway->cancelBillingAgreement($clicked)
            && ExtensionHelper::$agreements['pm_old']['deleted_at'] === null && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
            'A failed detach never soft-deletes matching local agreements');
        check($gateway->cancelBillingAgreement($clicked), 'Removing matching local copies succeeds on retry');
        Http::$handler = $native;
        check($methods['pm_old']->customer === null && $methods['pm_new']->customer === null, 'Every local matching copy is detached at Stripe');
        check(ExtensionHelper::$agreements['pm_old']['deleted_at'] !== null && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
            'Gateway soft-deletes the other matching local agreements and leaves the clicked row to core');
        $clicked->delete();
        check(count(BillingAgreement::where('user_id', 7)->get()) === 0, 'Core deleting the clicked row leaves no active detached copies');
        check(!isset($user->props->values['ae_stripe_auto_pay_default']), 'Removing copies clears the older Auto Pay selection');
    }
    if (!getenv('PAY_DEDUPE_CR_CASE') || getenv('PAY_DEDUPE_CR_CASE') === 'notice') {
        $user->props->values = [];
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card')];
        $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new', 6)];
        $setup->id = 'seti_notice_shape';
        $intent->payment_method_options = (object) ['card' => (object) ['setup_future_usage' => 'off_session']];
        $setupNotice = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        invoke($gateway, 'saveConsentedMethod', $intent);
        check(\Illuminate\Support\Facades\Session::$flashes['notification'] === $setupNotice,
            'Pay-with-Save and Add card flash the same success notice array');
        $user->props->values = [];
        ExtensionHelper::$agreements = [];
        $setup->id = 'seti_new_method_notice';
        check($gateway->setupAgreement(new Request(['setup_intent' => $setup->id])) === [
            'type' => 'success', 'message' => 'Payment method added successfully.',
        ], 'A first save keeps the default success notification');
    }
    // Masked display labels and billing contact emails cannot identify a Link account.
    foreach (['different account', 'missing account'] as $case) {
        $user->props->values = [];
        $methods = ['pm_old' => $link('pm_old', 'somebody@example.com'), 'pm_new' => $link('pm_new', 'saved@example.com')];
        if ($case === 'missing account') { unset($methods['pm_new']->link->email); $methods['pm_new']->billing_details = (object) ['email' => 'somebody@example.com']; }
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'link')];
        $setup->id = 'seti_link_' . str_replace(' ', '_', $case);
        $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        check(count(ExtensionHelper::$agreements) === 2 && isset(ExtensionHelper::$agreements['pm_new']), 'Distinct or unknown Link accounts are never merged: ' . $case);
    }
    $choice = json_encode(['version' => AeStripe::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => 1794071700, 'agreement_id' => 19]);
    $user->props->values = ['ae_stripe_auto_pay_default' => $choice];
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new', 6)];
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card'), 'pm_new' => $row(20, 'pm_new', 'card')];
    $setup->id = 'seti_selected_older';
    $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
    check($gateway->autoPayCard($user) === 19 && $user->props->values['ae_stripe_auto_pay_default'] === $choice
        && ExtensionHelper::$agreements['pm_old']['deleted_at'] === null && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
        'An older selected Auto Pay agreement survives while the newest duplicate is updated');
    if (!getenv('PAY_DEDUPE_RECHECK') || getenv('PAY_DEDUPE_RECHECK') === 'expiry') {
        check($methods['pm_old']->card->exp_month === 6, 'The older Auto Pay copy has its Stripe expiry refreshed');
        check(ExtensionHelper::$agreements['pm_old']['expiry'] === '2030-06-30', 'The older Auto Pay copy has its local expiry refreshed');
    }
    if (!getenv('PAY_DEDUPE_RECHECK')) {
        // Each copy protects its own newer consent; the newest visible row cannot decide for the older copy.
        $user->props->values = ['ae_stripe_method_pm_old' => json_encode(['customer' => 'cus_owner', 'gateway_id' => 2,
            'consent' => ['accepted_at' => 1794071800, 'sequence' => 1]])];
        $methods = ['pm_old' => $card('pm_old', 7), 'pm_new' => $card('pm_new', 6)];
        ExtensionHelper::$agreements = ['pm_old' => array_replace($row(19, 'pm_old', 'card'), ['expiry' => '2030-07-31']),
            'pm_new' => $row(20, 'pm_new', 'card')];
        $setup->id = 'seti_per_copy_consent';
        $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        check($methods['pm_old']->card->exp_month === 7 && ExtensionHelper::$agreements['pm_old']['expiry'] === '2030-07-31',
            'An older save never rolls back the hidden copy with newer consent');
        check($methods['pm_new']->card->exp_month === 6 && ExtensionHelper::$agreements['pm_new']['expiry'] === '2030-06-30',
            'A hidden copy with newer consent does not prevent another matching copy from refreshing');
    }
    if (!getenv('PAY_DEDUPE_RECHECK') || getenv('PAY_DEDUPE_RECHECK') === 'double-return') {
        $user->props->values = [];
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card')];
        $setup->id = 'seti_double_return';
        \Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-7'] = true;
        \Illuminate\Support\Facades\Cache::$beforeBlock = function () use ($gateway, $setup) {
            unset(\Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-7']);
            $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        };
        $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        check(str_contains($result['message'], 'already saved') && count(ExtensionHelper::$agreements) === 1,
            'A double Add card return waits for the first save and reuses its receipt');
        \Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-7'] = true;
        $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        check($result['type'] === 'danger' && str_contains($result['message'], 'in progress'),
            'A long Add card conflict redirects with a retry notice instead of a 500');
        try {
            invoke($gateway, 'persistAgreement', $user, (object) ['id' => 'pm_new']);
            throw new RuntimeException('Expected a retryable save conflict');
        } catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) {
            check(!$e instanceof \App\Exceptions\DisplayException, 'Webhooks keep retrying temporary save conflicts');
        }
        unset(\Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-7']);
    }
    if (!getenv('PAY_DEDUPE_RECHECK') || getenv('PAY_DEDUPE_RECHECK') === 'remove') {
        $user->props->values = [];
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card')];
        $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new', 6)];
        $setup->id = 'seti_remove_alias';
        $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        $native = Http::$handler;
        $failed = false;
        Http::$handler = function ($verb, $path, $data) use ($native, &$failed) {
            if (!$failed && $path === '/payment_methods/pm_new/detach') { $failed = true; throw new \Illuminate\Http\Client\ConnectionException; }
            return $native($verb, $path, $data);
        };
        check(!$gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_old']))
            && $methods['pm_old']->customer === 'cus_owner' && $methods['pm_new']->customer === 'cus_owner',
            'Remove stops on a duplicate detach failure and preserves the visible card for retry');
        check($gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_old'])), 'Remove succeeds on retry');
        check($methods['pm_old']->customer === null && $methods['pm_new']->customer === null,
            'Remove detaches the incoming duplicate that has no local agreement');
        check(!in_array(true, $heldLocks, true), 'Remove performs HTTP outside the owner lock');
        $methods['pm_old']->customer = $methods['pm_new']->customer = 'cus_owner';
        $failed = false;
        Http::$handler = function ($verb, $path, $data) use ($native, &$failed) {
            if (!$failed && $path === '/payment_methods/pm_old/detach') { $failed = true; throw new \Illuminate\Http\Client\RequestException(503); }
            return $native($verb, $path, $data);
        };
        check(!$gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_old']))
            && $methods['pm_new']->customer === null && $methods['pm_old']->customer === 'cus_owner',
            'A later failure keeps local removal pending after an earlier copy detached');
        check($gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_old']))
            && $methods['pm_new']->customer === null && $methods['pm_old']->customer === null,
            'Removal retries skip detached copies and finish the remaining copy');
        Http::$handler = $native;
    }
    // An in-flight return from the old dedupe path still resolves without reviving a removed method.
    $user->props->values = ['ae_stripe_duplicate_pm_new' => json_encode(['customer' => 'cus_owner', 'gateway' => 2, 'kept' => 'pm_old'])];
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $methods['pm_new']->customer = null;
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old', 'card')];
    $setup->id = 'seti_legacy_detached';
    $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
    check(str_contains($result['message'], 'already saved') && count(ExtensionHelper::$agreements) === 1, 'Legacy detached duplicate receipts still resolve');
    ExtensionHelper::$agreements['pm_old']['deleted_at'] = '2026-11-08';
    $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
    check($result['type'] === 'danger' && str_contains($result['message'], 'removed'), 'Legacy receipts never revive removed agreements');
})();
