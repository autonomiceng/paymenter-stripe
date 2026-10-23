<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Extension, Gateway, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Http, Route};
use Paymenter\Extensions\Gateways\AeStripe\ConvertFromStripeSavedCommand;

(function () {
    $tables = DB::$tables; $gatewayRows = Gateway::$rows; $user = User::$row;
    $agreements = ExtensionHelper::$agreements; $snapshot = BillingAgreement::$snapshot;
    $handler = Http::$handler; $auth = Auth::$id; $bundled = Gateway::$bundled;
    try {
        $row = fn ($id, $method) => ['id' => $id, 'external_reference' => $method, 'user_id' => 7,
            'gateway_id' => 42, 'name' => 'Visa **** 4242', 'type' => 'visa',
            'expiry' => '2030-05-31', 'deleted_at' => null];
        DB::$tables = [
            'extensions' => [['id' => 42, 'type' => 'gateway', 'extension' => 'StripeSaved',
                'name' => 'Stripe Saved Methods', 'enabled' => true, 'deleted_at' => null]],
            'billing_agreements' => [$row(81, 'pm_converted_old'), $row(82, 'pm_converted_new')],
            'services' => [['id' => 91, 'billing_agreement_id' => 82, 'auto_pay' => false]],
            'properties' => [['id' => 11, 'model_id' => 7, 'model_type' => User::class,
                'key' => 'stripe_saved_checkout_12', 'value' => '{"key":"original-checkout-key"}']],
        ];
        $before = DB::$tables;
        $calls = count(Http::$calls);
        $command = new ConvertFromStripeSavedCommand; $command->options = ['apply' => true];
        check($command->handle() === 0, 'Convert legacy saved cards before duplicate detection');
        check(DB::$tables['billing_agreements'] === $before['billing_agreements']
            && DB::$tables['services'] === $before['services'], 'Conversion preserves duplicate IDs, gateway ownership and service bindings');
        check(count(Http::$calls) === $calls, 'Conversion does not look up or detach saved cards');
        check(DB::$tables['properties'][0] === array_replace($before['properties'][0], ['key' => 'ae_stripe_checkout_12']),
            'Filtered conversion renames the checkout retry key without changing its value');
        Gateway::$rows = ['AeStripe' => [new Extension(DB::$tables['extensions'][0])]];
        Gateway::$bundled = true; Auth::$id = 7; BillingAgreement::$snapshot = null;
        [$returnClass, $returnMethod] = Route::$definitions['/extensions/stripe-saved/setup-agreement']['action'];
        $gateway = new $returnClass(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test']);
        $card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
            'allow_redisplay' => 'limited', 'card' => (object) ['fingerprint' => 'fp_converted',
                'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
        foreach (['unbound', 'bound', 'different fingerprint', 'consent override'] as $case) {
            User::$row = new User(new Properties('cus_owner'));
            User::$row->props->values['ae_stripe_checkout_12'] = DB::$tables['properties'][0]['value'];
            ExtensionHelper::$agreements = array_column($before['billing_agreements'], null, 'external_reference');
            DB::$tables['services'] = $case === 'bound' ? $before['services'] : [];
            $services = DB::$tables['services'];
            $methods = ['pm_converted_old' => $card('pm_converted_old'), 'pm_converted_new' => $card('pm_converted_new')];
            if ($case === 'different fingerprint') $methods['pm_converted_new']->card->fingerprint = 'fp_other';
            $setup = (object) ['id' => 'seti_converted_card', 'status' => 'succeeded', 'customer' => 'cus_owner',
                'payment_method' => 'pm_converted_new', 'latest_attempt' => null,
                'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1', 'stripe_saved_gateway' => '1',
                    'stripe_saved_setup_consent' => 'off_session_v1', 'stripe_saved_setup_consent_user' => '7',
                    'stripe_saved_setup_consent_at' => '1700000000']];
            if ($case === 'consent override') $setup->metadata->ae_stripe_setup_consent = '0';
            Http::$handler = function ($verb, $path, $data) use ($setup, &$methods) {
                if ($path === '/setup_intents/seti_converted_card') return $setup;
                foreach ($methods as $id => $method) {
                    if ($path === '/payment_methods/' . $id . '/detach') {
                        check(DB::$userLocked, 'Converted duplicate detach holds the owner lock');
                        $method->customer = null; return $method;
                    }
                    if ($path !== '/payment_methods/' . $id) continue;
                    if ($verb === 'post') {
                        check(DB::$userLocked, 'Converted card redisplay holds the owner lock');
                        $method->allow_redisplay = $data['allow_redisplay'];
                    }
                    return $method;
                }
                throw new RuntimeException('Unexpected converted card request: ' . $path);
            };
            $start = count(Http::$calls);
            check($gateway->webhook(eventRequest($setup, type: 'setup_intent.succeeded'))['status'] === 200,
                'Converted setup webhook accepts legacy metadata for ' . $case);
            $request = new Request(['setup_intent' => $setup->id]);
            if ($case === 'consent override') {
                rejects(fn () => $gateway->$returnMethod($request), 'HTTP 409');
                check(!array_filter(array_slice(Http::$calls, $start), fn ($call) => $call[0] !== 'get')
                    && ExtensionHelper::$agreements === array_column($before['billing_agreements'], null, 'external_reference'),
                    'Explicit current consent refusal wins over legacy consent before dedupe or redisplay');
                continue;
            }
            $result = $gateway->$returnMethod($request);
            $old = ExtensionHelper::$agreements['pm_converted_old'];
            $incoming = ExtensionHelper::$agreements['pm_converted_new'];
            check($old['id'] === 81 && $old['gateway_id'] === 42 && $old['deleted_at'] === null
                && $incoming['id'] === 82 && $incoming['gateway_id'] === 42,
                'Converted cards keep their original IDs and gateway after ' . $case);
            check(DB::$tables['services'] === $services
                && User::$row->props->values['ae_stripe_checkout_12'] === $before['properties'][0]['value'],
                'Duplicate handling preserves converted checkout retries and service preferences for ' . $case);
            if ($case === 'unbound') {
                check($result['message'] === 'This card is already saved.' && $incoming['deleted_at'] !== null
                    && $methods['pm_converted_new']->customer === null && $methods['pm_converted_old']->allow_redisplay === 'always',
                    'Legacy setup redirect resumes webhook dedupe of converted cards and preserves consent');
                $receipt = json_decode(User::$row->props->values['ae_stripe_duplicate_pm_converted_new']);
                check($receipt->gateway === 42 && $receipt->kept === 'pm_converted_old',
                    'Duplicate retry receipt uses the preserved converted gateway ID');
                $detaches = array_filter(array_slice(Http::$calls, $start), fn ($call) => str_ends_with($call[1], '/detach'));
                check(count($detaches) === 1, 'Converted webhook and legacy redirect detach a duplicate only once');
            } else {
                check($incoming['deleted_at'] === null && $methods['pm_converted_new']->customer === 'cus_owner'
                    && !array_filter(array_slice(Http::$calls, $start), fn ($call) => str_ends_with($call[1], '/detach')),
                    'Converted ' . $case . ' card remains saved despite matching last four digits and expiry');
            }
        }
    } finally {
        DB::$tables = $tables; Gateway::$rows = $gatewayRows; Gateway::$bundled = $bundled;
        User::$row = $user; ExtensionHelper::$agreements = $agreements; BillingAgreement::$snapshot = $snapshot;
        Http::$handler = $handler; Auth::$id = $auth;
    }
})();
