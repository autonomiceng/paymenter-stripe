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
