<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Servers\Domains\Services {
    // The real owner-scoped adapter supplies the domain calendar, not Stripe guesses.
    class NativePresenter {
        public static $domain = null, $summary = null;
        public function service($owner, $id) { return self::$domain; }
        public function domainSummaries($owner, $ids) { return [$ids[0] => self::$summary]; }
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
Http::$handler = function ($verb, $path) { if ($verb === 'get') return ['customer' => null]; throw new RuntimeException('Unexpected request'); };
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
