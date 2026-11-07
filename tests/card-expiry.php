<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

(function () {
    $state = [User::$row, Gateway::$rows, Gateway::$enabled, Gateway::$present, Gateway::$deleted,
        Auth::$id, BillingAgreement::$snapshot, ExtensionHelper::$agreements, DB::$tables, Http::$handler];
    try {
        User::$row = new User(new Properties('cus_owner'));
        Gateway::$rows = null;
        Gateway::$enabled = Gateway::$present = true;
        Gateway::$deleted = false;
        Auth::$id = 7;
        BillingAgreement::$snapshot = null;
        $gateway = new AeStripe([]);
        $setup = (object) ['id' => 'seti_expiry', 'status' => 'succeeded', 'customer' => 'cus_owner',
            'payment_method' => 'pm_new', 'latest_attempt' => null, 'metadata' => (object) [
                'user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v2',
                'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1700000000']];
        $card = fn ($id, $month, $year) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
            'allow_redisplay' => 'unspecified', 'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa',
                'last4' => '4242', 'exp_month' => $month, 'exp_year' => $year]];
        foreach (['month', 'year', 'lost update', 'lost detach', 'refused update', 'older attached', 'retained update'] as $case) {
            [$month, $year, $expiry] = $case === 'year' ? [5, 2031, '2031-05-31'] : [6, 2030, '2030-06-30'];
            $setup->id = 'seti_expiry_' . str_replace(' ', '_', $case);
            User::$row->props->values = [];
            ExtensionHelper::$agreements = ['pm_old' => ['id' => 19, 'external_reference' => 'pm_old',
                'user_id' => 7, 'gateway_id' => 2, 'name' => 'Visa **** 4242', 'type' => 'visa',
                'expiry' => '2030-05-31', 'deleted_at' => null]];
            DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 19, 'auto_pay' => true]];
            $tables = DB::$tables;
            $methods = ['pm_old' => $card('pm_old', 5, 2030), 'pm_new' => $card('pm_new', $month, $year)];
            $failure = $case;
            Http::$handler = function ($verb, $path, $data) use ($setup, &$methods, &$failure) {
                if ($path === '/setup_intents/' . $setup->id) return $setup;
                foreach ($methods as $id => $method) {
                    if ($path === '/payment_methods/' . $id . '/detach') {
                        check(DB::$userLocked, 'Expiry replacement detaches under the owner lock');
                        $method->customer = null;
                        if ($failure === 'lost detach') { $failure = null; throw new \Illuminate\Http\Client\ConnectionException('Lost detach response'); }
                        return $method;
                    }
                    if ($path !== '/payment_methods/' . $id) continue;
                    if ($verb === 'post') {
                        check(DB::$userLocked, 'Expiry replacement updates under the owner lock');
                        if (isset($data['card']) && in_array($failure, ['refused update', 'older attached', 'retained update'], true)) { $failure = null; throw new \Illuminate\Http\Client\RequestException(400, ['error' => ['code' => 'invalid_expiry_year']]); }
                        foreach ($data['card'] ?? [] as $key => $value) $method->card->$key = $value;
                        if (isset($data['allow_redisplay'])) $method->allow_redisplay = $data['allow_redisplay'];
                        if (isset($data['card']) && $failure === 'lost update') { $failure = null; throw new \Illuminate\Http\Client\ConnectionException('Lost update response'); }
                    }
                    return $method;
                }
                throw new RuntimeException('Unexpected expiry request: ' . $path);
            };
            $setup->payment_method = 'pm_new';
            try {
                $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
                check(in_array($case, ['month', 'year'], true), 'Expected Stripe failure was exercised');
            } catch (\Illuminate\Http\Client\ConnectionException | \Illuminate\Http\Client\RequestException $error) {
                check(in_array($case, ['lost update', 'lost detach', 'refused update', 'older attached', 'retained update'], true), 'Only the configured failure propagates');
                check(count(ExtensionHelper::$agreements) === 1 && ExtensionHelper::$agreements['pm_old']['expiry'] === '2030-05-31',
                    'A Stripe failure retains one agreement and rolls back the local expiry');
                if ($case !== 'lost detach') check($methods['pm_new']->customer === 'cus_owner', 'Failed updates never detach the new method');
                if (in_array($case, ['older attached', 'retained update'], true)) {
                    $methods['pm_later'] = $card('pm_later', 5, 2030);
                    $setup->payment_method = $case === 'retained update' ? 'pm_old' : 'pm_later';
                    if ($case === 'retained update') { $methods['pm_old']->card->exp_month = 8; $methods['pm_old']->card->exp_year = 2034; }
                    $oldSetupId = $setup->id;
                    $setup->id .= '_later';
                    $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
                    $setup->payment_method = 'pm_new';
                    $setup->id = $oldSetupId;
                    [$month, $year, $expiry] = $case === 'retained update' ? [8, 2034, '2034-08-31'] : [5, 2030, '2030-05-31'];
                }
                $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
            }
            check(count(ExtensionHelper::$agreements) === 1 && isset(ExtensionHelper::$agreements['pm_old']),
                'Saving the same fingerprint with a different expiry updates one existing agreement');
            check($methods['pm_old']->card->exp_month === $month && $methods['pm_old']->card->exp_year === $year
                && ExtensionHelper::$agreements['pm_old']['expiry'] === $expiry,
                'The retained Stripe method and local saved card both show the confirmed expiry');
            check($methods['pm_new']->customer === null && $methods['pm_old']->allow_redisplay === 'always',
                'The redundant method is detached after updating the retained card');
            check($result['message'] === 'This card is already saved. We updated it.', 'Expiry updates show the customer one notice');
            check(DB::$tables === $tables, 'Expiry updates leave service preferences and charge history unchanged');
            $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
            check(count(ExtensionHelper::$agreements) === 1 && ExtensionHelper::$agreements['pm_old']['expiry'] === $expiry,
                'A repeated setup return preserves the updated agreement');
            $methods['pm_latest'] = $card('pm_latest', 7, 2033);
            $setup->payment_method = 'pm_latest';
            $oldSetupId = $setup->id;
            $setup->id .= '_latest';
            $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
            $setup->payment_method = 'pm_new';
            $setup->id = $oldSetupId;
            $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
            check(ExtensionHelper::$agreements['pm_old']['expiry'] === '2033-07-31'
                && $methods['pm_old']->card->exp_year === 2033 && count(ExtensionHelper::$agreements) === 1,
                'An older detached setup retry never rolls back a later confirmed expiry');
        }
    } finally {
        [User::$row, Gateway::$rows, Gateway::$enabled, Gateway::$present, Gateway::$deleted,
            Auth::$id, BillingAgreement::$snapshot, ExtensionHelper::$agreements, DB::$tables, Http::$handler] = $state;
    }
})();
