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
foreach ([null, 'off_session_v1', 'off_session_v2', true] as $version) {
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
$gateway->saveAutoPayDefault(new Request($choice));
$record = $gateway->autoPayDefault($user);
check($record['version'] === AeStripe::AUTO_PAY_CONSENT && $record['user_id'] === 7
    && $record['agreement_id'] === 91 && is_int($record['accepted_at']), 'New account scope records authenticated consent, time and exact agreement');
check($existing->billing_agreement_id === null, 'Account opt-in leaves existing service opt-outs alone');
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
foreach (['1', 'true', json_encode(array_replace($record, ['version' => 'off_session_v1'])),
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
$gateway->saveAutoPayDefault(new Request($choice));
Gateway::$enabled = false;
rejects(fn () => $gateway->saveAutoPayDefault(new Request($choice)), 'HTTP 409');
$gateway->saveAutoPayDefault(new Request(['enabled' => false]));
Gateway::$enabled = true;
$service = $newService(); $created($service);
check($service->billing_agreement_id === null && $gateway->autoPayDefault($user) === null, 'Turning the default off only affects future services');
check($existing->billing_agreement_id === null && $enrolled->billing_agreement_id === 91 && count(ExtensionHelper::$agreements) === 1, 'Opt-ins and opt-outs do not change saved cards or old services');
check(count(Http::$calls) === $beforeCalls, 'Account consent and new-service assignment need no Stripe calls');

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
