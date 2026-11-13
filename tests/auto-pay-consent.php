<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Servers\Domains\Services {
    // The real owner-scoped adapter supplies the domain calendar, not Stripe guesses.
    class NativePresenter {
        public static $domain = null, $summary = null, $summaryCalls = 0;
        public function service($owner, $id) { return self::$domain; }
        public function domainSummaries($owner, $ids) { self::$summaryCalls++; return [$ids[0] => self::$summary]; }
    }
}
namespace {
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Event, Http, Route};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;
use Paymenter\Extensions\Servers\Domains\Services\NativePresenter;

(function () use ($user, $gateway) {
$oldAgreements = ExtensionHelper::$agreements;
$oldSnapshot = BillingAgreement::$snapshot;
$oldProperties = $user->props->values;
$oldEnabled = Gateway::$enabled;
$oldBundled = Gateway::$bundled;
$oldBundledEnabled = Gateway::$bundledEnabled;
$oldService = Service::$row;
$oldAuth = Auth::$id;
Gateway::$enabled = true;
Gateway::$bundled = false;
Auth::$id = 7;
$gateway->boot();
$route = Route::$definitions['/extensions/ae-stripe/auto-pay-default'];
check($route['method'] === 'POST' && $route['middleware'] === ['web', 'auth']
    && !isset($route['withoutMiddleware']), 'Account default requires authenticated POST with normal CSRF protection');
$gatewayId = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
ExtensionHelper::$agreements = ['pm_default' => ['id' => 91, 'user_id' => 7, 'gateway_id' => $gatewayId,
    'external_reference' => 'pm_default', 'deleted_at' => null]];
$choice = ['enabled' => true, 'agreement_id' => 91, 'consent' => true, 'version' => AeStripe::AUTO_PAY_CONSENT];
$property = 'ae_stripe_auto_pay_default';
$existing = new Service;
$existing->subscription_id = null;
$existing->plan->type = 'recurring';
$beforeCalls = count(Http::$calls);
foreach ([null, 'off_session_v1', 'off_session_v2', 'auto_pay_new_services_v2', true] as $version) {
    rejects(fn () => $gateway->saveAutoPayDefault(new Request(array_replace($choice, ['version' => $version]))), 'HTTP 422');
}
rejects(fn () => $gateway->saveAutoPayDefault(new Request(array_replace($choice, ['consent' => false]))), 'HTTP 422');
Auth::$id = 99;
// User query must honor the authenticated identity, just as Laravel does.
rejects(fn () => $gateway->saveAutoPayDefault(new Request($choice)), 'Missing row');
Auth::$id = 7;
foreach (['user_id' => 8, 'gateway_id' => $gatewayId + 1, 'deleted_at' => '2026-10-30'] as $field => $value) {
    $saved = ExtensionHelper::$agreements['pm_default'][$field];
    ExtensionHelper::$agreements['pm_default'][$field] = $value;
    rejects(fn () => $gateway->saveAutoPayDefault(new Request($choice)), 'HTTP 403');
    ExtensionHelper::$agreements['pm_default'][$field] = $saved;
}
Service::$row = $existing;
// Bundled Stripe retains ownership until adoption, including existing service links.
$existing->billing_agreement_id = 55;
Gateway::$bundled = true; Gateway::$bundledEnabled = true;
$beforeProperties = $user->props->values;
rejects(fn () => $gateway->saveAutoPayDefault(new Request($choice)), 'HTTP 409');
check($existing->billing_agreement_id === 55 && $user->props->values === $beforeProperties,
    'Refused enrollment leaves existing gateway links, preferences and consent unchanged');
$gateway->saveAutoPayDefault(new Request(['agreement_id' => 91]));
check($gateway->autoPayCard($user) === 91 && $existing->billing_agreement_id === 55,
    'A preference-only save remains usable during gateway coexistence');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
check($existing->billing_agreement_id === null && $gateway->autoPayDefault($user) === null,
    'Off remains usable while bundled Stripe owns collection');
Gateway::$bundled = false; Gateway::$bundledEnabled = false;
$gateway->saveAutoPayDefault(new Request($choice));
$record = $gateway->autoPayDefault($user);
check($record['version'] === AeStripe::AUTO_PAY_CONSENT && $record['user_id'] === 7
    && $record['agreement_id'] === 91 && is_int($record['accepted_at']), 'New account scope records authenticated consent, time and exact agreement');
check($existing->billing_agreement_id === 91, 'Account switch links current recurring services to the chosen card');
// Off revokes charging consent without discarding the customer's card choice.
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
check($gateway->autoPayDefault($user) === null, 'Off still revokes account charging consent');
check($gateway->autoPayCard($user) === 91, 'Off keeps the explicitly chosen Auto Pay card for the next visit');
$gateway->saveAutoPayDefault(new Request($choice));
check($gateway->autoPayCard($user) === 91, 'Off then On keeps the same card');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
ExtensionHelper::$agreements['pm_selected'] = ['id' => 92, 'user_id' => 7, 'gateway_id' => $gatewayId,
    'external_reference' => 'pm_selected', 'deleted_at' => null];
$gateway->saveAutoPayDefault(new Request(['agreement_id' => 92]));
check($gateway->autoPayCard($user) === 92 && $gateway->autoPayDefault($user) === null
    && $existing->billing_agreement_id === null, 'Choosing an Auto Pay card while Off persists without enabling charges');
// Saving another method must not take over the chosen card.
ExtensionHelper::$agreements['pm_added'] = ['id' => 93, 'user_id' => 7, 'gateway_id' => $gatewayId,
    'external_reference' => 'pm_added', 'deleted_at' => null];
check($gateway->autoPayCard($user) === 92, 'A newly saved card cannot replace the explicit choice');
foreach (['user_id' => 8, 'gateway_id' => $gatewayId + 1, 'deleted_at' => '2026-11-07'] as $field => $value) {
    $saved = ExtensionHelper::$agreements['pm_added'][$field];
    ExtensionHelper::$agreements['pm_added'][$field] = $value;
    rejects(fn () => $gateway->saveAutoPayDefault(new Request(['agreement_id' => 93])), 'HTTP 403');
    check($gateway->autoPayCard($user) === 92, 'A refused selection preserves the current choice');
    ExtensionHelper::$agreements['pm_added'][$field] = $saved;
}
$gateway->saveAutoPayDefault(new Request($choice));
$gateway->saveAutoPayDefault(new Request(['agreement_id' => 92]));
check($gateway->autoPayDefault($user)['agreement_id'] === 91 && $existing->billing_agreement_id === 91,
    'A card preference alone cannot change existing charging consent');
unset(ExtensionHelper::$agreements['pm_selected'], ExtensionHelper::$agreements['pm_added']);
$gateway->saveAutoPayDefault(new Request($choice));

foreach (['subscription_id' => 'sub_existing', 'status' => Service::STATUS_CANCELLED, 'user_id' => 8] as $field => $value) {
    $kept = $existing->$field; $existing->$field = $value; $existing->billing_agreement_id = null;
    $gateway->saveAutoPayDefault(new Request($choice));
    check($existing->billing_agreement_id === null, 'Account switch skips a service by ' . $field);
    $existing->$field = $kept;
}
$existing->plan->type = 'onetime';
$gateway->saveAutoPayDefault(new Request($choice));
check($existing->billing_agreement_id === null, 'Account switch skips one-time services');
$existing->plan->type = 'recurring';
$newService = function () use ($user) {
    $service = new Service;
    $service->subscription_id = null;
    $service->plan->type = 'recurring';
    $service->user = $user;
    $service->product->server = null;
    Service::$row = $service;
    return $service;
};
$created = fn ($service) => Event::$listeners[\App\Events\Service\Created::class](new \App\Events\Service\Created($service));
$service = $newService();
$created($service);
check($service->billing_agreement_id === 91, 'New recurring service inherits the explicitly chosen account default');
$enrolled = $service;
foreach (['billing_agreement_id' => 55, 'subscription_id' => 'sub_existing', 'status' => Service::STATUS_CANCELLED] as $field => $value) {
    $service = $newService(); $service->$field = $value;
    $created($service);
    check($service->billing_agreement_id === ($field === 'billing_agreement_id' ? 55 : null), 'New default preserves service ' . $field);
}
$service = $newService(); $service->plan->type = 'onetime'; $created($service);
check($service->billing_agreement_id === null, 'One-time services do not acquire Auto Pay');
$service = $newService(); Gateway::$enabled = false; $created($service);
check($service->billing_agreement_id === null, 'Disabled gateway never enrolls a new service'); Gateway::$enabled = true;
$service = $newService(); Gateway::$bundled = true; Gateway::$bundledEnabled = true; $created($service);
check($service->billing_agreement_id === null, 'Bundled gateway ownership prevents default enrollment'); Gateway::$bundled = false; Gateway::$bundledEnabled = false;
// Service Updated does not inherit a default, including a later per-service opt-out.
$service = $newService();
Event::$listeners[\App\Events\Service\Updated::class](new \App\Events\Service\Updated($service));
check($service->billing_agreement_id === null, 'Updating an existing opted-out service cannot re-enable Auto Pay');
foreach (['1', 'true', json_encode(array_replace($record, ['version' => 'off_session_v1'])), json_encode(array_replace($record, ['version' => 'auto_pay_new_services_v2'])),
    json_encode(array_replace($record, ['user_id' => 99]))] as $legacy) {
    $user->props->values[$property] = $legacy;
    $service = $newService(); $created($service);
    check($service->billing_agreement_id === null, 'Legacy or foreign account data grants no new service scope');
}
$gateway->saveAutoPayDefault(new Request($choice));
BillingAgreement::$snapshot = ExtensionHelper::$agreements;
ExtensionHelper::$agreements['pm_default']['deleted_at'] = '2026-10-31';
$service = $newService(); $created($service);
check($service->billing_agreement_id === null, 'Current read refuses a removed card even when the transaction snapshot still has it');
BillingAgreement::$snapshot = null;
check($gateway->autoPayDefault($user) === null, 'Removed card makes the displayed default Off');
ExtensionHelper::$agreements['pm_default']['deleted_at'] = null;
DB::$beforeUserLock = function () use ($user, $property) { unset($user->props->values[$property]); };
$service = $newService(); $created($service);
check($service->billing_agreement_id === null, 'Opt-out committed before the user lock prevents new-service enrollment');
$current = $newService(); $current->billing_agreement_id = 55;
$gateway->saveAutoPayDefault(new Request($choice));
check($current->billing_agreement_id === 91, 'Turning Auto Pay on moves every current service to the account card');
Gateway::$enabled = false;
rejects(fn () => $gateway->saveAutoPayDefault(new Request($choice)), 'HTTP 409');
$retry = fn ($state) => json_encode(['key' => 'k', 'renewal' => ['state' => $state, 'agreement_id' => 91, 'retries' => 1]]);
$user->props->values['ae_stripe_checkout_18'] = $retry('waiting');
$user->props->values['ae_stripe_checkout_19'] = $retry('active');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
Gateway::$enabled = true;
check($current->billing_agreement_id === null, 'Turning Auto Pay off unlinks current services, even while the gateway is paused');
check(json_decode($user->props->values['ae_stripe_checkout_18'], true)['renewal']['state'] === 'stopped'
    && $user->props->values['ae_stripe_checkout_19'] === $retry('active'), 'Turning Auto Pay off stops a waiting renewal retry and leaves a charge in flight');
// A service linked before the account switch (no account record) is also unlinked by Off.
$legacy = $newService(); $legacy->billing_agreement_id = 91;
check($gateway->autoPayDefault($user) === null, 'Legacy link has no account record');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
check($legacy->billing_agreement_id === null, 'Off unlinks a service linked per service before the account switch');
// Turning it on never lets a waiting retry charge an open invoice: the confirm says open invoices are not charged.
$user->props->values['ae_stripe_checkout_18'] = $retry('waiting');
$gateway->saveAutoPayDefault(new Request($choice));
check(json_decode($user->props->values['ae_stripe_checkout_18'], true)['renewal']['state'] === 'stopped', 'Turning Auto Pay on stops a waiting renewal retry');
unset($user->props->values['ae_stripe_checkout_18'], $user->props->values['ae_stripe_checkout_19']);
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$service = $newService(); $created($service);
check($service->billing_agreement_id === null && $gateway->autoPayDefault($user) === null, 'Off enrolls no new service');
check($enrolled->billing_agreement_id === 91 && count(ExtensionHelper::$agreements) === 1, 'The switch never changes saved cards');
check(count(Http::$calls) === $beforeCalls, 'Account consent and new-service assignment need no Stripe calls');
// Removing the account card turns Auto Pay off and unlinks what it paid for.
$gateway->saveAutoPayDefault(new Request($choice));
Http::$handler = function ($verb, $path) { if ($verb === 'get') return ['id' => 'pm_default', 'customer' => null]; throw new RuntimeException('Unexpected request'); };
check($gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements['pm_default'])) === true
    && $current->billing_agreement_id === null && !isset($user->props->values[$property]), 'Removing the account card turns Auto Pay off');
check($gateway->autoPayCard($user) === null, 'Removing the chosen card clears its saved preference');
Http::$handler = null;

// Hosting dates follow the real native cron setting. A pending invoice is not a new automatic debit.
$service = $newService(); $service->expires_at = new \DateTimeImmutable('2026-12-15');
$dates = $gateway->autoPayDates($service);
check($dates['invoice_on'] === '2026-12-08' && $dates['charge_on'] === '2026-12-08'
    && $dates['state'] === 'scheduled', 'Hosting invoice and charge date use native seven-day default');
$GLOBALS['fakeConfig']['settings.cronjob_invoice'] = 10;
check($gateway->autoPayDates($service)['charge_on'] === '2026-12-05', 'Opt-in dates follow a changed native invoice offset');
unset($GLOBALS['fakeConfig']['settings.cronjob_invoice']);
$service->expires_at = new \DateTimeImmutable('2026-10-30');
$dates = $gateway->autoPayDates($service);
check($dates['state'] === 'next_billing_run' && $dates['charge_on'] === null, 'Overdue schedule never promises a charge in the past or a guessed run date');
$service->expires_at = null;
check($gateway->autoPayDates($service)['state'] === 'missing', 'Missing expiry remains missing');
$service->pendingInvoices = [(object) ['id' => 18, 'created_at' => new \DateTimeImmutable('2026-10-28')]];
$dates = $gateway->autoPayDates($service);
check($dates['state'] === 'existing_invoice' && $dates['invoice_on'] === '2026-10-28'
    && $dates['charge_on'] === null && $dates['invoice_id'] === 18, 'Existing invoice discloses its actual date without promising a debit from opt-in');
$service->pendingInvoices = [];
$service->product->server = (object) ['extension' => 'Domains'];
$service->expires_at = new \DateTimeImmutable('2026-12-15');
NativePresenter::$domain = (object) ['renewal' => 'On'];
NativePresenter::$summary = (object) ['renewalIssueAt' => '2026-11-24'];
$dates = $gateway->autoPayDates($service);
check($dates['invoice_on'] === '2026-11-24' && $dates['charge_on'] === '2026-11-24', 'Domain opt-ins use actual Domains issue calendar, never hosting offset or due date');
NativePresenter::$domain->renewalSchedule = ['expiry' => '2026-12-15', 'issue' => '2026-11-26', 'due' => '2026-12-08'];
$calls = NativePresenter::$summaryCalls;
$dates = $gateway->autoPayDates($service);
check($dates['invoice_on'] === '2026-11-26' && $dates['state'] === 'scheduled' && NativePresenter::$summaryCalls === $calls,
    'A domain whose owner record has its saved calendar skips the slow full summary');
NativePresenter::$domain->renewalSchedule = [];
check($gateway->autoPayDates($service)['invoice_on'] === '2026-11-24' && NativePresenter::$summaryCalls === $calls + 1,
    'An empty saved calendar still reads the summary, which keeps a past issue date');
unset(NativePresenter::$domain->renewalSchedule);
NativePresenter::$domain->renewNow = ['invoice' => 'https://paymenter.example.com/invoices/retained'];
$dates = $gateway->autoPayDates($service);
check($dates['state'] === 'existing_invoice' && $dates['invoice_url'] === NativePresenter::$domain->renewNow['invoice']
    && $dates['charge_on'] === null, 'Retained domain invoice stays payable from its exact link without a new debit promise');
NativePresenter::$domain->renewNow = [];
NativePresenter::$summary = null;
check($gateway->autoPayDates($service)['state'] === 'missing', 'Missing domain calendar does not fall back to hosting');
NativePresenter::$domain = null;
check($gateway->autoPayDates($service)['state'] === 'missing', 'An active domain with no owner DTO keeps a visible missing calendar');
NativePresenter::$domain = (object) ['renewal' => 'Off'];
check($gateway->autoPayDates($service)['state'] === 'not_scheduled', 'Stopped domain renewal has no promised charge');
$service->product->server = null;
$service->cancellation = (object) ['id' => 1];
check($gateway->autoPayDates($service)['state'] === 'not_scheduled', 'Cancellation removes future charge promise');
$service->cancellation = null;
$service->price = 0;
check($gateway->autoPayDates($service)['state'] === 'not_scheduled', 'Free service has no charge date');
$service->price = 10;
$invoiceDates = new Invoice($user);
$foreign = clone $service; $foreign->id = 42; $foreign->user_id = 99;
$invoiceDates->items = [(object) ['reference_type' => Service::class, 'reference' => $service],
    (object) ['reference_type' => Service::class, 'reference' => $foreign],
    (object) ['reference_type' => null, 'reference' => $service]];
check(array_keys($gateway->invoiceAutoPayDates($invoiceDates)) === [41], 'Invoice opt-in dates cover only owner-matching service lines, without invoice-level Auto Pay');
// Turn on and pay: the first ticked invoice is charged before Auto Pay turns on, so a card that declines leaves
// Auto Pay off and the dialog gets the reason; a paid first invoice turns it on and pays the rest as Auto Pay.
$payer = new class(['stripe_webhook_secret' => 'whsec_test', 'stripe_secret_key' => 'fake-secret']) extends AeStripe {
    public array $charges = [];
    public ?string $decline = null;
    public ?string $declineAutoPay = null;
    public bool $pending = false;
    public $during = null;
    public ?string $consent = null;
    public ?string $reject = null;
    public int $intents = 0;
    // Stripe's answer for a payment of this invoice, recorded the way the bank return and the webhook record it.
    public function settle(Invoice $invoice, string $status, string $method, ?string $consent, ?string $id = null)
    {
        $intent = (object) ['id' => $id ?? 'pi_fake_' . $invoice->id . '_' . ++$this->intents, 'customer' => 'cus_owner', 'status' => $status,
            'currency' => 'usd', 'amount' => 2000, 'amount_received' => $status === 'succeeded' ? 2000 : 0, 'payment_method' => $method,
            'latest_charge' => null, 'last_payment_error' => null,
            'metadata' => (object) (['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1'] + ($consent ? ['ae_stripe_auto_pay_consent' => $consent] : []))];
        Invoice::$rows[$invoice->id] = $invoice;
        \Closure::bind(fn () => $this->recordIntentPayment($intent, $status, false), $this, AeStripe::class)();
        if ($status === 'succeeded') $invoice->status = 'paid';
        return $intent;
    }
    public function chargeAccepted(Invoice $invoice, $amount, BillingAgreement $agreement, bool $requireAutoPay = false, ?string $autoPayConsent = null)
    {
        $this->charges[] = [$invoice->id, $amount, $agreement->id, $requireAutoPay];
        $this->consent = $autoPayConsent;
        if ($this->during) { $during = $this->during; $this->during = null; $during($invoice, $autoPayConsent); }
        if ($this->reject) throw new \App\Exceptions\DisplayException($this->reject);
        if ($this->decline) throw new \Paymenter\Extensions\Gateways\AeStripe\PaymentDeclinedException($this->decline);
        if ($this->pending && !$requireAutoPay) throw new \Paymenter\Extensions\Gateways\AeStripe\PaymentPendingException('Processing.');
        if ($this->declineAutoPay && $requireAutoPay) throw new \Paymenter\Extensions\Gateways\AeStripe\PaymentDeclinedException($this->declineAutoPay);
        if ($requireAutoPay) { $invoice->status = 'paid'; return true; }
        $this->settle($invoice, 'succeeded', $agreement->external_reference, $autoPayConsent);
        return true;
    }
    public function chargeAutoPayAccepted(Invoice $invoice, $amount, BillingAgreement $agreement)
    {
        return $this->chargeAccepted($invoice, $amount, $agreement, true);
    }
};
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
Service::$row = $existing; $existing->billing_agreement_id = 55;
ExtensionHelper::$agreements['pm_turn_on'] = ['id' => 94, 'user_id' => 7, 'gateway_id' => $gatewayId,
    'external_reference' => 'pm_turn_on', 'deleted_at' => null];
$card = BillingAgreement::where('id', 94)->firstOrFail();
$first = new Invoice($user); $first->id = 31;
$second = new Invoice($user); $second->id = 32;
$before = $user->props->values;
$payer->decline = 'Your bank declined this card. Try a different card, or contact your bank.';
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$first, '20.00'], [$second, '15.00']], AeStripe::AUTO_PAY_CONSENT);
check($result['enabled'] === false && $result['message'] === $payer->decline && $result['invoices'] === [['id' => 31, 'status' => 'pending']],
    'A declined first payment returns its reason and turns nothing on');
check($payer->autoPayDefault($user) === null && $existing->billing_agreement_id === 55 && $user->props->values === $before
    && $payer->charges === [[31, '20.00', 94, false]], 'A declined first payment leaves Auto Pay, service links and the other invoices untouched');
$payer->charges = [];
foreach ([['auto_pay_new_services_v2', true, false], [AeStripe::AUTO_PAY_CONSENT, false, false], [AeStripe::AUTO_PAY_CONSENT, true, true]] as [$version, $enabled, $bundled]) {
    Gateway::$enabled = $enabled; Gateway::$bundled = $bundled; Gateway::$bundledEnabled = $bundled;
    $result = $payer->turnOnAutoPayAndPay($user, $card, [[$first, '20.00']], $version);
    check($result['enabled'] === false && $payer->charges === [] && $payer->autoPayDefault($user) === null,
        'Old consent, a paused gateway or bundled Stripe charges nothing and turns nothing on');
}
Gateway::$enabled = true; Gateway::$bundled = false; Gateway::$bundledEnabled = false;
$payer->decline = null;
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$first, '20.00'], [$second, '15.00']], AeStripe::AUTO_PAY_CONSENT);
check($result['enabled'] === true && $result['message'] === null && $result['invoices'] === [['id' => 31, 'status' => 'paid'], ['id' => 32, 'status' => 'paid']],
    'A paid first invoice turns Auto Pay on and pays the rest');
check(($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94 && $existing->billing_agreement_id === 94
    && $payer->charges === [[31, '20.00', 94, false], [32, '15.00', 94, true]], 'Only invoices after the first are charged as Auto Pay payments');
$turnOn = function (int $id) use ($payer, $gateway, $user, $card, $existing) {
    $gateway->saveAutoPayDefault(new Request(['enabled' => false]));
    $existing->billing_agreement_id = 55; $payer->charges = [];
    $first = new Invoice($user); $first->id = $id; $second = new Invoice($user); $second->id = $id + 1;
    Invoice::$rows[$id] = $first;
    return $payer->turnOnAutoPayAndPay($user, $card, [[$first, '20.00'], [$second, '15.00']], AeStripe::AUTO_PAY_CONSENT);
};
$payer->pending = true;
$result = $turnOn(40);
$payer->pending = false;
check($result['enabled'] === false && $result['invoices'] === [['id' => 40, 'status' => 'processing']]
    && $payer->charges === [[40, '20.00', 94, false]] && $payer->autoPayDefault($user) === null && $existing->billing_agreement_id === 55,
    'A first payment still processing leaves Auto Pay off and charges nothing more');
$consentKey = fn (int $invoice) => 'ae_stripe_auto_pay_consent_' . $invoice;
check(json_decode($user->props->values[$consentKey(40)] ?? 'null', true)['state'] === 'charging' && is_string($payer->consent)
    && json_decode($user->props->values[$consentKey(40)], true)['id'] === $payer->consent, 'The consent is stored before the charge and its id goes with the payment');
$pendingConsent = $payer->consent;
$processing = new Invoice($user); $processing->id = 40;
$payer->settle($processing, 'succeeded', 'pm_other', null);
check($payer->autoPayDefault($user) === null && !isset($user->props->values[$consentKey(40)]),
    'Another payment settling the invoice ends the consent and turns nothing on');
$payer->pending = true; $result = $turnOn(47); $payer->pending = false;
$processing = new Invoice($user); $processing->id = 47;
$payer->settle($processing, 'succeeded', 'pm_turn_on', $payer->consent);
check(($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94 && $existing->billing_agreement_id === 94,
    'Auto Pay turns on once that exact processing payment succeeds with the card');
$payer->pending = true; $result = $turnOn(46); $payer->pending = false;
$processing = new Invoice($user); $processing->id = 46;
$failed = $payer->settle($processing, 'failed', 'pm_turn_on', $payer->consent);
$payer->settle($processing, 'succeeded', 'pm_turn_on', $payer->consent, $failed->id);
check($payer->autoPayDefault($user) === null && $existing->billing_agreement_id === 55,
    'A processing payment that fails ends its consent, so a later success of the same payment turns nothing on');
// Settlement that lands before the charge call returns: the consent already exists, so the webhook decides.
$gateway->saveAutoPayDefault(new Request(['enabled' => false])); $existing->billing_agreement_id = 55; $payer->charges = [];
$payer->pending = true;
$payer->during = function ($invoice, $consent) use ($payer) { $payer->settle($invoice, 'failed', 'pm_turn_on', $consent, 'pi_race_failed'); };
$early = new Invoice($user); $early->id = 48;
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$early, '20.00']], AeStripe::AUTO_PAY_CONSENT);
$payer->pending = false;
$payer->settle($early, 'succeeded', 'pm_turn_on', $payer->consent, 'pi_race_failed');
check($result['enabled'] === false && $payer->autoPayDefault($user) === null && $existing->billing_agreement_id === 55
    && !isset($user->props->values[$consentKey(48)]), 'A failure that settles before the charge returns leaves no consent to reuse');
$payer->pending = true;
$payer->during = function ($invoice, $consent) use ($payer) { $payer->settle($invoice, 'succeeded', 'pm_turn_on', $consent); };
$early = new Invoice($user); $early->id = 49; $after = new Invoice($user); $after->id = 51;
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$early, '20.00'], [$after, '15.00']], AeStripe::AUTO_PAY_CONSENT);
$payer->pending = false;
check($result['enabled'] === true && ($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94 && $existing->billing_agreement_id === 94
    && !isset($user->props->values[$consentKey(49)]), 'A success that settles before the charge returns turns Auto Pay on once');
// Another operation pays the invoice first: this charge is refused, and the invoice reads paid.
$gateway->saveAutoPayDefault(new Request(['enabled' => false])); $existing->billing_agreement_id = 55; $payer->charges = [];
$taken = new Invoice($user); $taken->id = 53; $later = new Invoice($user); $later->id = 54;
$payer->during = function ($invoice) use ($payer) { $payer->settle($invoice, 'succeeded', 'pm_elsewhere', null); };
$payer->reject = 'This invoice has no payment due. Refresh the invoice.';
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$taken, '20.00'], [$later, '15.00']], AeStripe::AUTO_PAY_CONSENT);
check($result['enabled'] === false && $result['invoices'] === [['id' => 53, 'status' => 'paid']] && $payer->autoPayDefault($user) === null
    && $existing->billing_agreement_id === 55 && $payer->charges === [[53, '20.00', 94, false]],
    'An invoice paid by another payment during this charge never turns Auto Pay on');
$taken = new Invoice($user); $taken->id = 55;
$payer->settle($taken, 'succeeded', 'pm_elsewhere', null);
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$taken, '20.00']], AeStripe::AUTO_PAY_CONSENT);
$payer->reject = null;
check($result['enabled'] === false && $payer->autoPayDefault($user) === null && $existing->billing_agreement_id === 55
    && !isset($user->props->values[$consentKey(55)]), 'An invoice already paid before the dialog was sent never turns Auto Pay on');
// The saved-method lock is busy when the payment settles: the paid consent waits for the scheduled sweep.
$onMails = fn () => count(array_filter(DB::$tables['ae_stripe_outbox'] ?? [],
    fn ($row) => $row['kind'] === 'ae_stripe_auto_pay_mail:on:customer' && $row['recipient_id'] == 7));
\Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-' . $user->id] = true;
$result = $turnOn(42);
check($result['enabled'] === false && $result['message'] === 'The payment went through. Auto Pay turns on in a few minutes.'
    && $result['invoices'] === [['id' => 42, 'status' => 'paid']] && $payer->charges === [[42, '20.00', 94, false]]
    && $payer->autoPayDefault($user) === null && $existing->billing_agreement_id === 55
    && (json_decode($user->props->values[$consentKey(42)] ?? 'null', true)['state'] ?? null) === 'paid',
    'A paid first invoice whose turn-on meets a busy lock keeps its paid consent, says when Auto Pay turns on and charges nothing more');
// Time passing: the consent's backoff is over.
$due = function (int $invoice) use ($user, $consentKey) {
    $consent = json_decode($user->props->values[$consentKey($invoice)], true);
    $user->props->values[$consentKey($invoice)] = json_encode(array_replace($consent, ['next_at' => 0]));
};
$sweptMails = $onMails();
$tries = json_decode($user->props->values[$consentKey(42)], true)['tries'];
$payer->retryAutoPayConsents();
check($tries >= 1 && json_decode($user->props->values[$consentKey(42)], true)['tries'] === $tries, 'The sweep waits out the backoff after a failed try');
$due(42);
$payer->retryAutoPayConsents();
check($payer->autoPayDefault($user) === null && json_decode($user->props->values[$consentKey(42)] ?? 'null', true)['state'] === 'paid',
    'A sweep while the lock is still busy changes nothing and keeps the paid consent');
unset(\Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-' . $user->id]);
$due(42);
$payer->retryAutoPayConsents();
check(($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94 && $existing->billing_agreement_id === 94
    && json_decode($user->props->values[$consentKey(42)], true)['state'] === 'enrolled' && $onMails() === $sweptMails + 1
    && $payer->charges === [[42, '20.00', 94, false]], 'After the lock clears, the sweep turns Auto Pay on, links services and queues one email, without a charge');
$payer->retryAutoPayConsents();
check($onMails() === $sweptMails + 1 && $payer->charges === [[42, '20.00', 94, false]], 'Another sweep adds no email and no charge');
// A process lost between settlement and enrollment leaves a paid consent; the sweep enrolls it, unless a choice came since.
$gateway->saveAutoPayDefault(new Request(['enabled' => false])); $existing->billing_agreement_id = 55;
$lost = ['id' => 'apc_lost', 'user_id' => 7, 'invoice_id' => 56, 'agreement_id' => 94, 'version' => AeStripe::AUTO_PAY_CONSENT,
    'accepted_at' => time(), 'generation' => \Closure::bind(fn () => $this->autoPayGeneration($user), $payer, AeStripe::class)(), 'state' => 'paid', 'intent' => 'pi_lost'];
$user->props->values[$consentKey(56)] = json_encode(array_replace($lost, ['generation' => $lost['generation'] - 1]));
$user->props->values[$consentKey(57)] = json_encode(array_replace($lost, ['id' => 'apc_lost_2', 'invoice_id' => 57]));
$sweptMails = $onMails();
$payer->retryAutoPayConsents();
check(json_decode($user->props->values[$consentKey(56)], true)['state'] === 'changed'
    && json_decode($user->props->values[$consentKey(57)], true)['state'] === 'enrolled'
    && ($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94 && $existing->billing_agreement_id === 94 && $onMails() === $sweptMails + 1,
    'The sweep enrolls a paid consent left by a lost process, and one from before a newer choice changes nothing');
unset($user->props->values[$consentKey(56)], $user->props->values[$consentKey(57)]);
// Recoverable errors never end a paid consent: database deadlocks while enrolling are retried with backoff, past
// ten tries, with one staff notice, and the next try that works turns Auto Pay on.
$outbox = fn (string $kind) => count(array_filter(DB::$tables['ae_stripe_outbox'] ?? [], fn ($row) => $row['kind'] === $kind));
$gateway->saveAutoPayDefault(new Request(['enabled' => false])); $existing->billing_agreement_id = 55; $payer->charges = [];
$deadlockConsent = array_replace($lost, ['id' => 'apc_deadlock', 'invoice_id' => 58, 'intent' => 'pi_deadlock',
    'generation' => \Closure::bind(fn () => $this->autoPayGeneration($user), $payer, AeStripe::class)()]);
$user->props->values[$consentKey(58)] = json_encode($deadlockConsent);
$sweptMails = $onMails();
for ($try = 1; $try <= 11; $try++) {
    DB::$beforeUserLock = fn () => throw new RuntimeException('SQLSTATE[40001]: Deadlock found when trying to get lock');
    $due(58);
    $payer->retryAutoPayConsents();
    DB::$beforeUserLock = null;
    $stored = json_decode($user->props->values[$consentKey(58)], true);
    if ($try === 9) check($stored['state'] === 'paid' && $outbox('ae_stripe_auto_pay_consent:retrying:staff') === 0, 'Nine deadlocks keep the consent paid without a staff notice yet');
    if ($try === 10) check($stored['state'] === 'paid' && $stored['tries'] === 10 && $stored['next_at'] > time()
        && $outbox('ae_stripe_auto_pay_consent:retrying:staff') === 1, 'The tenth deadlock keeps the consent paid and raises one staff notice');
}
check($stored['state'] === 'paid' && $stored['tries'] === 11 && $outbox('ae_stripe_auto_pay_consent:retrying:staff') === 1
    && $payer->autoPayDefault($user) === null, 'Retries continue past ten without a second staff notice');
$due(58);
$payer->retryAutoPayConsents();
check(json_decode($user->props->values[$consentKey(58)], true)['state'] === 'enrolled' && ($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94
    && $existing->billing_agreement_id === 94 && $onMails() === $sweptMails + 1 && $payer->charges === [],
    'Once the deadlocks clear, the sweep enrolls the consent with one email and no charge');
// A permanent error ends the consent: its card is no longer saved, so it fails with one customer email and one staff notice.
$gateway->saveAutoPayDefault(new Request(['enabled' => false])); $existing->billing_agreement_id = 55;
$gone = array_replace($deadlockConsent, ['id' => 'apc_gone', 'invoice_id' => 59, 'intent' => 'pi_gone', 'agreement_id' => 96,
    'generation' => \Closure::bind(fn () => $this->autoPayGeneration($user), $payer, AeStripe::class)()]);
$user->props->values[$consentKey(59)] = json_encode($gone);
$sweptMails = $onMails();
$payer->retryAutoPayConsents();
$payer->retryAutoPayConsents();
\Closure::bind(fn () => $this->enrollPaidAutoPayConsent(7, 'ae_stripe_auto_pay_consent_59', intentId: 'pi_gone'), $payer, AeStripe::class)();
$failedMail = array_values(array_filter(DB::$tables['ae_stripe_outbox'] ?? [], fn ($row) => $row['kind'] === 'ae_stripe_auto_pay_mail:failed:customer'));
check(json_decode($user->props->values[$consentKey(59)], true)['state'] === 'failed' && $payer->autoPayDefault($user) === null
    && $existing->billing_agreement_id === 55 && $onMails() === $sweptMails && $payer->charges === []
    && count($failedMail) === 1 && $failedMail[0]['recipient_id'] == 7 && $outbox('ae_stripe_auto_pay_consent:failed:staff') === 1,
    'A consent whose card is gone ends failed with one customer email and one staff notice, and later tries add nothing');
$failedBody = json_decode($failedMail[0]['payload'], true);
check(str_contains($failedBody['body'], 'went through') && str_contains($failedBody['body'], 'Auto Pay did not turn on')
    && $failedBody['link_label'] === 'Turn on Auto Pay', 'The failure email says the payment went through, that Auto Pay is not on, and links to turn it on');
// A newer choice wins over a failure: the customer turns Auto Pay on elsewhere, then explicitly off, then removes the
// consent's card. The waiting consent ends changed, with no customer email and no staff notice.
ExtensionHelper::$agreements['pm_superseded'] = ['id' => 97, 'user_id' => 7, 'gateway_id' => $gatewayId,
    'external_reference' => 'pm_superseded', 'deleted_at' => null];
$gateway->saveAutoPayDefault(new Request(['enabled' => false])); $existing->billing_agreement_id = 55;
$superseded = array_replace($deadlockConsent, ['id' => 'apc_superseded', 'invoice_id' => 60, 'intent' => 'pi_superseded', 'agreement_id' => 97,
    'generation' => \Closure::bind(fn () => $this->autoPayGeneration($user), $payer, AeStripe::class)()]);
$user->props->values[$consentKey(60)] = json_encode($superseded);
$gateway->saveAutoPayDefault(new Request(['agreement_id' => 94]));
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
unset(ExtensionHelper::$agreements['pm_superseded']);
$quiet = [$onMails(), $outbox('ae_stripe_auto_pay_mail:failed:customer'), $outbox('ae_stripe_auto_pay_consent:failed:staff'), $outbox('ae_stripe_auto_pay_consent:retrying:staff')];
$payer->retryAutoPayConsents();
\Closure::bind(fn () => $this->enrollPaidAutoPayConsent(7, 'ae_stripe_auto_pay_consent_60', intentId: 'pi_superseded'), $payer, AeStripe::class)();
check(json_decode($user->props->values[$consentKey(60)], true)['state'] === 'changed' && $payer->autoPayDefault($user) === null
    && [$onMails(), $outbox('ae_stripe_auto_pay_mail:failed:customer'), $outbox('ae_stripe_auto_pay_consent:failed:staff'), $outbox('ae_stripe_auto_pay_consent:retrying:staff')] === $quiet,
    'A newer Off, then removing the consent\'s card, ends the consent changed with no email and no staff notice');
unset($user->props->values[$consentKey(58)], $user->props->values[$consentKey(59)], $user->props->values[$consentKey(60)]);
$payer->declineAutoPay = 'Insufficient funds. Try a different card, or contact your bank.';
$result = $turnOn(44);
$payer->declineAutoPay = null;
check($result['enabled'] === true && $result['message'] === null
    && $result['invoices'] === [['id' => 44, 'status' => 'paid'], ['id' => 45, 'status' => 'pending', 'message' => 'Insufficient funds. Try a different card, or contact your bank.']]
    && ($payer->autoPayDefault($user)['agreement_id'] ?? null) === 94, 'A later invoice that declines keeps Auto Pay on and returns its own reason');
// The invoice page says when Auto Pay will not pay an open invoice.
$open = new Invoice($user); $open->id = 33;
check($payer->autoPayCoversInvoice($open) === false, 'With Auto Pay on, an open invoice with no planned renewal charge is not covered');
$user->props->values['ae_stripe_checkout_33'] = json_encode(['renewal' => ['state' => 'waiting']]);
check($payer->autoPayCoversInvoice($open) === true, 'A renewal retry still planned covers the invoice');
unset($user->props->values['ae_stripe_checkout_33']);
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
check($payer->autoPayCoversInvoice($open) === null, 'With Auto Pay off there is nothing to say');
// Every turn-on stores the current consent version and queues one "Auto Pay is on" email; off and a declined
// turn-on send none. Records accepted under the earlier words stay valid.
$mails = fn () => array_values(array_filter(DB::$tables['ae_stripe_outbox'] ?? [],
    fn ($row) => $row['kind'] === 'ae_stripe_auto_pay_mail:on:customer' && $row['recipient_id'] == 7));
$mailCount = count($mails());
$choice = array_replace($choice, ['agreement_id' => 94]);
ExtensionHelper::$agreements['pm_elsewhere'] = ['id' => 95, 'user_id' => 7, 'gateway_id' => $gatewayId,
    'external_reference' => 'pm_elsewhere', 'deleted_at' => null];
check($gateway->autoPayOffered($user) === true, 'The pay dialog offers Auto Pay while it is off');
$linked = Service::$row; $saved = [$linked->billing_agreement_id, $linked->status, $linked->plan->type, $linked->subscription_id, $linked->user_id];
[$linked->billing_agreement_id, $linked->status, $linked->plan->type, $linked->subscription_id, $linked->user_id] = [95, 'active', 'recurring', null, 7];
check($gateway->autoPayOffered($user) === false, 'A renewing service linked to a saved card here counts as Auto Pay on, so no box');
check($payer->autoPayCoversInvoice($open) === false, 'Services linked one by one still get the invoice reason line');
$linked->status = Service::STATUS_CANCELLED;
check($gateway->autoPayOffered($user) === true, 'A cancelled linked service leaves Auto Pay off');
[$linked->billing_agreement_id, $linked->status, $linked->plan->type, $linked->subscription_id, $linked->user_id] = $saved;
$gateway->saveAutoPayDefault(new Request($choice));
check(($gateway->autoPayDefault($user)['version'] ?? null) === 'auto_pay_account_v2' && count($mails()) === $mailCount + 1
    && json_decode($mails()[$mailCount]['payload'], true)['user_id'] === 7, 'The switch stores the v2 record and queues one Auto Pay is on email');
check($gateway->autoPayOffered($user) === false, 'The pay dialog offers no Auto Pay box while it is on');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
check(count($mails()) === $mailCount + 1, 'Turning Auto Pay off sends no email');
$user->props->values['ae_stripe_auto_pay_default'] = json_encode(['version' => 'auto_pay_account_v1', 'user_id' => 7,
    'accepted_at' => 1794071700, 'agreement_id' => 94]);
check(($gateway->autoPayDefault($user)['agreement_id'] ?? null) === 94, 'A record accepted under the v1 words stays valid');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$payer->decline = 'Your bank declined this card. Try a different card, or contact your bank.';
$declined = new Invoice($user); $declined->id = 52;
$payer->turnOnAutoPayAndPay($user, $card, [[$declined, '20.00']], AeStripe::AUTO_PAY_CONSENT);
$payer->decline = null;
check($payer->autoPayDefault($user) === null && count($mails()) === $mailCount + 1, 'A declined turn-on and pay sends no email');
$turnOn(50);
check(count($mails()) === $mailCount + 2, 'A paid turn-on and pay queues one email');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));

// The pay dialog's box for a new card: Auto Pay turns on only once the payment succeeded and its card was saved, once.
// The metadata the pay dialog's confirm puts on the PaymentIntent when Auto Pay is ticked.
$dialogConsent = fn () => \Closure::bind(fn () => $this->autoPayIntentConsent(\App\Models\User::where('id', 7)->firstOrFail(), true), $gateway, AeStripe::class)();
check($dialogConsent()['ae_stripe_auto_pay'] === AeStripe::AUTO_PAY_CONSENT && \Closure::bind(fn () => $this->autoPayIntentConsent(\App\Models\User::where('id', 7)->firstOrFail(), false), $gateway, AeStripe::class)()
    === ['ae_stripe_auto_pay' => '', 'ae_stripe_auto_pay_at' => '', 'ae_stripe_auto_pay_gen' => ''], 'The pay dialog records Auto Pay consent only when ticked');
$paidIntent = fn (string $status, string $id = 'pi_auto_pay') => (object) ['id' => $id, 'status' => $status,
    'payment_method' => 'pm_turn_on', 'metadata' => (object) array_replace(['invoice_id' => 61, 'ae_stripe_gateway' => '1',
        'ae_stripe_consent' => '1'], $dialogConsent(), ['ae_stripe_auto_pay_at' => '1794071800'])];
$dialogInvoice = new Invoice($user); $dialogInvoice->id = 61; Invoice::$rows[61] = $dialogInvoice;
$user->props->values['ae_stripe_saved_' . hash('sha256', 'pi_auto_pay:pm_turn_on')] = json_encode(['incoming' => 'pm_turn_on', 'reference' => 'pm_turn_on']);
$afterPay = fn ($intent) => (fn () => $this->turnOnPaidAutoPay($intent))->call($gateway);
$afterPay($paidIntent('requires_payment_method'));
check($gateway->autoPayDefault($user) === null && count($mails()) === $mailCount + 2, 'A payment that did not succeed leaves Auto Pay off');
$afterPay($paidIntent('succeeded'));
$record = $gateway->autoPayDefault($user);
check(($record['agreement_id'] ?? null) === 94 && $record['version'] === AeStripe::AUTO_PAY_CONSENT && $record['accepted_at'] === 1794071800
    && count($mails()) === $mailCount + 3, 'A succeeded payment turns Auto Pay on with the saved card, the version and time shown, and one email');
$afterPay($paidIntent('succeeded'));
check(count($mails()) === $mailCount + 3, 'The bank return and the webhook for the same payment add nothing');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$afterPay($paidIntent('succeeded'));
check($gateway->autoPayDefault($user) === null, 'A late webhook never undoes a later Off');
$gateway->saveAutoPayDefault(new Request(array_replace($choice, ['agreement_id' => 95])));
$user->props->values['ae_stripe_saved_' . hash('sha256', 'pi_auto_pay_2:pm_turn_on')] = json_encode(['incoming' => 'pm_turn_on', 'reference' => 'pm_turn_on']);
$mailCount = count($mails());
$afterPay($paidIntent('succeeded', 'pi_auto_pay_2'));
check(($gateway->autoPayDefault($user)['agreement_id'] ?? null) === 95 && count($mails()) === $mailCount,
    'Auto Pay turned on elsewhere since the dialog opened keeps its own card');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
unset(Invoice::$rows[61]);
// The dialog refuses Auto Pay before any charge when the card is not being saved, or when Auto Pay is already on.
$confirmIntent = (object) ['id' => 'pi_confirm', 'customer' => 'cus_owner', 'status' => 'requires_payment_method', 'amount' => 2000,
    'amount_received' => 0, 'currency' => 'usd', 'confirmation_method' => 'automatic', 'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1']];
$priorHandler = Http::$handler;
Http::$handler = fn () => $confirmIntent;
$confirm = fn (array $extra) => $gateway->confirmPayment(new Request(array_replace(['intent' => 'pi_confirm',
    'confirmation_token' => 'ctoken_fixture', 'amount' => 2000, 'save' => true, 'auto_pay' => true], $extra)));
$sent = fn () => count(array_filter(Http::$calls, fn ($call) => $call[0] === 'post' && str_contains($call[1], 'pi_confirm')));
$calls = $sent();
check($confirm(['save' => false])['status'] === 409 && $sent() === $calls, 'Auto Pay without saving the card is refused before any charge');
$gateway->saveAutoPayDefault(new Request($choice));
check($confirm([])['status'] === 409 && $sent() === $calls, 'Auto Pay already on is refused before any charge');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
Http::$handler = $priorHandler;

// A first new-card payment confirmed before a later On and Off cannot turn Auto Pay back on when it lands late.
$dialogInvoice = new Invoice($user); $dialogInvoice->id = 61; Invoice::$rows[61] = $dialogInvoice;
$user->props->values['ae_stripe_saved_' . hash('sha256', 'pi_late:pm_turn_on')] = json_encode(['incoming' => 'pm_turn_on', 'reference' => 'pm_turn_on']);
$late = $paidIntent('succeeded', 'pi_late');
$gateway->saveAutoPayDefault(new Request($choice));
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$afterPay($late);
check($gateway->autoPayDefault($user) === null && $existing->billing_agreement_id === null, 'A delayed new-card payment never undoes a later On and Off');
$late = $paidIntent('succeeded', 'pi_late_off');
$user->props->values['ae_stripe_saved_' . hash('sha256', 'pi_late_off:pm_turn_on')] = json_encode(['incoming' => 'pm_turn_on', 'reference' => 'pm_turn_on']);
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$afterPay($late);
check($gateway->autoPayDefault($user) === null, 'An Off accepted while the payment was in flight wins');
// The saved-method lock is busy when the new card's payment is recorded: the choice is kept as a paid consent, and
// the scheduled sweep turns Auto Pay on later, without another charge or browser return.
$busyInvoice = new Invoice($user); $busyInvoice->id = 65; Invoice::$rows[65] = $busyInvoice;
$busy = $paidIntent('succeeded', 'pi_busy'); $busy->metadata->invoice_id = 65;
$user->props->values['ae_stripe_saved_' . hash('sha256', 'pi_busy:pm_turn_on')] = json_encode(['incoming' => 'pm_turn_on', 'reference' => 'pm_turn_on']);
$mailCount = count($mails());
\Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-' . $user->id] = true;
$afterPay($busy);
check($gateway->autoPayDefault($user) === null && (json_decode($user->props->values[$consentKey(65)] ?? 'null', true)['state'] ?? null) === 'paid',
    'A new-card payment whose turn-on meets a busy lock keeps its Auto Pay choice as a paid consent');
unset(\Illuminate\Support\Facades\Cache::$locks['ae-stripe-save-method-' . $user->id]);
$due(65);
$gateway->retryAutoPayConsents();
check(($gateway->autoPayDefault($user)['agreement_id'] ?? null) === 94 && count($mails()) === $mailCount + 1
    && json_decode($user->props->values[$consentKey(65)], true)['state'] === 'enrolled', 'The sweep then turns Auto Pay on with one email');
$afterPay($busy);
check(count($mails()) === $mailCount + 1, 'A later webhook for that payment adds nothing');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
unset(Invoice::$rows[65], $user->props->values[$consentKey(65)]);
unset(Invoice::$rows[61]);
// A saved-card turn-on and pay keeps any Auto Pay choice made after its dialog opened.
$elsewhere = array_replace($choice, ['agreement_id' => 95]);
$gateway->saveAutoPayDefault(new Request($elsewhere));
$payer->charges = []; $existing->billing_agreement_id = 95;
$stale = new Invoice($user); $stale->id = 70;
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$stale, '20.00']], AeStripe::AUTO_PAY_CONSENT);
check($result['enabled'] === false && $payer->charges === [] && ($payer->autoPayDefault($user)['agreement_id'] ?? null) === 95
    && $existing->billing_agreement_id === 95, 'A turn-on and pay sent after Auto Pay turned on elsewhere is refused before any charge');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$existing->billing_agreement_id = 55;
$payer->during = fn () => $gateway->saveAutoPayDefault(new Request($elsewhere));
$a = new Invoice($user); $a->id = 71; $b = new Invoice($user); $b->id = 72;
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$a, '20.00'], [$b, '15.00']], AeStripe::AUTO_PAY_CONSENT);
check($result['enabled'] === false && ($payer->autoPayDefault($user)['agreement_id'] ?? null) === 95 && $existing->billing_agreement_id === 95
    && $payer->charges === [[71, '20.00', 94, false]], 'Auto Pay turned on with another card during the payment keeps that card');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$existing->billing_agreement_id = 55; $payer->charges = [];
$payer->during = function () use ($gateway, $elsewhere) {
    $gateway->saveAutoPayDefault(new Request($elsewhere));
    $gateway->saveAutoPayDefault(new Request(['enabled' => false]));
};
$a->status = 'pending'; $a->id = 73;
$result = $payer->turnOnAutoPayAndPay($user, $card, [[$a, '20.00']], AeStripe::AUTO_PAY_CONSENT);
check($result['enabled'] === false && $payer->autoPayDefault($user) === null && $existing->billing_agreement_id === null,
    'An On then Off during the payment leaves Auto Pay off');
// One Off-to-On sends one email, even when the switch is sent again.
$mailCount = count($mails());
$gateway->saveAutoPayDefault(new Request($choice));
$gateway->saveAutoPayDefault(new Request($choice));
check(count($mails()) === $mailCount + 1 && ($gateway->autoPayDefault($user)['agreement_id'] ?? null) === 94,
    'Sending the same turn-on again sends no second email');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
$gateway->saveAutoPayDefault(new Request($choice));
check(count($mails()) === $mailCount + 2, 'A new turn-on after Off sends its own email');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));

ExtensionHelper::$agreements = $oldAgreements;
BillingAgreement::$snapshot = $oldSnapshot;
$user->props->values = $oldProperties;
Gateway::$enabled = $oldEnabled;
Gateway::$bundled = $oldBundled;
Gateway::$bundledEnabled = $oldBundledEnabled;
Service::$row = $oldService;
Auth::$id = $oldAuth;
})();
}
