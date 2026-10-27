<?php
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, Properties, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{DB, Http};

$oldHandler = Http::$handler;
Gateway::$enabled = true;
Gateway::$rows = null;
Gateway::$bundled = true;
$user = User::$row = new User(new Properties('cus_owner'));
$invoice = Invoice::$row = new Invoice($user);
Invoice::$rows = [];
$fixtures = [
    'card' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'never-store']],
    'link' => ['type' => 'link', 'link' => ['email' => 'payer@example.com']],
    'bank' => ['type' => 'us_bank_account', 'us_bank_account' => ['last4' => '6789', 'fingerprint' => 'never-store']],
    'unknown' => ['type' => 'future_wallet'],
    'missing' => null,
    'missingtype' => ['card' => []],
    'emptytype' => ['type' => ''],
    'invalidtype' => ['type' => []],
    'incomplete' => ['type' => 'card', 'card' => ['brand' => 'visa']],
    'invalidlast4' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '12345']],
    'missingbank' => ['type' => 'us_bank_account'],
    'missingideal' => ['type' => 'ideal'],
    'missingbancontact' => ['type' => 'bancontact'],
    'missingsofort' => ['type' => 'sofort'],
    'invalidemail' => ['type' => 'link', 'link' => ['email' => 'invalid']],
    'oversized' => ['type' => str_repeat('w', 256)],
];
$expected = ['card' => 'Visa **** 4242', 'link' => 'Link · p***@example.com',
    'bank' => 'US Bank Account **** 6789', 'unknown' => 'Future wallet', 'missing' => null, 'missingtype' => null, 'emptytype' => null, 'invalidtype' => null, 'incomplete' => null,
    'invalidlast4' => null, 'missingbank' => null, 'missingideal' => null, 'missingbancontact' => null,
    'missingsofort' => null, 'invalidemail' => 'Link', 'oversized' => null];
foreach (['payment_intent.succeeded', 'payment_intent.processing'] as $eventType) {
    foreach ($fixtures as $name => $details) {
        $id = 'pi_label' . $name . ($eventType === 'payment_intent.processing' ? 'processing' : 'success');
        $methodIntent = (object) ['id' => $id, 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000,
            'customer' => 'cus_owner', 'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1'],
            'latest_charge' => (object) ['payment_method_details' => json_decode(json_encode($details))]];
        $before = count(Http::$calls);
        $gateway->webhook(eventRequest($methodIntent, type: $eventType));
        $gateway->webhook(eventRequest($methodIntent, type: $eventType));
        $rows = DB::$tables['ae_stripe_transaction_methods'] ?? [];
        $row = $rows[crc32('12:' . $id)] ?? null;
        check(($row['label'] ?? null) === $expected[$name], "$name label on $eventType");
        check($row === null || array_keys($row) === ['invoice_transaction_id', 'label'], 'Only display label and local ID stored');
        check(count(Http::$calls) === $before, 'Expanded event never retrieves a charge');
        check(!str_contains(json_encode($rows), 'never-store') && !str_contains(json_encode($rows), 'payer@'), 'No fingerprint or full Link email stored');
    }
}
$methodIntent->id = 'pi_retrieved';
$methodIntent->latest_charge = 'ch_fixture';
Http::$handler = function ($verb, $path) use ($fixtures) {
    check($verb === 'get' && $path === '/charges/ch_fixture', 'Only one charge retrieve');
    check(!DB::$locked, 'Optional method retrieval must not hold settlement locks');
    return ['payment_method_details' => $fixtures['card']];
};
$before = count(Http::$calls);
$gateway->webhook(eventRequest($methodIntent, type: 'payment_intent.processing'));
$gateway->webhook(eventRequest($methodIntent, type: 'payment_intent.processing'));
check(count(Http::$calls) === $before + 1, 'Processing replay with a stored label does not retrieve again');
$methodIntent->latest_charge = (object) ['payment_method_details' => (object) $fixtures['link']];
$gateway->webhook(eventRequest($methodIntent));
$gateway->webhook(eventRequest($methodIntent));
check(count(Http::$calls) === $before + 1, 'Unexpanded charge retrieves once across processing and success');
check(DB::$tables['ae_stripe_transaction_methods'][crc32('12:pi_retrieved')]['label'] === 'Visa **** 4242', 'First captured label survives success and replay');
$methodIntent->id = 'pi_failedlabel';
$methodIntent->latest_charge = 'ch_fixture';
$before = count(Http::$calls);
$gateway->webhook(eventRequest($methodIntent, type: 'payment_intent.payment_failed'));
check(count(Http::$calls) === $before && !isset(DB::$tables['ae_stripe_transaction_methods'][crc32('12:pi_failedlabel')]), 'Failed payment captures nothing');
// A PaymentIntent can fail and then reuse its transaction for another card.
$retryIntent = clone $methodIntent;
$retryIntent->id = 'pi_labelretry';
$retryIntent->latest_charge = (object) ['payment_method_details' => json_decode(json_encode($fixtures['card']))];
$retryId = crc32('12:pi_labelretry');
$gateway->webhook(eventRequest($retryIntent, type: 'payment_intent.processing'));
check(DB::$tables['ae_stripe_transaction_methods'][$retryId]['label'] === 'Visa **** 4242', 'Processing records the first attempt card');
$gateway->webhook(eventRequest($retryIntent, type: 'payment_intent.payment_failed'));
$gateway->webhook(eventRequest($retryIntent, type: 'payment_intent.payment_failed'));
check(!isset(DB::$tables['ae_stripe_transaction_methods'][$retryId]), 'Failure and its replay clear the failed attempt label');
$retryIntent->latest_charge = 'ch_retrycard';
Http::$handler = function ($verb, $path) {
    check($verb === 'get' && $path === '/charges/ch_retrycard' && !DB::$locked, 'Retry retrieves its new charge before locking');
    return ['payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '4444']]];
};
$gateway->webhook(eventRequest($retryIntent));
check(DB::$tables['ae_stripe_transaction_methods'][$retryId]['label'] === 'Mastercard **** 4444', 'Successful retry records its own card on the reused transaction');
$retryIntent->latest_charge = (object) ['payment_method_details' => json_decode(json_encode($fixtures['card']))];
$before = ExtensionHelper::$applications;
$gateway->webhook(eventRequest($retryIntent));
$gateway->webhook(eventRequest($retryIntent, type: 'payment_intent.payment_failed'));
check(DB::$tables['ae_stripe_transaction_methods'][$retryId]['label'] === 'Mastercard **** 4444', 'Success replay and late failure keep the first successful label');
check(ExtensionHelper::$applications === $before && ExtensionHelper::$payments['12:pi_labelretry']['status'] === 'succeeded', 'Replayed success and late failure never rewrite a settled transaction');
// Failure can clear the processing label after success prepares, before it locks.
$raceIntent = clone $retryIntent;
$raceIntent->id = 'pi_labelretryrace';
$raceId = crc32('12:pi_labelretryrace');
$gateway->webhook(eventRequest($raceIntent, type: 'payment_intent.processing'));
$raceIntent->latest_charge = 'ch_retrycard';
DB::$beforeUserLock = function () use ($gateway, $raceIntent) {
    $gateway->webhook(eventRequest($raceIntent, type: 'payment_intent.payment_failed'));
};
$gateway->webhook(eventRequest($raceIntent));
check((DB::$tables['ae_stripe_transaction_methods'][$raceId]['label'] ?? null) === 'Mastercard **** 4444', 'Success prepares its new card even when failure clears the old label while waiting for locks');
$gateway->webhook(eventRequest($raceIntent));
check((DB::$tables['ae_stripe_transaction_methods'][$raceId]['label'] ?? null) === 'Mastercard **** 4444', 'Concurrent retry retains its successful label on replay');
foreach ([new ConnectionException('offline'), new RequestException(404)] as $index => $error) {
    $methodIntent->id = 'pi_lookupfailure' . $index;
    Http::$handler = fn () => throw $error;
    $response = $gateway->webhook(eventRequest($methodIntent));
    check($response['status'] === 200, 'Optional method lookup failure still acknowledges settlement');
    check(ExtensionHelper::$payments['12:' . $methodIntent->id]['status'] === 'succeeded', 'Lookup failure preserves payment');
    check(!isset(DB::$tables['ae_stripe_transaction_methods'][crc32('12:' . $methodIntent->id)]), 'Lookup failure stores no invented label');
}
$methodIntent->id = 'pi_methodrace';
Http::$handler = fn () => ['payment_method_details' => $fixtures['card']];
$before = count(Http::$calls);
DB::$beforeUserLock = function () use ($invoice) { $invoice->currency_code = 'eur'; };
$gateway->webhook(eventRequest($methodIntent));
check(count(Http::$calls) === $before + 1 && !isset(ExtensionHelper::$payments['12:pi_methodrace'])
    && !isset(DB::$tables['ae_stripe_transaction_methods'][crc32('12:pi_methodrace')]), 'Locked currency guard refuses changes after optional lookup');
$invoice->currency_code = 'usd';
foreach (['customer' => 'cus_other', 'currency' => 'eur'] as $field => $badValue) {
    $refused = clone $methodIntent;
    $refused->id = 'pi_refused' . $field;
    $refused->$field = $badValue;
    $before = count(Http::$calls);
    $gateway->webhook(eventRequest($refused));
    check(count(Http::$calls) === $before && !isset(DB::$tables['ae_stripe_transaction_methods'][crc32('12:' . $refused->id)]), 'Refused ' . $field . ' cannot capture a label');
}
$missingCharge = clone $methodIntent;
$missingCharge->id = 'pi_missingcharge';
unset($missingCharge->latest_charge);
$before = count(Http::$calls);
$gateway->webhook(eventRequest($missingCharge));
check(count(Http::$calls) === $before && !isset(DB::$tables['ae_stripe_transaction_methods'][crc32('12:pi_missingcharge')]), 'Missing charge shows no label and performs no lookup');
$missingCharge->id = 'pi_invalidcharge';
$missingCharge->latest_charge = '../ch_untrusted';
$gateway->webhook(eventRequest($missingCharge));
check(count(Http::$calls) === $before && !isset(DB::$tables['ae_stripe_transaction_methods'][crc32('12:pi_invalidcharge')]), 'Invalid charge identifier never becomes an API path');
Http::$handler = $oldHandler;
