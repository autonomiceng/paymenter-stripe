<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native events, checkout transactions and Livewire actions cannot be established by the offline model fakes.
use App\Models\{BillingAgreement, Category, Invoice, Order, Product, Service};
use Illuminate\Support\Facades\{Cache, DB, Http};
use Livewire\Livewire;

$case = getenv('PAY_CHECKOUT_NATIVE');
$category = Category::create(['slug' => 'checkout-fixture', 'name' => 'Fixture']);
$product = Product::create(['category_id' => $category->id, 'name' => 'Fixture', 'slug' => 'checkout-fixture']);
$plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 20]);
$old = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_old']);
$record = ['version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => $user->id, 'accepted_at' => 1794071700, 'agreement_id' => $old->id];
$setDefault = fn () => $user->properties()->updateOrCreate(['key' => 'ae_stripe_auto_pay_default'], ['value' => json_encode($record)]);
$create = fn ($extra = []) => Service::create($extra + ['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
    'price' => 20, 'currency_code' => 'USD', 'quantity' => 1, 'status' => 'active']);
$delivered = fn ($page) => array_values(array_filter($page->effects['dispatches'] ?? [], fn ($e) => $e['name'] === 'notify'));
$pending = fn ($service) => $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->exists();
if (str_starts_with($case, 'followup-') || str_starts_with($case, 'final-')) { require __DIR__ . '/native-pending-followups.php'; return; }
if (str_starts_with($case, 'polish-')) { require __DIR__ . '/native-pending-polish.php'; return; }
if (in_array($case, ['transaction', 'checkout', 'pending-retry'], true)) {
    $setDefault();
    if ($case === 'transaction') {
        $service = DB::transaction(function () use ($create, $assert, $pending) {
            $service = $create();
            $assert($service->fresh()->billing_agreement_id === null, 'Inheritance never binds inside the order transaction.');
            $assert($pending($service), 'Pending inheritance is committed with the service.');
            return $service;
        });
        $assert($service->fresh()->billing_agreement_id === $old->id && !$pending($service), 'The after-commit attempt binds and clears pending work.');
        try { DB::transaction(function () use ($create) { $create(); throw new RuntimeException('Rollback fixture'); }); } catch (RuntimeException $e) {}
        $assert(Service::count() === 1, 'Rolled-back checkout leaves neither service nor pending binding.');
    } else {
        $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
        try {
            if ($case === 'checkout') {
                $cart = App\Models\Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
                $cart->items()->create(['product_id' => $product->id, 'plan_id' => $plan->id, 'quantity' => 1, 'checkout_config' => [], 'config_options' => []]);
                request()->cookies->set('cart', $cart->ulid); Illuminate\Support\Once::flush();
                $page = Livewire::withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('tos', true)->call('checkout');
                $assert(Order::count() === 1 && Service::count() === 1, 'The real checkout commits the order while the saved-card lock is busy: ' . json_encode($delivered($page)));
                $service = Service::firstOrFail();
            } else {
                $service = DB::transaction(fn () => $create());
                $assert($service->fresh()->billing_agreement_id === null, 'Busy inheritance leaves the committed service unbound.');
            }
            $assert($pending($service), 'Busy inheritance retains durable pending work.');
            if ($case === 'checkout') {
                $bill = Invoice::latest('id')->firstOrFail();
                $invoicePage = Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $bill]);
                $assert((bool) array_filter($delivered($invoicePage), fn ($e) => str_contains($e['params'][0]['message'] ?? '', 'Auto Pay setup is pending')), 'The order invoice also shows the pending setup.');
            }
            $page = Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()]);
            $events = $delivered($page);
            $assert((bool) array_filter($events, fn ($e) => str_contains($e['params'][0]['message'] ?? '', 'Auto Pay setup is pending')), 'The customer sees pending Auto Pay and an automatic retry explanation.');
        } finally { $lock->release(); }
        $retryGateway = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_secret_key' => 'rk_test_fixture']);
        $retryGateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id && !$pending($service), 'The scheduler retries after contention clears.');
        $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Repeated retry does not alter the completed binding.');
    }
} elseif ($case === 'pending-survivor') {
    $new = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_new']);
    $user->properties()->updateOrCreate(['key' => 'ae_stripe_auto_pay_default'], ['value' => json_encode(array_replace($record, ['agreement_id' => $new->id]))]);
    $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
    try { $service = DB::transaction(fn () => $create()); } finally { $lock->release(); }
    $methods['pm_new']['customer'] = null;
    $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_new', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_old'])]);
    $assert($gateway->deduplicateCards(true, fn ($line) => null) === 0, 'Interrupted cleanup moves the same consent to its verified survivor.');
    $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === $old->id && !$pending($service), 'Pending inheritance follows the verified survivor without losing the original consent.');
} elseif ($case === 'pending-reserved') {
    $setDefault();
    $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_old', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_other'])]);
    $service = DB::transaction(fn () => $create());
    $gateway->retryAutoPayInheritance();
    $assert($pending($service) && $service->fresh()->billing_agreement_id === null, 'A reserved card leaves inheritance pending even after the cache lock is free.');
    $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600);
    $assert($lock->get(), 'Failed binding releases its acquired card lock.'); $lock->release();
    $user->properties()->where('key', 'ae_stripe_duplicate_pm_old')->delete();
    $gateway->retryAutoPayInheritance();
    $assert(!$pending($service) && $service->fresh()->billing_agreement_id === $old->id, 'Resolved reservation lets pending inheritance finish.');
} elseif (str_starts_with($case, 'paused-create-')) {
    // A service ordered during a pause or while bundled Stripe is active waits for AE Stripe card payments.
    $missing = preg_match('/-(noconsent|nocard)$/', $case, $match) ? $match[1] : null;
    if ($missing !== 'noconsent') $setDefault();
    if ($missing === 'nocard') $old->delete();
    $bundled = str_contains($case, 'bundled') ? App\Models\Gateway::create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]) : null;
    $pause = fn () => $bundled ? $bundled->update(['enabled' => true]) : $native->update(['enabled' => false]);
    $resume = fn () => $bundled ? $bundled->update(['enabled' => false]) : $native->update(['enabled' => true]);
    $binds = 0;
    Service::updated(function ($service) use (&$binds) { if ($service->wasChanged('billing_agreement_id') && $service->billing_agreement_id) ++$binds; });
    $notices = fn ($page) => array_filter($delivered($page), fn ($e) => str_contains($e['params'][0]['message'] ?? '', 'Auto Pay setup'));
    $pause();
    $service = DB::transaction(fn () => $create());
    $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === null, 'Nothing is linked while card payments are paused.');
    if ($missing) {
        $assert(!$pending($service), 'Without consent or a card no pending setup is recorded.');
        $resume(); $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === null && $binds === 0, 'Resume links nothing without consent or a card.');
    } else {
        $assert($pending($service), 'A service ordered while paused keeps pending Auto Pay setup.');
        $invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        foreach ([Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()]), Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()])] as $page) {
            $shown = array_values($notices($page));
            $assert(count($shown) === 1 && str_contains($shown[0]['params'][0]['message'], 'paused. We will retry when card payments resume'), 'Service and invoice explain the paused setup: ' . json_encode($shown));
        }
        $resume();
        $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id && !$pending($service) && $binds === 1, 'Resume links the service exactly once: ' . $binds);
        $assert(!$notices(Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])), 'The notice ends once Auto Pay is linked.');
        $pause(); $gateway->retryAutoPayInheritance(); $resume();
        $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id && $binds === 1, 'A repeated resume does not link again.');
    }
    $assert(Http::recorded()->isEmpty() && App\Models\InvoiceTransaction::count() === 0, 'Linking makes no provider request and no charge.');
} elseif (str_starts_with($case, 'pending-')) {
    $setDefault();
    $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
    try { $service = DB::transaction(fn () => $create()); } finally { $lock->release(); }
    $assert($pending($service), 'The fixture has durable pending work.');
    if ($case === 'pending-off') $gateway->saveAutoPayDefault(Illuminate\Http\Request::create('/', 'POST', ['enabled' => false]));
    elseif ($case === 'pending-removed') $old->delete();
    elseif ($case === 'pending-clear') Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])->call('clearBillingAgreement');
    elseif ($case === 'pending-subscription') $service->update(['subscription_id' => 'sub_existing']);
    elseif ($case === 'pending-owner') { $otherUser = App\Models\User::create(['email' => 'other@example.com', 'password' => 'synthetic']); $service->update(['user_id' => $otherUser->id]); }
    else throw new RuntimeException('Unknown pending case');
    if (in_array($case, ['pending-off', 'pending-clear'], true)) $assert(!$pending($service), 'Explicit opt-out cancels pending work before any worker runs.');
    $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === null && !$pending($service), 'Pending retry never overrides later consent, ownership, removal, subscription or service opt-out.');
} elseif ($case === 'removal-partial') {
    $service = $create(['billing_agreement_id' => $old->id]);
    $setDefault();
    $new = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_new']);
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests();
    Http::fake(function ($request) use (&$methods, $assert) {
        $assert(DB::transactionLevel() === 0, 'Native removal HTTP stays outside transactions.');
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v1/payment_methods/pm_new/detach') return Http::response(['error' => ['code' => 'api_error']], 503);
        foreach ($methods as $id => &$method) {
            if ($path === '/v1/payment_methods/' . $id . '/detach') $method['customer'] = null;
            elseif ($path !== '/v1/payment_methods/' . $id) continue;
            return Http::response($method);
        }
        throw new RuntimeException('Unexpected native removal request.');
    });
    $assert(!$gateway->cancelBillingAgreement($new), 'Native partial removal retains failure.');
    $assert($old->fresh()->trashed() && !$new->fresh()->trashed() && $service->fresh()->billing_agreement_id === null,
        'Native partial failure reconciles the detached copy and its real service link.');
    $assert($gateway->autoPayDefault($user) === null, 'Native partial failure clears the detached account default.');
} elseif ($case === 'subscription' || $case === 'invoice-success') {
    $service = $create($case === 'subscription' ? ['subscription_id' => 'sub_existing'] : []);
    $invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
    if ($case === 'subscription') {
        $page = Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])->set('selectedMethod', $old->ulid)->call('updateBillingAgreement');
        $assert($service->fresh()->billing_agreement_id === null, 'A Stripe-subscription service rejects a local-only card selection.');
        $assert((bool) array_filter($delivered($page), fn ($e) => str_contains($e['params'][0]['message'] ?? '', 'subscription')), 'Subscription selection explains its separate billing method.');
    }
    // A real native action must reach payment exactly once; fake the extension debit boundary only.
    $payments = 0;
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests();
    Http::fake(function ($request) use (&$payments) {
        if (str_ends_with($request->url(), '/payment_intents')) { ++$payments; return Http::response(['error' => ['message' => 'Synthetic payment continuation']], 503); }
        throw new RuntimeException('Unexpected native invoice request: ' . parse_url($request->url(), PHP_URL_PATH));
    });
    $page = Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()])->set('selectedMethod', $old->ulid)->set('setAsDefault', true)->call('processPayment');
    $assert($service->fresh()->billing_agreement_id === ($case === 'subscription' ? null : $old->id), 'Invoice default selection binds only ordinary recurring services.');
    $assert($payments === 1, 'Native invoice payment continues exactly once after default selection.');
    $page->assertSet('setAsDefault', false);
} elseif (str_starts_with($case, 'notice-')) {
    $intent = json_decode(json_encode(['id' => 'pi_notice', 'customer' => 'cus_fixture', 'payment_method' => 'pm_new',
        'payment_method_options' => ['card' => ['setup_future_usage' => 'off_session']],
        'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']]));
    $token = (new ReflectionMethod($gateway, 'saveConsentedMethod'))->invoke($gateway, $intent, str_repeat('a', 32));
    $sessionSnapshot = session()->all();
    if ($case === 'notice-tab') {
        request()->query->remove('saved_notice');
        $poll = Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        $assert(!$delivered($poll), 'Another tab polling the same invoice cannot consume checkout confirmation.');
    }
    request()->query->set('saved_notice', $token ?? str_repeat('a', 32));
    if ($case === 'notice-failure') {
        $broken = new class extends App\Livewire\Invoices\Show {
            public function notify($message, $type = 'success', $redirect = false) { throw new RuntimeException('Synthetic delivery failure'); }
        };
        $broken->invoice = $invoice;
        try { (new ReflectionMethod($gateway, 'deliverSavedMethodNotice'))->invoke($gateway, $broken); throw new RuntimeException('Expected delivery failure'); }
        catch (RuntimeException $e) { $assert($e->getMessage() === 'Synthetic delivery failure', 'Notice delivery failure is observable.'); }
        $state = json_decode($user->properties()->where('key', 'ae_stripe_notice_' . hash('sha256', $token))->firstOrFail()->value, true);
        $assert(!$state['consumed'], 'Failed delivery rolls back its claim so the same tab can retry.');
    }
    if ($case === 'notice-guards') {
        $account = Livewire::test(App\Livewire\Client\PaymentMethods::class);
        $assert(!$delivered($account), 'A non-invoice component leaves checkout confirmation pending.');
        $foreign = new Invoice(['user_id' => 999, 'currency_code' => 'USD', 'due_at' => now()->addWeek(), 'status' => 'pending']);
        // Invoke only the guard so native invoice routing does not reject ownership before it runs.
        $component = new App\Livewire\Invoices\Show; $component->invoice = $foreign;
        (new ReflectionMethod($gateway, 'deliverSavedMethodNotice'))->invoke($gateway, $component);
        $original = $user->properties()->where('key', 'like', 'ae_stripe_notice_%')->first();
        $assert($original !== null, 'Foreign invoice delivery does not consume the intended notice.');
        $saved = json_decode($original->value, true); $wrong = $saved; $wrong['user_id'] = 999;
        $original->update(['value' => json_encode($wrong)]);
        $page = Livewire::withQueryParams(['saved_notice' => $token ?? str_repeat('a', 32)])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        $assert(!$delivered($page) && $original->fresh()->value === json_encode($wrong), 'A mismatched notice user cannot receive confirmation.');
        $original->update(['value' => json_encode($saved)]);
    }
    $page = Livewire::withQueryParams(['saved_notice' => $token ?? str_repeat('a', 32)])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $assert(count($delivered($page)) === 1, 'The checkout destination claims and delivers its confirmation.');
    session()->replace($sessionSnapshot);
    $page = Livewire::withQueryParams(['saved_notice' => $token ?? str_repeat('a', 32)])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $assert(!$delivered($page), 'Two requests with the same loaded session cannot deliver one notice twice.');
    (new ReflectionMethod($gateway, 'saveConsentedMethod'))->invoke($gateway, $intent, $token);
    $page = Livewire::withQueryParams(['saved_notice' => $token ?? str_repeat('a', 32)])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $assert(!$delivered($page), 'Repeated confirmation with the same checkout token cannot restore a consumed notice.');
} else throw new RuntimeException('Unknown native checkout follow-up case');
echo "PASS: $checks native checkout follow-up checks ($case, $theme)\n";
