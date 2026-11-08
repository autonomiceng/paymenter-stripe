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
foreach (['service-during', 'service-reserved', 'invoice-reserved'] as $scenario) {
    if (getenv('PAY_BINDING_NATIVE_CASE') && getenv('PAY_BINDING_NATIVE_CASE') !== $scenario) continue;
    $service->update(['billing_agreement_id' => null]);
    BillingAgreement::query()->forceDelete();
    $user->properties()->where('key', 'like', 'ae_stripe_%')->delete();
    $old = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_old']);
    $new = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_new']);
    $methods = ['pm_old' => $method('pm_old'), 'pm_new' => $method('pm_new')];
    $page = Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])->set('selectedMethod', $new->ulid);
    $entered = false;
    $attempt = function () use ($page, $service, $assert, &$entered) {
        $entered = true;
        $page->call('updateBillingAgreement');
        $assert($service->fresh()->billing_agreement_id === null, 'The real native service action refuses binding a cleanup reservation.');
        $events = array_filter($page->effects['dispatches'] ?? [], fn ($event) => $event['name'] === 'notify' && str_contains($event['params'][0]['message'] ?? '', 'Try again'));
        $assert((bool) $events, 'The native service action delivers a clear retry notice.');
    };
    Http::fake(function ($request) use (&$methods, $attempt, &$entered, $scenario, $assert) {
        $assert(DB::transactionLevel() === 0, 'Native cleanup provider I/O remains outside owner transactions.');
        $path = parse_url($request->url(), PHP_URL_PATH);
        foreach ($methods as $id => &$remote) {
            if ($path === '/v1/payment_methods/' . $id . '/detach') {
                if (!$entered && $scenario === 'service-during') $attempt();
                $remote['customer'] = null;
            } elseif ($path !== '/v1/payment_methods/' . $id) continue;
            elseif ($request->method() === 'POST') $remote['allow_redisplay'] = $request['allow_redisplay'];
            return Http::response($remote);
        }
        throw new RuntimeException('Unexpected native binding provider request.');
    });
    if ($scenario === 'service-during') {
        $assert($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $entered, 'Cleanup completes while the native service action is refused during detach.');
        $page->set('selectedMethod', $old->ulid)->call('updateBillingAgreement');
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Binding the surviving card succeeds after cleanup releases its lock.');
    } else {
        $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_new', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_old'])]);
        if ($scenario === 'service-reserved') $attempt();
        else {
            $invoicePage = Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()])->set('selectedMethod', $new->ulid)->set('setAsDefault', true);
            $early = false;
            \Livewire\trigger('call', $invoicePage->instance(), 'processPayment', [], new stdClass, function () use (&$early) { $early = true; });
            $assert($early && $service->fresh()->billing_agreement_id === null, 'Invoice recurring-default binding refuses a reserved card before payment.');
        }
    }
}
echo "PASS: $checks native binding checks ($theme)\n";
