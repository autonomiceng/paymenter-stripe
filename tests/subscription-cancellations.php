<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native events and scheduled recovery use real models, transactions, cache locks and HTTP fixtures.
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Models\{Gateway, Service, ServiceCancellation};
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\{Carbon, Sleep};
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

function subscriptionWorker(): AeStripe
{
    return new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test', 'stripe_use_subscriptions' => true]);
}
function cancellationService(string $id, array $invoices = []): Service
{
    $service = nativeService('sub_' . $id, $invoices);
    $service->properties()->create(['key' => 'has_stripe_subscription', 'value' => '1']);
    return $service->fresh();
}
function endOfPeriod(Service $service): void
{
    ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Fixture']);
}
function cancellationCleared(Service $service): bool
{
    return $service->fresh()->subscription_id === null && !$service->properties()->where('key', 'has_stripe_subscription')->exists();
}
function cancellationRetained(Service $service): bool
{
    return $service->fresh()->subscription_id === $service->subscription_id
        && $service->properties()->where('key', 'has_stripe_subscription')->first()?->value === '1';
}
function subscriptionDraft(Service $service): object
{
    return (object) ['id' => 'in_' . $service->id, 'status' => 'draft', 'currency' => 'usd', 'metadata' => (object) ['ae_stripe_gateway' => '1'],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) [
            'subscription' => $service->subscription_id, 'metadata' => (object) ['ae_stripe_gateway' => '1']]]];
}
function subscriptionWrites(): array
{
    return array_values(array_filter($GLOBALS['stripeCalls'], fn ($call) => $call[0] === 'post'));
}
function recoverCancellations(): void
{
    $before = count(app(Schedule::class)->events());
    subscriptionWorker()->boot();
    $events = array_values(array_filter(array_slice(app(Schedule::class)->events(), $before),
        fn ($event) => $event->description === 'ae-stripe:subscription-cancellations'));
    check(count($events) === 1, 'A fresh gateway registers subscription cancellation recovery');
    $event = $events[0];
    check($event->expression === '*/5 * * * *' && !$event->onOneServer && !$event->withoutOverlapping,
        'Cancellation recovery has no shared-cache scheduling prerequisite');
    $event->run(app());
}

$cases = [];
$cases['subscription-active'] = function () {
    $service = cancellationService('active', [nativeInvoice(800)]);
    $draft = subscriptionDraft($service);
    $GLOBALS['stripeHandler'] = function ($verb) use ($draft) {
        check(DB::transactionLevel() === 0, 'Subscription operation HTTP runs outside every transaction');
        return $verb === 'get' ? $draft : [];
    };
    subscriptionWorker()->webhook(eventRequest($draft, type: 'invoice.created'));
    $path = '/invoices/' . $draft->id;
    check(array_column(subscriptionWrites(), 1) === [$path, $path . '/finalize', $path . '/pay'],
        'An active subscription still binds, finalizes and pays in order');
};
foreach (['updated', 'created'] as $event) foreach (['commit', 'rollback'] as $outcome) {
    $cases[$event . '-' . $outcome] = function () use ($event, $outcome) {
        $service = cancellationService($event . '_' . $outcome);
        DB::beginTransaction();
        if ($event === 'updated') $service->update(['status' => Service::STATUS_CANCELLED]); else endOfPeriod($service);
        check(!$GLOBALS['stripeCalls'], 'Native ' . $event . ' cancellation makes no HTTP before commit');
        if ($outcome === 'commit') {
            DB::commit();
            check(cancellationCleared($service) && count($GLOBALS['stripeCalls']) === 1, 'Commit deletes and clears the saved subscription');
        } else {
            DB::rollBack();
            check(!$GLOBALS['stripeCalls'] && cancellationRetained($service), 'Rollback makes no deletion and keeps the subscription');
            recoverCancellations();
            check(!$GLOBALS['stripeCalls'], 'Rolled-back cancellation cannot reappear in recovery');
        }
    };
}
$cases['contention-recovery'] = function () {
    $service = cancellationService('contention', [nativeInvoice(801)]);
    $draft = subscriptionDraft($service);
    $lease = Cache::lock('ae-stripe-provider-subscription:7', 600);
    check($lease->get(), 'A competing worker holds the database provider lock');
    try {
        endOfPeriod($service);
        check(!$GLOBALS['stripeCalls'] && cancellationRetained($service) && $service->fresh()->status === 'active',
            'Contended event leaves the active end-of-period cancellation durable');
    } finally { $lease->release(); }
    $GLOBALS['stripeHandler'] = fn ($verb, $path) => $verb === 'get' ? $draft : ['id' => $service->subscription_id, 'status' => 'canceled'];
    subscriptionWorker()->webhook(eventRequest($draft, type: 'invoice.created'));
    check(!subscriptionWrites(), 'Pending end-of-period cancellation prevents invoice writes before recovery');
    recoverCancellations();
    check(cancellationCleared($service), 'Registered recovery cancels after the competing lease releases');
    subscriptionWorker()->webhook(eventRequest($draft, type: 'invoice.created'));
    check(!subscriptionWrites(), 'No later finalization or payment follows confirmed cancellation');
};
$cases['lost-callback-recovery'] = function () {
    foreach (['status', 'relation'] as $state) {
        $service = cancellationService('lost_' . $state);
        // The process committed native state but died before its after-commit callback could run.
        if ($state === 'status') Service::withoutEvents(fn () => $service->update(['status' => Service::STATUS_CANCELLED]));
        else ServiceCancellation::withoutEvents(fn () => endOfPeriod($service));
    }
    // Neither an unmarked subscription nor an active service with no request belongs in recovery.
    $unmarked = nativeService('sub_unmarked');
    Service::withoutEvents(fn () => $unmarked->update(['status' => Service::STATUS_CANCELLED]));
    $active = cancellationService('still_active');
    recoverCancellations();
    check(count($GLOBALS['stripeCalls']) === 2 && cancellationCleared($service), 'Fresh recovery finds both kinds of saved cancellation without an event');
    check($unmarked->fresh()->subscription_id === 'sub_unmarked' && cancellationRetained($active), 'Recovery selects only marked pending cancellations');
};
$cases['file-recovery'] = function () {
    foreach (['file', 'array'] as $driver) {
        config(['cache.default' => $driver]);
        $service = cancellationService($driver, [nativeInvoice($driver === 'file' ? 802 : 803)]);
        $draft = subscriptionDraft($service);
        $before = count($GLOBALS['stripeCalls']);
        rejects(fn () => subscriptionWorker()->webhook(eventRequest($draft, type: 'invoice.created')),
            'Stripe updates need a shared cache store. Contact support.');
        check(count($GLOBALS['stripeCalls']) === $before, 'Local cache refuses finalization before any HTTP');
        $GLOBALS['stripeHandler'] = fn () => throw new ConnectionException('Fixture outage');
        endOfPeriod($service);
        check(cancellationRetained($service), 'Local-cache event preserves unconfirmed cancellation');
        $GLOBALS['stripeHandler'] = fn ($verb, $path) => ['id' => basename($path), 'status' => 'canceled'];
        recoverCancellations();
        check(cancellationCleared($service), 'Registered cancellation recovery runs with ' . $driver . ' cache');
        subscriptionWorker()->webhook(eventRequest($draft, type: 'invoice.created'));
        check(!subscriptionWrites(), 'Local-cache deletion is never followed by a finalize or pay');
    }
};
$cases['lost-delete-response'] = function () {
    $service = cancellationService('lost_response');
    $deleted = false;
    $GLOBALS['stripeHandler'] = function ($verb) use (&$deleted) {
        if ($verb === 'delete') $deleted = true;
        throw new ConnectionException('Lost response');
    };
    endOfPeriod($service);
    check($deleted && cancellationRetained($service), 'Lost deletion and confirmation responses keep both local fields');
    $GLOBALS['stripeHandler'] = function ($verb, $path) {
        if ($verb === 'delete') throw new StripeFixtureError(404, ['error' => ['code' => 'resource_missing']]);
        return ['id' => basename($path), 'status' => 'canceled'];
    };
    $GLOBALS['stripeCalls'] = [];
    recoverCancellations();
    check(cancellationCleared($service) && array_column($GLOBALS['stripeCalls'], 0) === ['delete', 'get'],
        'Repeated DELETE error clears only after retrieving the same cancelled subscription');
};
$cases['unconfirmed-batch'] = function () {
    $services = [];
    foreach (['active', 'wrong_id', 'unreadable', 'confirmed'] as $result) {
        $services[$result] = $service = cancellationService('batch_' . $result);
        ServiceCancellation::withoutEvents(fn () => endOfPeriod($service));
    }
    $GLOBALS['stripeHandler'] = function ($verb, $path) {
        if ($verb === 'delete' || $path === '/subscriptions/sub_batch_unreadable') throw new StripeFixtureError(503);
        return ['id' => $path === '/subscriptions/sub_batch_wrong_id' ? 'sub_other' : basename($path),
            'status' => $path === '/subscriptions/sub_batch_active' ? 'active' : 'canceled'];
    };
    recoverCancellations();
    foreach (['active', 'wrong_id', 'unreadable'] as $result) check(cancellationRetained($services[$result]), 'Unconfirmed ' . $result . ' keeps both fields');
    check(cancellationCleared($services['confirmed']), 'Recovery continues past individual failures');
};
foreach (['binding', 'cancel', 'finalize'] as $race) {
    $cases['subscription-' . $race] = function () use ($race) {
        $service = cancellationService($race, [nativeInvoice(['binding' => 810, 'cancel' => 811, 'finalize' => 812][$race])]);
        $draft = subscriptionDraft($service);
        $worker = subscriptionWorker();
        $overlapped = false;
        $GLOBALS['stripeHandler'] = function ($verb, $path) use ($race, $service, $draft, $worker, &$overlapped) {
            check(DB::transactionLevel() === 0, 'Subscription operation HTTP runs outside every transaction');
            $trigger = $race === 'cancel' ? $verb === 'delete'
                : $verb === 'post' && $path === '/invoices/' . $draft->id . ($race === 'finalize' ? '/finalize' : '');
            if (!$overlapped && $trigger) {
                $overlapped = true;
                if ($race === 'cancel') {
                    try { $worker->webhook(eventRequest($draft, type: 'invoice.created')); }
                    catch (App\Exceptions\DisplayException $e) { /* The held cancellation guard refuses the competing write. */ }
                } else endOfPeriod($service);
            }
            return $verb === 'get' ? $draft : ['id' => $service->subscription_id, 'status' => 'canceled'];
        };
        if ($race === 'cancel') endOfPeriod($service); else $worker->webhook(eventRequest($draft, type: 'invoice.created'));
        check($overlapped, 'The competing operation ran at ' . $race);
        $paths = array_column(subscriptionWrites(), 1);
        if ($race === 'cancel') check(!$paths && cancellationCleared($service), 'Invoice delivery during cancellation cannot charge after deletion');
        else {
            check(!in_array('/invoices/' . $draft->id . '/pay', $paths, true), 'Cancellation committed during ' . $race . ' prevents payment');
            if ($race === 'binding') check(!in_array('/invoices/' . $draft->id . '/finalize', $paths, true), 'Cancellation committed during binding prevents finalization');
            check(cancellationRetained($service), 'Competing cancellation remains pending until the invoice guard releases');
            recoverCancellations();
            check(cancellationCleared($service), 'Production recovery completes the competing cancellation');
        }
        $before = subscriptionWrites();
        $worker->webhook(eventRequest($draft, type: 'invoice.created'));
        check(subscriptionWrites() === $before, 'Successful cancellation has no later finalization or payment');
    };
}
$cases['replacement-id'] = function () {
    $service = cancellationService('original');
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($service) {
        Service::withoutEvents(fn () => Service::where('id', $service->id)->update(['subscription_id' => 'sub_replacement']));
        return ['id' => basename($path), 'status' => 'canceled'];
    };
    endOfPeriod($service);
    check($service->fresh()->subscription_id === 'sub_replacement'
        && $service->properties()->where('key', 'has_stripe_subscription')->first()?->value === '1', 'Confirmed deletion never clears a replacement subscription or its marker');
};
$cases['ownership-disabled'] = function () use ($nativeGateway) {
    $nativeGateway->update(['enabled' => false]);
    $service = cancellationService('disabled');
    endOfPeriod($service);
    check(cancellationCleared($service), 'Disabled AE Stripe still cancels its subscriptions');
    $bundled = Gateway::create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
    $service = cancellationService('bundled');
    ServiceCancellation::withoutEvents(fn () => endOfPeriod($service));
    $before = count($GLOBALS['stripeCalls']);
    recoverCancellations();
    check(count($GLOBALS['stripeCalls']) === $before && cancellationRetained($service), 'Even a disabled bundled gateway retains subscription ownership');
    $bundled->delete();
    recoverCancellations();
    check(cancellationCleared($service), 'Recovery takes ownership only after bundled Stripe is absent');
};

$failures = [];
foreach ($cases as $name => $case) {
    if (getenv('PAY_PR95_CASE') && getenv('PAY_PR95_CASE') !== $name) continue;
    nativeSharedCache();
    Carbon::setTestNow(now());
    Sleep::fake(true, true);
    Event::forget(Updated::class); Event::forget(Created::class);
    subscriptionWorker()->boot();
    $GLOBALS['stripeCalls'] = [];
    $GLOBALS['stripeHandler'] = function ($verb, $path) {
        check(DB::transactionLevel() === 0, 'Cancellation HTTP runs outside database transactions');
        return ['id' => basename($path), 'status' => 'canceled'];
    };
    try { $case(); echo 'PASS case=' . $name . "\n"; }
    catch (Throwable $e) { $failures[] = $name; echo 'FAIL case=' . $name . ': ' . $e->getMessage() . "\n"; }
    finally {
        while (DB::transactionLevel() > 0) DB::rollBack();
        DB::table('services')->update(['subscription_id' => null]);
        $nativeGateway->update(['enabled' => true]);
        Sleep::fake(false); Carbon::setTestNow();
    }
}
check(!$failures, 'Failed cancellation cases: ' . implode(', ', $failures));
