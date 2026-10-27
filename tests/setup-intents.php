<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{Gateway, User};
use Illuminate\Support\Facades\{Auth, DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

$savedSetupState = [User::$row, Gateway::$enabled, Auth::$id, Http::$handler];
$setupUser = User::$row = new User(new \App\Models\Properties('cus_owner'));
Gateway::$enabled = true;
Auth::$id = $setupUser->id;
$setupGateway = new AeStripe([]);
$remoteSetups = [];
$setupKeys = [];
$loseSetupResponse = false;
Http::$handler = function ($verb, $path, $data) use (&$remoteSetups, &$setupKeys, &$loseSetupResponse, $setupUser) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
    if ($verb === 'post' && $path === '/setup_intents') {
        check(!DB::$userLocked, 'Setup creation does not hold the user lock during HTTP');
        $key = end(Http::$calls)[3]['Idempotency-Key'] ?? '';
        $setupKeys[] = $key;
        $id = 'seti_cycle_' . ($key === '' ? count($setupKeys) : (array_search($key, array_unique($setupKeys), true) + 1));
        $remoteSetups[$id] ??= (object) ['id' => $id, 'client_secret' => 'fixture-secret',
            'customer' => $data['customer'], 'status' => 'requires_payment_method', 'metadata' => (object) $data['metadata']];
        if ($loseSetupResponse) { $loseSetupResponse = false; throw new RuntimeException('Setup response lost'); }
        return $remoteSetups[$id];
    }
    if ($verb === 'get' && str_starts_with($path, '/setup_intents/')) return $remoteSetups[basename($path)];
    throw new RuntimeException('Unexpected setup request: ' . $path);
};
$firstSetup = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
for ($i = 0; $i < 8; $i++) {
    $reopened = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
    check($reopened->id === $firstSetup->id, 'Reopen reuses the unfinished SetupIntent after a decline');
}
check(count($remoteSetups) === 1 && count($setupKeys) === 1, 'Repeated opens create one SetupIntent');
check($setupKeys[0] !== '', 'Setup creation uses a persisted retry key');
$property = 'ae_stripe_setup';
$priorPayload = json_decode($setupUser->props->values[$property], true);
$priorPayload['digest'] = 'previous-release-payload';
$setupUser->props->values[$property] = json_encode($priorPayload);
check($setupGateway->createBillingAgreement($setupUser)['data']['intent']->id === $firstSetup->id,
    'A stored intent survives changes to creation-only metadata across upgrades');
foreach (['requires_confirmation', 'requires_action'] as $status) {
    $remoteSetups[$firstSetup->id]->status = $status;
    check($setupGateway->createBillingAgreement($setupUser)['data']['intent']->id === $firstSetup->id, 'Reuse setup status ' . $status);
}
$remoteSetups[$firstSetup->id]->status = 'processing';
rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'HTTP 409');
check(count($setupKeys) === 1, 'Processing setup cannot start a competing intent');
$remoteSetups[$firstSetup->id]->status = 'requires_payment_method';
foreach (['customer' => 'cus_other', 'user_id' => '99', 'is_billing_agreement' => '0', 'ae_stripe_gateway' => '0'] as $field => $bad) {
    $target = $field === 'customer' ? $remoteSetups[$firstSetup->id] : $remoteSetups[$firstSetup->id]->metadata;
    $original = $target->$field;
    $target->$field = $bad;
    rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'HTTP 409');
    $target->$field = $original;
}
foreach (['succeeded', 'canceled'] as $status) {
    $last = array_key_last($remoteSetups);
    $remoteSetups[$last]->status = $status;
    $next = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
    check($next->id !== $last, 'A terminal setup permits a new method after ' . $status);
}
// Interleave a second opener after the first has committed its creation key.
unset($setupUser->props->values[$property]);
$remoteBefore = count($remoteSetups);
$keysBefore = count($setupKeys);
$interleavedSetup = null;
DB::$afterTransaction = function () use ($setupGateway, $setupUser, &$interleavedSetup) {
    DB::$afterTransaction = function () use ($setupGateway, $setupUser, &$interleavedSetup) {
        $interleavedSetup = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
    };
};
$parallelSetup = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
check($parallelSetup->id === $interleavedSetup->id && count($remoteSetups) === $remoteBefore + 1,
    'Overlapping creation requests reuse one remote SetupIntent');
check(count($setupKeys) === $keysBefore + 2 && $setupKeys[$keysBefore] === end($setupKeys),
    'Overlapping requests send the same committed idempotency key');
$remoteSetups[$parallelSetup->id]->status = 'succeeded';
$remoteBefore = count($remoteSetups);
DB::$afterTransaction = function () use ($setupGateway, $setupUser, &$interleavedSetup) {
    DB::$afterTransaction = function () use ($setupGateway, $setupUser, &$interleavedSetup) {
        $interleavedSetup = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
    };
};
$parallelSetup = $setupGateway->createBillingAgreement($setupUser)['data']['intent'];
check($parallelSetup->id === $interleavedSetup->id && count($remoteSetups) === $remoteBefore + 1,
    'Concurrent terminal rotation preserves the newer unfinished attempt');
$normalHandler = Http::$handler;
Http::$handler = function ($verb, $path, $data) use ($normalHandler) {
    if (str_starts_with($path, '/setup_intents/')) throw new RuntimeException('Setup lookup failed');
    return $normalHandler($verb, $path, $data);
};
$before = count($setupKeys);
rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'Setup lookup failed');
check(count($setupKeys) === $before, 'Failed lookup cannot create a replacement setup');
Http::$handler = $normalHandler;
$storedBefore = $setupUser->props->values[$property];
$missingSetup = new \Illuminate\Http\Client\RequestException(404, ['error' => ['code' => 'resource_missing']]);
Http::$handler = function ($verb, $path, $data) use ($normalHandler, $missingSetup) {
    if (str_starts_with($path, '/setup_intents/')) throw $missingSetup;
    return $normalHandler($verb, $path, $data);
};
$before = count($setupKeys);
try {
    $setupGateway->createBillingAgreement($setupUser);
    throw new RuntimeException('Expected missing setup lookup to propagate');
} catch (\Illuminate\Http\Client\RequestException $error) {
    check($error === $missingSetup, 'Missing setup lookup requires reconciliation in its original account');
}
check(count($setupKeys) === $before && $setupUser->props->values[$property] === $storedBefore,
    'A 404 lookup retains the unresolved intent without creating a replacement');
Http::$handler = $normalHandler;
$storedBefore = $setupUser->props->values[$property];
$changedCustomer = clone $setupUser;
$changedCustomer->props = new \App\Models\Properties('cus_changed');
$changedCustomer->props->values[$property] = $storedBefore;
User::$row = $changedCustomer;
Http::$handler = function ($verb, $path, $data) use ($normalHandler) {
    if ($path === '/customers/cus_changed') return ['id' => 'cus_changed'];
    return $normalHandler($verb, $path, $data);
};
rejects(fn () => $setupGateway->createBillingAgreement($changedCustomer), 'HTTP 409');
check($changedCustomer->props->values[$property] === $storedBefore, 'Changed customer retains the unresolved setup for reconciliation');
$remoteSetups[$parallelSetup->id]->status = 'succeeded';
$changedCustomer->props->customer = 'cus_owner';
Http::$handler = function ($verb, $path, $data) use ($normalHandler) {
    if ($path === '/customers/cus_owner') return ['id' => 'cus_owner', 'deleted' => true];
    if ($path === '/customers' && $verb === 'post') return ['id' => 'cus_replacement'];
    return $normalHandler($verb, $path, $data);
};
$replacementSetup = $setupGateway->createBillingAgreement($changedCustomer)['data']['intent'];
check($replacementSetup->id !== $parallelSetup->id && $replacementSetup->customer === 'cus_replacement',
    'Replacing a deleted customer rotates the old terminal intent and permits account setup');
User::$row = $setupUser;
Http::$handler = $normalHandler;
unset($setupUser->props->values[$property]);
$loseSetupResponse = true;
rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'Setup response lost');
$lostKey = end($setupKeys);
$lostAttempt = $setupUser->props->values[$property];
$changedAttempt = json_decode($lostAttempt, true);
$changedAttempt['digest'] = 'different-unresolved-payload';
$setupUser->props->values[$property] = json_encode($changedAttempt);
$before = count($setupKeys);
rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'HTTP 409');
check(count($setupKeys) === $before, 'An unresolved key cannot replay changed creation parameters');
$setupUser->props->values[$property] = $lostAttempt;
$setupGateway->createBillingAgreement($setupUser);
check($lostKey === end($setupKeys), 'Lost setup response retries with the committed key');
$stored = json_decode($setupUser->props->values[$property], true);
unset($stored['intent']);
$stored['created'] = time() - 86400;
$setupUser->props->values[$property] = json_encode($stored);
$before = count($setupKeys);
rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'HTTP 409');
check(count($setupKeys) === $before, 'Expired unresolved creation requires reconciliation');
Auth::$id = 99;
$before = count(Http::$calls);
rejects(fn () => $setupGateway->createBillingAgreement($setupUser), 'HTTP 403');
check(count(Http::$calls) === $before, 'Foreign user cannot retrieve or create a setup');
[User::$row, Gateway::$enabled, Auth::$id, Http::$handler] = $savedSetupState;
