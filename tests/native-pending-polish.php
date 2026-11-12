<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// The native editor, observer and Livewire hydration need the existing in-memory application fixture.
use App\Models\{Role, Service, User};
use Illuminate\Support\Facades\{Cache, DB};
use Livewire\Livewire;

$setDefault();
$lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
try { $service = DB::transaction(fn () => $create()); } finally { $lock->release(); }
$assert($pending($service), 'Fixture has pending setup.');
if (str_starts_with($case, 'polish-staff')) {
    $service->update(['expires_at' => now()->addWeek()]);
    $role = Role::create(['name' => 'Staff', 'permissions' => ['*']]);
    $user->update(['role_id' => $role->id]); $user->unsetRelation('role');
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire::test(App\Admin\Resources\ServiceResource\Pages\EditService::class, ['record' => $service->id]);
    $field = $page->instance()->form->getComponentByStatePath('billing_agreement_id');
    $actions = $field->getHintActions();
    if ($case !== 'polish-staff-select') $assert((bool) array_filter($actions, fn ($a) => $a->getName() === 'aeStripeAutoPayOff'), 'Staff editor offers explicit pending Auto Pay opt-out beside None.');
    $page->set('data.quantity', 2)->call('save');
    $assert($service->fresh()->quantity === 2, 'Unrelated staff save succeeds: ' . json_encode($page->instance()->getErrorBag()->toArray()));
    $assert($pending($service), 'Unrelated staff edits preserve pending setup.');
    if ($case === 'polish-staff-select') {
        $page->set('data.billing_agreement_id', $old->id)->call('save');
        $assert(!$pending($service), 'Explicit staff card selection cancels pending inheritance before retry.');
        $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Worker preserves explicit staff selection.');
        echo "PASS: $checks pending polish checks ($case, $theme)\n"; return;
    }
    if ($case === 'polish-staff-delayed') {
        $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Callback can finish before the delayed staff click.');
    }
    if ($case === 'polish-staff-subscription') {
        $fired = false;
        DB::listen(function ($query) use (&$fired, $service) {
            if ($fired || DB::transactionLevel() === 0 || !str_starts_with($query->sql, 'select * from "users"')) return;
            $fired = true; $service->refresh()->update(['subscription_id' => 'sub_existing']);
        });
        $status = null;
        try {
            $page->call('mountAction', 'aeStripeAutoPayOff', [], ['schemaComponent' => 'form.billing_agreement_id'])->call('callMountedAction');
            $state = (new ReflectionProperty($page, 'lastState'))->getValue($page); $status = $state->getResponse()->status();
        } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $status = $e->getStatusCode(); }
        $assert($fired && in_array($status, [404, 409], true) && $pending($service), 'A subscription assigned during staff opt-out is refused without claiming Auto Pay is off: ' . json_encode([$fired, $status]));
        echo "PASS: $checks pending polish checks ($case, $theme)\n"; return;
    }
    $page->call('mountAction', 'aeStripeAutoPayOff', [], ['schemaComponent' => 'form.billing_agreement_id'])->call('callMountedAction');
    $assert(!$pending($service) && $service->fresh()->billing_agreement_id === null, 'Staff None action cancels pending setup immediately.');
    $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === null, 'Repeated callbacks cannot restore staff opt-out.');
    $service->update(['expires_at' => now()->addDay()]);
    Illuminate\Support\Facades\Artisan::call('app:cron-job');
    $assert($service->invoices()->count() === 1, 'Native renewal still issues an invoice after staff opt-out.');
    Illuminate\Support\Facades\Http::assertNothingSent();
} elseif ($case === 'polish-owner') {
    $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']);
    $service->update(['user_id' => $otherUser->id]);
    $assert(!$pending($service), 'Actual ownership transition cancels old pending setup immediately.');
    $service->update(['user_id' => $user->id]); $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === null, 'Ownership round trip cannot restore old pending consent.');
    $lock->get();
    try {
        (new ReflectionMethod($gateway, 'applyNewServiceAutoPay'))->invoke($gateway, $service->fresh());
        $service->update(['quantity' => 2]);
        $assert($pending($service), 'A fresh pending request after transfer survives unchanged-owner edits.');
    } finally { $lock->release(); }
    $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === $old->id, 'Fresh authorized work after transfer can complete.');
} elseif ($case === 'polish-owner-callback') {
    $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']);
    $newRecord = array_replace($record, ['user_id' => $otherUser->id]);
    $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->delete();
    DB::transaction(function () use ($service, $otherUser, $gateway, $newRecord) {
        DB::afterCommit(function () use ($service, $otherUser, $newRecord) {
            $service->update(['user_id' => $otherUser->id]);
            $service->properties()->updateOrCreate(['key' => 'ae_stripe_auto_pay_pending'], ['value' => json_encode($newRecord)]);
        });
        (new ReflectionMethod($gateway, 'applyNewServiceAutoPay'))->invoke($gateway, $service->fresh());
    });
    $value = json_decode($service->properties()->where('key', 'ae_stripe_auto_pay_pending')->firstOrFail()->value, true);
    $assert($value === $newRecord && $service->fresh()->billing_agreement_id === null, 'Old after-commit callback cannot bind or delete the new owner pending request.');
} elseif (str_starts_with($case, 'polish-paused-')) {
    $nearCap = $record + ['retry' => ['attempts' => 9, 'state' => 'pending']];
    $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->update(['value' => json_encode($nearCap)]);
    $beforePause = $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->first()->value;
    $bundled = null;
    if (str_contains($case, 'disabled')) $native->update(['enabled' => false]);
    else $bundled = App\Models\Gateway::create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
    $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
    $assert($pending($service) && $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->first()->value === $beforePause, 'Operational pause preserves the exact near-cap retry budget.');
    $invalidate = preg_match('/-(off|removed|subscription|owner)$/', $case, $match) ? $match[1] : null;
    if ($invalidate === 'off') $gateway->saveAutoPayDefault(Illuminate\Http\Request::create('/', 'POST', ['enabled' => false]));
    elseif ($invalidate === 'removed') $old->delete();
    elseif ($invalidate === 'subscription') $service->update(['subscription_id' => 'sub_existing']);
    elseif ($invalidate === 'owner') { $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']); $service->update(['user_id' => $otherUser->id]); }
    if ($invalidate) {
        $gateway->retryAutoPayInheritance();
        $assert(!$pending($service), 'Revocation and invalid eligibility are cancelled even during operational pause.');
    }
    if ($bundled) $bundled->update(['enabled' => false]); else $native->update(['enabled' => true]);
    if (str_ends_with($case, '-failure')) {
        $reservation = $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_old', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_other'])]);
        $gateway->retryAutoPayInheritance();
        $retry = json_decode($service->properties()->where('key', 'ae_stripe_auto_pay_pending')->first()->value, true)['retry'];
        $assert($retry === ['attempts' => 10, 'state' => 'stopped'], 'First genuine failure after resume consumes exactly the remaining attempt.');
        $reservation->delete(); $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === null, 'A stopped request is not automatically revived.');
        $gateway->saveAutoPayDefault(Illuminate\Http\Request::create('/', 'POST', ['enabled' => true, 'agreement_id' => $old->id, 'consent' => true, 'version' => $gateway::AUTO_PAY_CONSENT]));
        $assert(!$pending($service) && $service->fresh()->billing_agreement_id === $old->id, 'Explicit account On replaces stopped work.');
        echo "PASS: $checks pending polish checks ($case, $theme)\n"; return;
    }
    $gateway->retryAutoPayInheritance();
    $assert(!$pending($service) && $service->fresh()->billing_agreement_id === ($invalidate ? null : $old->id), 'Resume revalidates and completes pending setup.');
} elseif ($case === 'polish-exhausted') {
    $stoppedLogs = 0; Illuminate\Support\Facades\Log::listen(function ($event) use (&$stoppedLogs) { if ($event->message === 'ae_stripe_auto_pay_binding_stopped') ++$stoppedLogs; });
    $reservation = $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_old', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_other'])]);
    for ($i = 1; $i <= 10; ++$i) {
        $gateway->retryAutoPayInheritance();
        $retry = json_decode($service->properties()->where('key', 'ae_stripe_auto_pay_pending')->first()->value, true)['retry'];
        $assert($retry === ['attempts' => $i, 'state' => $i < 10 ? 'pending' : 'stopped'], 'Genuine failure has the exact ninth/tenth boundary.');
    }
    $reservation->delete();
    for ($i = 0; $i < 15; ++$i) $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === null, 'Exhausted setup stops automatic retries after the lock clears.');
    $assert($stoppedLogs === 1, 'Only exhaustion logs once; repeated retries do not log forever.');
    $page = Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()]);
    $assert((bool) array_filter($delivered($page), fn ($e) => str_contains($e['params'][0]['message'] ?? '', 'could not')), 'Exhausted setup explains manual selection or invoice payment.');
    $page->set('selectedMethod', $old->ulid)->call('updateBillingAgreement');
    $assert(!$pending($service) && $service->fresh()->billing_agreement_id === $old->id, 'Explicit selection can replace exhausted setup.');
} elseif (str_starts_with($case, 'polish-select-')) {
    $selected = App\Models\BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Selected card', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_selected']);
    if ($case === 'polish-select-service') {
        Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])->set('selectedMethod', $selected->ulid)->call('updateBillingAgreement');
    } elseif ($case === 'polish-select-account') {
        $gateway->saveAutoPayDefault(Illuminate\Http\Request::create('/', 'POST', ['enabled' => true, 'agreement_id' => $selected->id, 'consent' => true, 'version' => $gateway::AUTO_PAY_CONSENT]));
    } else {
        $invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $payments = 0;
        Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory); Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function ($request) use (&$payments) {
            if (str_ends_with($request->url(), '/payment_intents')) { ++$payments; return Illuminate\Support\Facades\Http::response(['error' => ['message' => 'Synthetic continuation']], 503); }
            throw new RuntimeException('Unexpected native invoice request');
        });
        Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()])->set('selectedMethod', $selected->ulid)->set('setAsDefault', true)->call('processPayment');
        $assert($payments === 1, 'Invoice payment continues exactly once after choosing a recurring card.');
    }
    $assert(!$pending($service) && $service->fresh()->billing_agreement_id === $selected->id, 'Explicit selection cancels pending setup synchronously.');
    $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === $selected->id, 'Repeated workers preserve the explicit replacement.');
} elseif (str_starts_with($case, 'polish-inflight-')) {
    $fired = false;
    DB::listen(function ($query) use (&$fired, $case, $gateway, $service) {
        if ($fired || !str_starts_with($query->sql, 'select * from "users"')) return;
        $fired = true;
        if ($case === 'polish-inflight-off') $gateway->saveAutoPayDefault(Illuminate\Http\Request::create('/', 'POST', ['enabled' => false]));
        else Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()])->call('clearBillingAgreement');
    });
    $gateway->retryAutoPayInheritance();
    $assert($fired && !$pending($service) && $service->fresh()->billing_agreement_id === null, 'An in-flight worker cannot restore an explicit opt-out.');
} elseif (str_starts_with($case, 'polish-poll')) {
    $invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
    if ($case === 'polish-poll-many') {
        $lock->get(); try { $second = DB::transaction(fn () => $create()); } finally { $lock->release(); }
        $invoice->items()->create(['quantity' => 1, 'price' => 20, 'description' => 'Second service', 'reference_type' => Service::class, 'reference_id' => $second->id]);
        $assert($pending($second), 'Both invoice services have pending setup.');
    }
    foreach ([App\Livewire\Services\Show::class => ['service' => $service->fresh()], App\Livewire\Invoices\Show::class => ['invoice' => $invoice->fresh()]] as $class => $parameters) {
        if ($case === 'polish-poll-many') {
            foreach ([$service, $second] as $fixture) {
                $fixture->refresh()->update(['billing_agreement_id' => null]);
                $lock->get();
                try { (new ReflectionMethod($gateway, 'applyNewServiceAutoPay'))->invoke($gateway, $fixture->fresh()); } finally { $lock->release(); }
                $assert($pending($fixture), 'Each page scenario starts with both independent pending services.');
            }
        }
        $page = Livewire::test($class, $parameters);
        $assert(count($delivered($page)) === 1, 'First page load explains pending setup.');
        if ($case === 'polish-poll-many' && $class === App\Livewire\Invoices\Show::class) {
            $second->properties()->where('key', 'ae_stripe_auto_pay_pending')->update(['value' => json_encode($record + ['retry' => ['attempts' => 10, 'state' => 'stopped']])]);
            $page->call('$refresh');
            $assert(count($delivered($page)) === 1 && str_contains($delivered($page)[0]['params'][0]['message'], 'could not'), 'Only the second service changing to stopped takes precedence.');
            $page->call('$refresh'); $assert(!$delivered($page), 'Stopped aggregate state is explained once.');
            $second->properties()->where('key', 'ae_stripe_auto_pay_pending')->update(['value' => json_encode($record)]);
            $page->call('$refresh'); $assert(count($delivered($page)) === 1, 'Changing only the second service back to pending updates the invoice.');
            $page->call('$refresh');
        }
        $page->call('$refresh');
        $assert(!$delivered($page), 'Identical polling does not repeat pending setup warning.');
        $native->update(['enabled' => false]); $page->call('$refresh');
        $assert(count($delivered($page)) === 1, 'Changed paused state is explained once.');
        $page->call('$refresh'); $assert(!$delivered($page), 'Paused state does not repeat.');
        $native->update(['enabled' => true]);
        $newPage = Livewire::test($class, $parameters);
        $assert(count($delivered($newPage)) === 1, 'A new page explains pending work independently.');
        $gateway->retryAutoPayInheritance(); $page->call('$refresh');
        $assert(!$delivered($page), 'Completed setup has no pending warning.');
        $service->refresh()->update(['billing_agreement_id' => null]);
        $lock->get();
        try { (new ReflectionMethod($gateway, 'applyNewServiceAutoPay'))->invoke($gateway, $service->fresh()); } finally { $lock->release(); }
        $assert($pending($service), 'Fixture has new pending work after completion.');
        $page->call('$refresh');
        $assert(count($delivered($page)) === 1, 'Later pending work on the same page explains its new state.');
        $page->call('notify', 'Payment not completed.', 'error');
        $assert(count($delivered($page)) === 1 && ($delivered($page)[0]['params'][0]['type'] ?? null) === 'error', 'Actual payment feedback is preserved without another pending warning.');
    }
} elseif (str_starts_with($case, 'polish-deferred') || in_array($case, ['polish-token', 'polish-prune', 'polish-refused', 'polish-legacy'], true)) {
    $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->delete();
    $gateway = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_secret_key' => 'rk_test_fixture', 'stripe_webhook_secret' => 'whsec_fixture']);
    if (str_contains($case, 'first')) $old->forceDelete();
    $intent = json_decode(json_encode(['id' => 'pi_noticepolish', 'status' => 'succeeded', 'customer' => 'cus_fixture', 'payment_method' => 'pm_new',
        'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000,
        'payment_method_options' => ['card' => ['setup_future_usage' => 'off_session']],
        'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']]));
    $save = fn ($object, $token) => (new ReflectionMethod($gateway, 'saveConsentedMethod'))->invoke($gateway, $object, $token);
    $token = str_repeat('b', 32);
    if (str_starts_with($case, 'polish-deferred')) {
        $factory = Illuminate\Support\Facades\Http::getFacadeRoot();
        Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function () use ($case) {
            if (str_contains($case, 'connection')) throw new Illuminate\Http\Client\ConnectionException('Synthetic connection failure');
            return Illuminate\Support\Facades\Http::response(['error' => ['code' => 'api_error']], 503);
        });
        $result = $save($intent, $token);
        Illuminate\Support\Facades\Http::swap($factory);
        $assert($result === $token, 'Transient save preserves the original tab destination for deferred confirmation.');
        $beforeLoad = str_contains($case, 'before');
        $page = $beforeLoad ? null : Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        if ($page) $assert(!$delivered($page), 'No success notice appears while saving is still pending.');
        request()->query->remove('saved_notice');
        $otherTab = Livewire::withQueryParams([])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        if (str_contains($case, 'recovery')) {
            Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
            Illuminate\Support\Facades\Http::preventStrayRequests();
            Illuminate\Support\Facades\Http::fake(function ($request) use ($intent, &$methods, $assert) {
                $assert(DB::transactionLevel() === 0, 'Notice recovery HTTP stays outside transactions.');
                if (str_ends_with($request->url(), '/payment_intents/' . $intent->id)) return Illuminate\Support\Facades\Http::response((array) $intent);
                foreach ($methods as $id => &$remote) if (str_ends_with($request->url(), '/payment_methods/' . $id)) return Illuminate\Support\Facades\Http::response($remote);
                throw new RuntimeException('Unexpected notice recovery request');
            });
            (new ReflectionMethod($gateway, 'retrySavedMethodNotices'))->invoke($gateway);
        } else {
            $body = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => $intent]]);
            $signature = 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $body, 'whsec_fixture');
            $request = Illuminate\Http\Request::create('/', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $body);
            $assert($gateway->webhook($request)->getStatusCode() === 200, 'Deferred webhook saves and settles successfully.');
            $assert($gateway->webhook($request)->getStatusCode() === 200, 'Duplicate webhook remains idempotent.');
            $assert(App\Models\InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->count() === 1, 'Notice recovery never duplicates payment settlement.');
        }
        request()->query->remove('saved_notice');
        $otherTab->call('$refresh'); $assert(!$delivered($otherTab), 'Deferred success is not consumed by another tab.');
        if ($page) $page->call('$refresh');
        else $page = Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        $assert(count($delivered($page)) === 1, 'Original invoice receives deferred success before load or on its next poll.');
        $expected = str_contains($case, 'first') ? 'added successfully' : 'already saved';
        $assert(str_contains($delivered($page)[0]['params'][0]['message'] ?? '', $expected), 'Deferred first and duplicate saves keep the correct confirmation.');
        $page->call('$refresh'); $assert(!$delivered($page), 'Deferred confirmation is delivered once.');
    } elseif ($case === 'polish-token') {
        $first = $save($intent, $token);
        $assert($save($intent, $token) === $first, 'Same intent and token replay is stable.');
        $assert($save($intent, str_repeat('e', 32)) === $first, 'Parallel submissions for the same intent share one claimable destination.');
        $page = Livewire::withQueryParams(['saved_notice' => $first])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        $assert(count($delivered($page)) === 1, 'Original intent delivers once.');
        $assert($save($intent, $token) === $first, 'Consumed intent replay preserves its token.');
        $page->call('$refresh'); $assert(!$delivered($page), 'Consumed intent replay cannot restore success.');
        $secondIntent = clone $intent; $secondIntent->id = 'pi_replacement';
        $second = $save($secondIntent, $token);
        $assert(is_string($second) && $second !== $first, 'Replacement intent receives its own notice identity on the same invoice.');
        $assert($save($secondIntent, $token) === $second, 'Replacement intent allocation is stable across concurrent retries.');
        $thirdIntent = unserialize(serialize($intent)); $thirdIntent->id = 'pi_other_invoice'; $thirdIntent->metadata->invoice_id = $other->id;
        $third = $save($thirdIntent, $token);
        $assert(is_string($third) && $third !== $first && $third !== $second, 'Other invoice cannot turn a notice token collision into a settled-payment error.');
    } elseif ($case === 'polish-legacy') {
        foreach ([false, true] as $consumed) {
            $legacyToken = str_repeat($consumed ? 'd' : 'c', 32);
            $key = 'ae_stripe_notice_' . hash('sha256', $legacyToken);
            $legacy = ['user_id' => $user->id, 'invoice_id' => $invoice->id, 'consumed' => $consumed,
                'notice' => ['type' => 'success', 'message' => 'Payment method added successfully.']];
            $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($legacy)]);
            $intent->id .= $consumed ? 'consumed' : 'ready';
            $result = $save($intent, $legacyToken);
            $assert(is_string($result) && $result !== $legacyToken, 'A notice without intent identity cannot suppress a new payment confirmation.');
            $assert(json_decode($user->properties()->where('key', $key)->firstOrFail()->value, true) === $legacy, 'Legacy notices keep their original delivery state.');
        }
        $expired = clone $intent; $expired->id = 'pi_expirednotice'; $expired->created = now()->subDays(31)->timestamp;
        $assert($save($expired, str_repeat('f', 32)) === null, 'Expired payment confirmation is not recreated after notice retention.');
    } elseif ($case === 'polish-refused') {
        $prior = $save($intent, $token);
        $snapshot = $user->properties()->where('key', 'like', 'ae_stripe_notice_%')->pluck('value', 'key')->all();
        foreach (['no-consent', 'customer', 'owner', 'method', 'usage'] as $refusal) {
            $bad = unserialize(serialize($intent)); $bad->id .= $refusal;
            if ($refusal === 'no-consent') $bad->metadata->ae_stripe_consent = '0';
            elseif ($refusal === 'customer') $bad->customer = 'cus_other';
            elseif ($refusal === 'owner') { $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']); auth()->login($otherUser); }
            elseif ($refusal === 'method') $methods['pm_new']['customer'] = 'cus_other';
            else $bad->payment_method_options->card->setup_future_usage = 'none';
            $assert($save($bad, str_repeat('c', 32)) === null, 'Refused saving has no success token: ' . $refusal);
            $assert($user->properties()->where('key', 'like', 'ae_stripe_notice_%')->pluck('value', 'key')->all() === $snapshot, 'Refusal preserves other notices and creates no success property: ' . $refusal);
            auth()->login($user); $methods['pm_new']['customer'] = 'cus_fixture';
        }
    } else {
        $token = $save($intent, $token);
        $page = Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        $assert(count($delivered($page)) === 1, 'Fixture has consumed a real notice.');
        Illuminate\Support\Carbon::setTestNow(now()->addDays(31));
        $gateway->retryAutoPayInheritance();
        $assert(!$user->properties()->where('key', 'ae_stripe_notice_' . hash('sha256', $token))->exists(), 'Used notice property is pruned after retention.');
        $save($intent, $token);
        $page = Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
        $assert(!$delivered($page), 'Receipt preserves once-only delivery after notice pruning.');
    }
} else throw new RuntimeException('Unknown polish case');
echo "PASS: $checks pending polish checks ($case, $theme)\n";
