<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native SQL, model events, staff actions and Livewire rendering need the existing in-memory fixture.
use App\Models\{BillingAgreement, Gateway, Invoice, Property, Service, User};
use Illuminate\Support\Facades\{Cache, DB, Http, Log};
use Livewire\Livewire;

$invoke = fn ($method, ...$args) => (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$args);
$intent = json_decode(json_encode(['id' => 'pi_followup', 'status' => 'succeeded', 'created' => now()->timestamp, 'customer' => 'cus_fixture', 'payment_method' => 'pm_new',
    'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000, 'payment_method_options' => ['card' => ['setup_future_usage' => 'off_session']],
    'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']]));
$notice = function ($token, $changes = []) use ($user, $invoice, $intent) {
    $key = 'ae_stripe_notice_' . hash('sha256', $token);
    $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($changes + ['token' => $token, 'user_id' => $user->id,
        'invoice_id' => $invoice->id, 'intent_id' => $intent->id, 'created_at' => now()->timestamp, 'state' => 'pending', 'consumed' => false])]);
    return $key;
};
$requests = []; $error = null; $methodError = null;
Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests();
Http::fake(function ($request) use (&$requests, &$error, &$methodError, $intent, &$methods, $assert) {
    $assert(DB::transactionLevel() === 0, 'Changed notice HTTP stays outside owner transactions.');
    $path = parse_url($request->url(), PHP_URL_PATH); $requests[] = $path;
    if (str_starts_with($path, '/v1/payment_intents/')) {
        if (!str_ends_with($path, '/' . $intent->id)) {
            if ($error === 'restored') return Http::response(array_replace((array) $intent, ['id' => basename($path)]));
            if ($error === 'malformed') return Http::response(['unexpected' => true]);
            if ($error === 'connection') throw new Illuminate\Http\Client\ConnectionException('Synthetic outage');
            return Http::response(['error' => ['code' => 'resource_missing']], $error ?: 404);
        }
        return Http::response((array) $intent);
    }
    foreach ($methods as $id => &$remote) if ($path === '/v1/payment_methods/' . $id) {
        if ($methodError === 'connection') throw new Illuminate\Http\Client\ConnectionException('Synthetic saving outage');
        return $methodError ? Http::response(['error' => ['code' => $methodError === 404 ? 'resource_missing' : 'api_error']], $methodError) : Http::response($remote);
    }
    throw new RuntimeException('Unexpected fixture HTTP: ' . $path);
});
$token = str_repeat('b', 32);
if (str_starts_with($case, 'final-')) { require __DIR__ . '/native-final-followups.php'; return; }
if ($case === 'followup-staff-race') {
    $setDefault(); $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
    try { $service = DB::transaction(fn () => $create(['expires_at' => now()->addWeek()])); } finally { $lock->release(); }
    $role = App\Models\Role::create(['name' => 'Staff', 'permissions' => ['*']]);
    $user->update(['role_id' => $role->id]); $user->unsetRelation('role');
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire::test(App\Admin\Resources\ServiceResource\Pages\EditService::class, ['record' => $service->id]);
    $otherGateway = Gateway::create(['name' => 'Other', 'extension' => 'Other', 'type' => 'gateway', 'enabled' => true]);
    $selected = BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $otherGateway->id, 'name' => 'Other method', 'type' => 'card', 'external_reference' => 'foreign']);
    $field = $page->instance()->form->getComponentByStatePath('billing_agreement_id');
    $action = array_values(array_filter($field->getHintActions(), fn ($a) => $a->getName() === 'aeStripeAutoPayOff'))[0];
    // The real editor action was loaded before a second staff save committed a new choice.
    DB::table('services')->where('id', $service->id)->update(['billing_agreement_id' => $selected->id]);
    $status = 200;
    try { $action->evaluate($action->getActionFunction(), ['livewire' => $page->instance()]); }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $status = $e->getStatusCode(); }
    $assert($status === 409 && $service->fresh()->billing_agreement_id === $selected->id, 'Staff opt-out preserves a concurrent other-gateway selection and requests refresh: ' . json_encode([$status, $service->fresh()->billing_agreement_id]));
} elseif (str_starts_with($case, 'followup-webhook')) {
    $key = $notice($token);
    $gateway = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_secret_key' => 'rk_test_fixture', 'stripe_webhook_secret' => 'whsec_fixture']);
    if (str_contains($case, 'discard')) $methods['pm_new']['customer'] = 'cus_foreign';
    $body = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => $intent]]);
    $signature = 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $body, 'whsec_fixture');
    $request = Illuminate\Http\Request::create('/', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $body);
    if ($case === 'followup-webhook-save-failure') {
        $factory = Http::getFacadeRoot(); Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(function () use ($assert) { $assert(DB::transactionLevel() === 0, 'Failed saving HTTP is outside owner transactions.'); return Http::response(['error' => ['code' => 'api_error']], 503); });
        $failed = false;
        try { $gateway->webhook($request); } catch (Illuminate\Http\Client\RequestException $e) { $failed = $e->response->status() === 503; }
        $assert($failed, 'An actual transient saving failure remains retryable by the webhook sender.');
        Http::swap($factory);
        $assert($gateway->webhook($request)->getStatusCode() === 200 && App\Models\InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->count() === 1, 'Retried saving completes without another settlement.');
        $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'Recovered saving finishes the original tab destination.');
        echo "PASS: $checks pending follow-up checks ($case, $theme)\n"; return;
    }
    $armed = $case !== 'followup-webhook-discard-log';
    $refusalLogs = 0;
    if ($case === 'followup-webhook-discard-log') {
        $user->properties()->create(['key' => 'ae_stripe_saved_' . hash('sha256', $intent->id . ':' . $intent->payment_method), 'value' => json_encode(['incoming' => $intent->payment_method, 'removed' => true])]);
        Log::listen(function ($event) use (&$refusalLogs) { if ($event->message === 'AeStripe saved method refused') { ++$refusalLogs; throw new RuntimeException('Synthetic logging failure'); } });
    }
    DB::listen(function ($query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'properties') && in_array('ae_stripe_notice_%', $query->bindings, true)) throw new RuntimeException('Synthetic notice storage outage');
    });
    try { $response = $gateway->webhook($request); } catch (Throwable $e) { $assert(false, 'Notice-only storage failures must not fail a settled webhook.'); }
    $assert($response->getStatusCode() === 200, 'Settled webhook acknowledges notice-only outage.');
    if ($case === 'followup-webhook-discard-log') $assert($refusalLogs === 1, 'The post-settlement refusal log actually failed.');
    $armed = false; $invoke = fn ($method, ...$args) => (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$args);
    $invoke('retrySavedMethodNotices');
    $state = json_decode($user->properties()->where('key', $key)->first()?->value ?? 'null', true);
    $assert(str_contains($case, 'discard') ? $state === null : ($state['state'] ?? null) === 'ready', 'Original notice recovers or safely retires after storage returns.');
    $assert($gateway->webhook($request)->getStatusCode() === 200 && App\Models\InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->count() === 1, 'Duplicate webhook never duplicates settlement.');
} elseif (in_array($case, ['followup-queue-local-budget', 'followup-queue-save-budget', 'followup-queue-notice-budget', 'followup-queue-save-outage'], true)) {
    $key = $notice($token, ['attempts' => 19]); $armed = true; $faults = 0;
    if ($case === 'followup-queue-save-outage') $methodError = 503;
    DB::listen(function ($query) use (&$armed, &$faults, $case) {
        if (!$armed || !str_starts_with($query->sql, 'select')) return;
        $fail = match ($case) {
            'followup-queue-local-budget' => str_contains($query->sql, '"invoices"'),
            'followup-queue-save-budget' => in_array('ae_stripe_saved_%', $query->bindings, true),
            'followup-queue-notice-budget' => DB::transactionLevel() > 0 && in_array('ae_stripe_notice_%', $query->bindings, true),
            default => false,
        };
        if ($fail) { ++$faults; throw new RuntimeException('Synthetic local recovery outage'); }
    });
    for ($i = 0; $i < 22; ++$i) {
        $invoke('retrySavedMethodNotices');
        $state = json_decode($user->properties()->where('key', $key)->first()->value, true);
        $assert($state['state'] === 'pending' && $state['attempts'] === 19 && $state['retry_at'] > now()->timestamp, 'Local or saving outages back off without exhausting confirmations.');
        Illuminate\Support\Carbon::setTestNow(now()->addHours(2));
    }
    $armed = false; $methodError = null;
    $assert($faults > 0 || $case === 'followup-queue-save-outage', 'The intended local fault actually fired.');
    $invoke('retrySavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'Local or saving recovery completes the original confirmation after access returns.');
} elseif (str_starts_with($case, 'followup-queue')) {
    if ($case === 'followup-queue-missing') $notice(str_repeat('a', 32), ['intent_id' => 'pi_missing']);
    elseif ($case === 'followup-queue-progress') {
        $wrongOwner = User::create(['email' => 'wrong@example.com', 'password' => 'synthetic']);
        $wrongInvoice = Invoice::create(['user_id' => $wrongOwner->id, 'currency_code' => 'USD', 'due_at' => now()->addWeek(), 'status' => 'pending']);
        for ($i = 0; $i < 350; ++$i) {
            $changes = match ($i % 4) { 0 => ['invoice_id' => 99999], 1 => ['invoice_id' => $wrongInvoice->id], 2 => ['user_id' => $wrongOwner->id], 3 => ['intent_id' => 'invalid'] };
            $notice(str_pad(dechex($i), 32, '0', STR_PAD_LEFT), $changes);
        }
    } elseif (str_starts_with($case, 'followup-queue-outage-')) {
        $error = substr($case, strlen('followup-queue-outage-'));
        if ($error !== 'connection') $error = (int) $error;
        $first = $notice(str_repeat('a', 32), ['intent_id' => 'pi_missing', 'attempts' => 19]);
    } elseif ($case === 'followup-queue-state-race') {
        $key = $notice($token); $changed = false;
        Http::fake(function () use (&$changed, $user, $key) {
            $changed = true; $user->properties()->where('key', $key)->update(['value' => '{"state":"ready","replacement":true}']);
            return Http::response(['error' => ['code' => 'resource_missing']], 404);
        });
        $invoke('retrySavedMethodNotices');
        $assert($changed && $user->properties()->where('key', $key)->first()->value === '{"state":"ready","replacement":true}', 'A provider read cannot overwrite concurrent replacement state.');
        echo "PASS: $checks pending follow-up checks ($case, $theme)\n"; return;
    } elseif ($case === 'followup-queue-storage') {
        $first = $notice(str_repeat('a', 32), ['intent_id' => 'pi_missing']);
        DB::listen(function ($query) use ($first) { if (str_starts_with($query->sql, 'select') && in_array($first, $query->bindings, true)) throw new RuntimeException('Synthetic per-item storage failure'); });
    } elseif ($case === 'followup-queue-spaces') {
        // Legacy/imported JSON need not use the current encoder's whitespace.
    } elseif ($case === 'followup-queue-malformed') {
        $error = 'malformed'; $first = $notice(str_repeat('a', 32), ['intent_id' => 'pi_missing', 'attempts' => 19]);
    } elseif (str_starts_with($case, 'followup-queue-terminal')) {
        $exhaustedLogs = [];
        Log::listen(function ($event) use (&$exhaustedLogs, $case) {
            if (!str_starts_with($event->message, 'AeStripe saved method confirmation recovery exhausted')) return;
            $exhaustedLogs[] = $event->context;
            if ($case === 'followup-queue-terminal-log-failure') throw new RuntimeException('Synthetic logging outage');
        });
        $first = $notice(str_repeat('a', 32), ['intent_id' => 'pi_missing', 'attempts' => 19]);
    } else throw new RuntimeException('Unknown queue case');
    $key = $notice($token);
    if ($case === 'followup-queue-spaces') {
        $row = $user->properties()->where('key', $key)->first(); $row->update(['value' => json_encode(json_decode($row->value, true), JSON_PRETTY_PRINT)]);
    }
    $invoke('retrySavedMethodNotices');
    if ($case === 'followup-queue-progress') $invoke('retrySavedMethodNotices');
    if (str_starts_with($case, 'followup-queue-outage-')) {
        $assert(count($requests) === 1 && json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'pending', 'Global access/outage failure backs off without fanning out across the queue.');
        for ($i = 0; $i < 22; ++$i) {
            $state = json_decode($user->properties()->where('key', $first)->first()->value, true);
            $assert($state['state'] === 'pending' && $state['attempts'] === 19 && $state['retry_at'] > now()->timestamp, 'Provider outages preserve the near-cap attempt count and pending confirmation.');
            Illuminate\Support\Carbon::setTestNow(now()->addHours(2)); $invoke('retrySavedMethodNotices');
        }
        $error = 'restored'; Illuminate\Support\Carbon::setTestNow(now()->addHours(2)); $invoke('retrySavedMethodNotices');
        $assert(json_decode($user->properties()->where('key', $first)->first()->value, true)['state'] === 'ready', 'Restored provider access completes a near-cap consented save.');
    }
    if ($case === 'followup-queue-malformed') {
        $firstState = json_decode($user->properties()->where('key', $first)->first()->value, true);
        $assert($firstState['state'] === 'pending' && $firstState['attempts'] === 19, 'Unreadable provider responses preserve the near-cap confirmation budget.');
        $error = 'restored'; Illuminate\Support\Carbon::setTestNow(now()->addHours(2)); $invoke('retrySavedMethodNotices');
        $assert(json_decode($user->properties()->where('key', $first)->first()->value, true)['state'] === 'ready', 'Readable provider responses resume the original consented save.');
    }
    if (str_starts_with($case, 'followup-queue-terminal')) {
        $value = json_decode($user->properties()->where('key', $first)->first()->value, true);
        $assert($value['state'] === 'failed' && $value['attempts'] === 20, 'Irrecoverable notices stop at the bounded retry cap.');
        $invoke('retrySavedMethodNotices');
        $assert(count($exhaustedLogs) === 1 && ($exhaustedLogs[0]['invoice_id'] ?? null) === $invoice->id && ($exhaustedLogs[0]['intent_id'] ?? null) === 'pi_missing', 'Exhausted missing-intent recovery logs its invoice and intent once, including possible account-key mismatch.');
        $late = clone $intent; $late->id = 'pi_missing'; $invoke('savePaidMethod', $late);
        $assert(json_decode($user->properties()->where('key', $first)->first()->value, true)['state'] === 'ready', 'A late successful webhook may complete original work without a new debit.');
    }
    $value = json_decode($user->properties()->where('key', $key)->firstOrFail()->value, true);
    $assert(($value['state'] ?? null) === 'ready', 'Later valid confirmation recovers past missing or ineligible older reservations.');
    $assert(count(array_filter($requests, fn ($p) => str_contains($p, 'payment_intents'))) <= (str_starts_with($case, 'followup-queue-outage-') ? 26 : ($case === 'followup-queue-malformed' ? 3 : 2)), 'Ineligible records do not generate provider reads.');
} elseif (str_starts_with($case, 'followup-after-commit')) {
    $setDefault(); $armed = false;
    $failure = substr($case, strlen('followup-after-commit-'));
    if (in_array($failure, ['state', 'log'], true)) $user->properties()->create(['key' => 'ae_stripe_duplicate_pm_old', 'value' => json_encode(['customer' => 'cus_fixture', 'gateway' => $native->id, 'kept' => 'pm_other'])]);
    $cart = App\Models\Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
    $cart->items()->create(['product_id' => $product->id, 'plan_id' => $plan->id, 'quantity' => 1, 'checkout_config' => [], 'config_options' => []]);
    request()->cookies->set('cart', $cart->ulid); Illuminate\Support\Once::flush();
    $page = Livewire::withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('tos', true);
    Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionCommitting::class, function () use (&$armed) {
        if (DB::transactionLevel() === 1 && App\Models\Order::exists()) $armed = true;
    });
    DB::listen(function ($query) use (&$armed, $failure) {
        if (!$armed) return;
        $gatewayFault = str_starts_with($query->sql, 'select') && str_contains($query->sql, 'extensions') && in_array($failure === 'bundled' ? 'Stripe' : 'AeStripe', $query->bindings, true);
        $stateFault = str_starts_with($query->sql, 'update "properties"') && str_contains($query->sql, '"value"');
        if (($failure === 'gateway' || $failure === 'bundled') ? $gatewayFault : $stateFault) throw new RuntimeException('Synthetic after-commit ' . $failure . ' outage');
    });
    if ($failure === 'log') Log::listen(function () use (&$armed) { if ($armed) throw new RuntimeException('Synthetic log outage'); });
    try { $page->call('checkout'); } catch (Throwable $e) { $assert(false, 'A committed checkout must contain all callback recovery failures.'); }
    $armed = false; $service = Service::firstOrFail();
    $assert(App\Models\Order::count() === 1 && $pending($service) && $cart->items()->count() === 0, 'Committed order clears its cart and retains pending setup after callback outage.');
    Illuminate\Support\Once::flush();
    Livewire::withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('tos', true)->call('checkout');
    $assert(App\Models\Order::count() === 1, 'Resubmission of the cleared cart cannot duplicate an order.');
    $user->properties()->where('key', 'ae_stripe_duplicate_pm_old')->delete();
    $gateway->retryAutoPayInheritance(); $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === $old->id && !$pending($service), 'Later recovery completes the committed setup once.');
} elseif ($case === 'followup-retention') {
    $receipt = $user->properties()->create(['key' => 'ae_stripe_saved_retained', 'value' => json_encode(['payment' => 'pi_retained', 'notice_consumed' => false])]);
    foreach (['pending', 'ready', 'consumed'] as $index => $state) {
        $changes = ['state' => $state === 'consumed' ? 'ready' : $state, 'created_at' => now()->subDays(31)->timestamp,
            'consumed' => $state === 'consumed', 'notice' => ['type' => 'success', 'message' => 'Saved.'], 'receipt_key' => $receipt->key];
        if ($state === 'consumed') $changes['consumed_at'] = now()->subDays(31)->timestamp;
        $key = $notice(str_repeat((string) ($index + 1), 32), $changes);
        $user->properties()->where('key', $key)->update(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)]);
    }
    $legacy = $notice(str_repeat('f', 32));
    $legacyState = json_decode($user->properties()->where('key', $legacy)->first()->value, true); unset($legacyState['created_at']);
    $user->properties()->where('key', $legacy)->update(['value' => json_encode($legacyState), 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)]);
    $live = $notice($token, ['created_at' => now()->subDays(29)->timestamp]);
    $invoke('pruneSavedMethodNotices');
    $assert($receipt->fresh() !== null && json_decode($receipt->fresh()->value, true)['notice_consumed'] === true, 'Pruning preserves payment/consent receipts and prevents replay revival.');
    $assert($user->properties()->where('key', 'like', 'ae_stripe_notice_%')->count() === 1 && $user->properties()->where('key', $live)->exists(), 'Retention removes abandoned pending and ready notices while preserving recoverable work.');
} elseif (in_array($case, ['followup-contention', 'followup-malformed', 'followup-transfer', 'followup-transfer-rollback', 'followup-prune-failure'], true) || str_starts_with($case, 'followup-stopped')) {
    $setDefault(); $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
    try { $service = DB::transaction(fn () => $create()); } finally { $lock->release(); }
    $assert($pending($service), 'Fixture has pending work.');
    if ($case === 'followup-contention') {
        $lock->get(); try { for ($i = 0; $i < 15; ++$i) $gateway->retryAutoPayInheritance(); } finally { $lock->release(); }
        $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Ordinary cache contention cannot exhaust valid setup.');
    } elseif (str_starts_with($case, 'followup-stopped')) {
        $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->update(['value' => json_encode($record + ['retry' => ['attempts' => 10, 'state' => 'stopped']])]);
        $change = substr($case, strlen('followup-stopped-'));
        if ($change === 'off') $gateway->saveAutoPayDefault(Illuminate\Http\Request::create('/', 'POST', ['enabled' => false]));
        elseif ($change === 'removed') $old->delete();
        elseif ($change === 'cancelled') $service->update(['status' => Service::STATUS_CANCELLED]);
        elseif ($change === 'plan') $service->update(['plan_id' => $product->plans()->create(['name' => 'Once', 'type' => 'one-time'])->id]);
        else $service->update(['subscription_id' => 'sub_existing']);
        $invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $gateway->retryAutoPayInheritance();
        foreach ([App\Livewire\Services\Show::class => ['service' => $service->fresh()], App\Livewire\Invoices\Show::class => ['invoice' => $invoice->fresh()]] as $class => $parameters) {
            $assert(!$delivered(Livewire::test($class, $parameters)), 'Ineligible stopped work does not advertise unusable manual selection.');
        }
        $assert(!$pending($service), 'Stopped work is revalidated and retired after subscription assignment.');
    } elseif ($case === 'followup-malformed') {
        $lock->get(); try { $healthy = DB::transaction(fn () => $create()); } finally { $lock->release(); }
        $invoice->items()->first()->update(['reference_type' => Service::class, 'reference_id' => $service->id]);
        $invoice->items()->create(['quantity' => 1, 'price' => 20, 'description' => 'Healthy service', 'reference_type' => Service::class, 'reference_id' => $healthy->id]);
        foreach (['broken', 'null', '7', 'true', '"text"', '{}'] as $bad) {
            $service->properties()->where('key', 'ae_stripe_auto_pay_pending')->update(['value' => $bad]);
            try { $page = Livewire::test(App\Livewire\Services\Show::class, ['service' => $service->fresh()]); }
            catch (Throwable $e) { $assert(false, 'Malformed pending metadata must not break customer pages.'); }
            $assert(!$delivered($page), 'Malformed metadata produces no misleading pending instruction.');
            $assert(count($delivered(Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]))) === 1, 'Malformed metadata cannot hide a healthy invoice service.');
        }
        $gateway->retryAutoPayInheritance();
        $assert($service->fresh()->billing_agreement_id === null && $healthy->fresh()->billing_agreement_id === $old->id, 'Malformed metadata never authorizes binding; healthy work still completes.');
    } elseif ($case === 'followup-transfer-rollback') {
        $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']);
        try { DB::transaction(function () use ($service, $otherUser) { $service->update(['user_id' => $otherUser->id]); throw new RuntimeException('Rollback'); }); } catch (RuntimeException $e) {}
        $assert($service->fresh()->user_id === $user->id && $pending($service), 'Rolled-back transfer preserves original owner and pending work together.');
        $gateway->retryAutoPayInheritance(); $assert($service->fresh()->billing_agreement_id === $old->id, 'Original consent remains usable after rollback.');
    } elseif ($case === 'followup-transfer') {
        $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']);
        $interrupted = false;
        DB::listen(function ($query) use (&$interrupted) {
            if ($interrupted || !str_starts_with($query->sql, 'update "services"') || !str_contains($query->sql, '"user_id"')) return;
            $interrupted = true; throw new RuntimeException('Synthetic interruption after owner SQL');
        });
        try { $service->update(['user_id' => $otherUser->id]); } catch (RuntimeException $e) {}
        $service->refresh()->update(['user_id' => $user->id]); $gateway->retryAutoPayInheritance();
        $assert($interrupted && $service->fresh()->billing_agreement_id === null, 'Interrupted ownership round trip cannot revive old work.');
    } else {
        $fail = true;
        DB::listen(function ($query) use (&$fail) { if ($fail && str_contains($query->sql, 'properties') && in_array('ae_stripe_notice_%', $query->bindings, true)) throw new RuntimeException('Synthetic pruning error'); });
        try { $gateway->retryAutoPayInheritance(); } catch (Throwable $e) { $assert(false, 'Notice pruning failure must not block inheritance.'); }
        $fail = false;
        $assert($service->fresh()->billing_agreement_id === $old->id, 'Pending setup proceeds despite notice pruning failure.');
    }
} elseif ($case === 'followup-poll') {
    $notice($token); $invoice->update(['status' => 'paid']);
    $page = Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $page->call('checkPaymentStatus');
    $assert(str_contains($page->html(), 'data-ae-stripe-notice-poll'), 'Deferred notice keeps its own updates after payment polling stops.');
    $invoke('savePaidMethod', $intent); $page->call('$refresh');
    $assert(count($delivered($page)) === 1, 'Deferred notice reaches the settled invoice without a page refresh.');
    $page->call('$refresh');
    $assert(!str_contains($page->html(), 'data-ae-stripe-notice-poll'), 'Notice updates stop after terminal delivery.');
    $locks = 0; DB::listen(function ($query) use (&$locks) { if (DB::transactionLevel() > 0 && str_starts_with($query->sql, 'select * from "users"')) ++$locks; });
    $page->call('$refresh'); $assert($locks === 0, 'Consumed notice memo does not keep locking the owner row.');
} else throw new RuntimeException('Unknown follow-up case');
echo "PASS: $checks pending follow-up checks ($case, $theme)\n";
