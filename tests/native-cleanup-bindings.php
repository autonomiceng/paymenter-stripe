<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{BillingAgreement, Category, Product, Service};
use Illuminate\Support\Facades\{DB, Http};
use Livewire\Livewire;

$category = Category::create(['slug' => 'fixture', 'name' => 'Fixture']);
$product = Product::create(['category_id' => $category->id, 'name' => 'Fixture', 'slug' => 'fixture']);
$plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
$service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 20,
    'currency_code' => 'USD', 'quantity' => 1, 'status' => 'active']);
$invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
foreach (['service-during', 'service-reserved', 'invoice-reserved', 'repair-service'] as $scenario) {
    if (getenv('PAY_BINDING_NATIVE_CASE') && getenv('PAY_BINDING_NATIVE_CASE') !== $scenario) continue;
    $service->update(['billing_agreement_id' => null]);
    BillingAgreement::query()->forceDelete();
    $user->properties()->where('key', 'like', 'ae_stripe_%')->delete();
    $old = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_old']);
    $new = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_new']);
    $methods = ['pm_old' => $method('pm_old'), 'pm_new' => $method('pm_new')];
    $page = null; $entered = false; $bindingError = null;
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    $attempt = function () use (&$page, $service, $assert, &$entered, &$bindingError) {
        $entered = true;
        try {
            $page->call('updateBillingAgreement');
            $assert($service->fresh()->billing_agreement_id === null, 'The real native service action refuses binding a cleanup reservation.');
            $events = array_filter($page->effects['dispatches'] ?? [], fn ($event) => $event['name'] === 'notify' && str_contains($event['params'][0]['message'] ?? '', 'Try again'));
            $assert((bool) $events, 'The native service action delivers a clear retry notice.');
        } catch (Throwable $error) { $bindingError = $error; }
    };
    Http::fake(function ($request) use (&$methods, $attempt, &$entered, $scenario, $assert, $service, $user, $new) {
        $assert(DB::transactionLevel() === 0, 'Native cleanup provider I/O remains outside owner transactions.');
        $path = parse_url($request->url(), PHP_URL_PATH);
        foreach ($methods as $id => &$remote) {
            if ($path === '/v1/payment_methods/' . $id . '/detach') {
                if (!$entered && $scenario === 'service-during') $attempt();
                if (!$entered && $scenario === 'repair-service') {
                    $entered = true;
                    $service->update(['billing_agreement_id' => $new->id]);
                    foreach (['ae_stripe_auto_pay_default', 'ae_stripe_auto_pay_card'] as $key) $user->properties()->create(['key' => $key, 'value' => json_encode([
                        'user_id' => $user->id, 'agreement_id' => $new->id, 'accepted_at' => 1700000000, 'version' => 'auto_pay_account_v1',
                    ])]);
                }
                $remote['customer'] = null;
            } elseif ($path !== '/v1/payment_methods/' . $id) continue;
            elseif ($request->method() === 'POST') $remote['allow_redisplay'] = $request['allow_redisplay'];
            return Http::response($remote);
        }
        throw new RuntimeException('Unexpected native binding provider request.');
    });
    $page = Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])->set('selectedMethod', $new->ulid);
    if (in_array($scenario, ['service-during', 'repair-service'], true)) {
        $lines = [];
        $result = $gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; });
        if ($bindingError) throw $bindingError;
        $assert($result === 0 && $entered, 'Cleanup completes while the native service action is refused during detach: ' . implode(' ', $lines));
        if ($scenario === 'repair-service') {
            $assert($service->fresh()->billing_agreement_id === $old->id && $new->fresh()->trashed(), 'Native reconciliation moves a bypassed service binding to the verified survivor.');
            foreach (['ae_stripe_auto_pay_default', 'ae_stripe_auto_pay_card'] as $key) {
                $saved = json_decode($user->properties()->where('key', $key)->first()->value, true);
                $assert($saved['agreement_id'] === $old->id && $saved['accepted_at'] === 1700000000, 'Native reconciliation preserves account consent and selection.');
            }
        }
        $page->set('selectedMethod', $old->ulid)->call('updateBillingAgreement');
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Binding the surviving card succeeds after cleanup releases its lock.');
    } else {
        $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_new', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_old'])]);
        if ($scenario === 'service-reserved') { $attempt(); if ($bindingError) throw $bindingError; }
        else {
            $invoicePage = Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()])->set('selectedMethod', $new->ulid)->set('setAsDefault', true);
            $early = false;
            \Livewire\trigger('call', $invoicePage->instance(), 'processPayment', [], new stdClass, function () use (&$early) { $early = true; }, [], 0);
            $assert($early && $service->fresh()->billing_agreement_id === null, 'Invoice recurring-default binding refuses a reserved card before payment.');
        }
    }
}
echo "PASS: $checks native binding checks ($theme)\n";
