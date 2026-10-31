<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{Extension, Gateway, Properties};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

(function () {
    if (!\Illuminate\Support\Facades\Route::has('extensions.gateways.ae-stripe.webhook')) require __DIR__ . '/../extension/AeStripe/routes.php';
    $handler = Http::$handler; $rows = Gateway::$rows; $tables = DB::$tables; $bundled = Gateway::$bundledEnabled;
    $config = ['stripe_secret_key' => 'rk_test_original', 'stripe_publishable_key' => 'pk_test_original', 'stripe_webhook_secret' => 'whsec_test'];
    $extension = new Extension(['id' => 3, 'enabled' => true, 'changed' => true]);
    $extension->settingValues = new Properties(''); $extension->settingValues->values = $config;
    DB::$tables['extensions'] = [['id' => 3, 'deleted_at' => null]];
    Gateway::$rows = ['AeStripe' => [$extension]];
    $gateway = new AeStripe($config);
    $events = ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.processing',
        'setup_intent.succeeded', 'subscription_schedule.canceled', 'invoice.created', 'invoice.payment_succeeded', 'charge.updated', 'charge.succeeded'];
    $endpoint = (object) ['id' => 'we_existing', 'url' => route('extensions.gateways.ae-stripe.webhook'),
        'enabled_events' => $events, 'api_version' => '2025-07-30.basil'];
    $endpoints = [$endpoint]; $failure = null; $secondPage = false; $creationCache = []; $loseCreation = false;
    Http::$handler = function ($verb, $path, $data) use (&$endpoints, &$failure, &$secondPage, &$creationCache, &$loseCreation) {
        if ($failure === $verb) throw new RuntimeException('Synthetic provider detail must not be shown');
        if ($path === '/account') {
            $key = end(Http::$calls)[3]['Authorization'];
            return (object) ['id' => str_contains($key, 'otheraccount') ? 'acct_other' : 'acct_original'];
        }
        if ($path === '/webhook_endpoints' && $verb === 'get') {
            if ($secondPage && !isset($data['starting_after'])) return (object) ['data' => [(object) ['id' => 'we_unrelated', 'url' => 'https://example.invalid/other']], 'has_more' => true];
            return (object) ['data' => $endpoints, 'has_more' => false];
        }
        if ($path === '/webhook_endpoints' && $verb === 'post') {
            $key = end(Http::$calls)[3]['Idempotency-Key'];
            if (isset($creationCache[$key])) return $creationCache[$key];
            $created = json_decode(json_encode(['id' => 'we_new', 'secret' => 'whsec_created', 'created' => time()] + $data));
            $endpoints[] = $created; $creationCache[$key] = $created;
            if ($loseCreation) { $loseCreation = false; throw new \Illuminate\Http\Client\ConnectionException('Lost creation response'); }
            return $created;
        }
        if ($path === '/webhook_endpoints/we_existing' && $verb === 'post') {
            foreach ($data as $key => $value) $endpoints[0]->$key = $value;
            return $endpoints[0];
        }
        throw new RuntimeException('Unexpected continuity request');
    };
    try {
        $gateway->updated($extension);
        $stateKey = 'ae_stripe_webhook_continuity';
        $state = json_decode($extension->settingValues->values[$stateKey], true);
        check($state['account'] === 'acct_original' && $state['endpoint'] === 'we_existing', 'Existing signing secret is checked against the current Stripe account and endpoint');
        $before = count(Http::$calls); $secondPage = true;
        $extension->settingValues->values['stripe_secret_key'] = 'rk_test_replacement';
        $extension->settingValues->values['stripe_webhook_secret'] = 'whsec_rotated';
        $gateway->updated($extension);
        check($gateway->config('stripe_secret_key') === 'rk_test_replacement', 'A replacement key on the same account takes effect after verification');
        check(!array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[0] !== 'get'), 'A settings edit reuses a matching endpoint found on a later page without deleting it');
        $oldRequest = eventRequest((object) ['id' => 'evt_paid_inflight'], type: 'unhandled.event');
        check($gateway->webhook($oldRequest)['status'] === 200, 'A delivery signed before rotation remains verifiable');
        $freshGateway = new AeStripe($config); // A request that started before the edit.
        $body = $oldRequest->body; $at = time();
        $newRequest = new Request(body: $body, signature: 't=' . $at . ',v1=' . hash_hmac('sha256', $at . '.' . $body, 'whsec_rotated'));
        check($freshGateway->webhook($newRequest)['status'] === 200 && $freshGateway->config('stripe_secret_key') === 'rk_test_replacement', 'A request started before rotation refreshes the saved signing secrets');
        $state = json_decode($extension->settingValues->values[$stateKey], true); $deadline = $state['previous'][0]['until'];
        $gateway->updated($extension);
        check(json_decode($extension->settingValues->values[$stateKey], true)['previous'][0]['until'] === $deadline, 'An ordinary edit does not extend the old secret deadline');
        $state['previous'][0]['until'] = time() - 1; $extension->settingValues->values[$stateKey] = json_encode($state);
        check($gateway->webhook($oldRequest)['status'] === 400 && $gateway->webhook($newRequest)['status'] === 200, 'Expired old secrets stop authenticating while the current secret keeps working');
        foreach (['rk_test_otheraccount', 'rk_live_original'] as $invalidKey) {
            $extension->settingValues->values['stripe_secret_key'] = $invalidKey;
            try { $gateway->updated($extension); throw new RuntimeException('Expected refusal'); }
            catch (\App\Exceptions\DisplayException $e) {
                check(!str_contains($e->getMessage(), $invalidKey), 'Account or mode refusal never exposes an API key');
            }
            check($extension->settingValues->values['stripe_secret_key'] === 'rk_test_replacement', 'A refused credential edit restores the accepted key');
        }
        $extension->settingValues->values['stripe_webhook_secret'] = '';
        rejects(fn () => $gateway->updated($extension), "Copy this endpoint's signing secret from Stripe, then save again. Its existing deliveries have been preserved.");
        check($extension->settingValues->values['stripe_webhook_secret'] === 'whsec_rotated', 'Clearing the signing secret preserves the old accepted secret and endpoint');
        $endpoint->url = 'https://old.example.invalid/extensions/ae-stripe/webhook';
        $gateway->updated($extension);
        check($endpoint->url === route('extensions.gateways.ae-stripe.webhook'), 'A site URL change updates the same endpoint, keeping pending deliveries and its signing secret');
        $failure = 'get'; Gateway::$bundledEnabled = true;
        $extension->settingValues->values['stripe_secret_key'] = 'rk_test_failed';
        rejects(fn () => $gateway->enabled($extension), 'Stripe setup could not be verified. The previous credentials were kept. Check the API key permissions and save again.');
        check(Gateway::$bundledEnabled && $extension->settingValues->values['stripe_secret_key'] === 'rk_test_replacement', 'Failed verification preserves bundled Stripe and the existing key');
        $failure = null; $endpoints = []; $secondPage = false;
        $extension->settingValues->values = array_replace($config, ['stripe_webhook_secret' => '']);
        $gateway = new AeStripe(array_replace($config, ['stripe_webhook_secret' => '']));
        $before = count(Http::$calls); $loseCreation = true; Gateway::$bundledEnabled = true;
        rejects(fn () => $gateway->enabled($extension), 'Stripe setup could not be verified. The previous credentials were kept. Check the API key permissions and save again.');
        check(Gateway::$bundledEnabled, 'A lost webhook creation reply leaves bundled Stripe enabled');
        $gateway->updated($extension);
        check(count($endpoints) === 1, 'A lost creation reply recovers the original endpoint and secret without making a duplicate');
        check($extension->settingValues->values['stripe_webhook_secret'] === 'whsec_created', 'A fresh gateway creates one webhook and saves its signing secret');
        $creates = array_values(array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[0] === 'post'));
        check(count($creates) === 2 && !empty($creates[0][3]['Idempotency-Key']) && $creates[0][3]['Idempotency-Key'] === $creates[1][3]['Idempotency-Key'], 'Webhook creation uses a stable retry identity');
        check(!array_filter(Http::$calls, fn ($call) => $call[0] === 'delete'), 'No credential edit deletes a Stripe endpoint');
    } finally {
        Http::$handler = $handler; Gateway::$rows = $rows; DB::$tables = $tables; Gateway::$bundledEnabled = $bundled;
    }
})();
