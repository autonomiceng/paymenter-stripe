<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native owner events, transactions and Livewire claims cannot be proved by the offline models.
use App\Models\{Invoice, Property, Service, User};
use Illuminate\Support\Facades\{Cache, DB, Http, Log};
use Livewire\Livewire;
if ($case === 'final-transfer-race' || $case === 'final-transfer-order') {
    $setDefault(); $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600); $lock->get();
    try { $service = DB::transaction(fn () => $create()); } finally { $lock->release(); }
    $otherUser = User::create(['email' => 'other@example.com', 'password' => 'synthetic']);
    $fired = false; $order = [];
    DB::listen(function ($query) use (&$fired, &$order, $case, $service, $record) {
        if (str_starts_with($query->sql, 'select * from "users"')) $order[] = 'user';
        if (str_starts_with($query->sql, 'select * from "services"')) $order[] = 'service';
        if ($fired || !str_starts_with($query->sql, 'select') || !in_array('ae_stripe_auto_pay_pending', $query->bindings, true)) return;
        $order[] = 'property'; $fired = true;
        if ($case === 'final-transfer-race') DB::table('properties')->where('model_type', Service::class)->where('model_id', $service->id)->where('key', 'ae_stripe_auto_pay_pending')->update(['value' => json_encode($record + ['retry' => ['attempts' => 1, 'state' => 'pending']])]);
    });
    $refused = false;
    try { $service->update(['user_id' => $otherUser->id]); } catch (Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { $refused = true; }
    if ($case === 'final-transfer-race') {
        $assert($fired && $refused && $service->fresh()->user_id === $user->id, 'A missed pending cancellation refuses the ownership write.');
        $service->refresh()->update(['user_id' => $otherUser->id]);
    } else $assert(array_slice($order, 0, 3) === ['user', 'service', 'property'], 'Transfer invalidation locks user then service then pending property.');
    $service->refresh()->update(['user_id' => $user->id]); $gateway->retryAutoPayInheritance();
    $assert($service->fresh()->billing_agreement_id === null && !$pending($service), 'A retry race cannot restore old pending consent after an ownership round trip.');
} elseif (preg_match('/\Afinal-method-(\d+|connection)\z/', $case)) {
    $key = $notice($token, ['attempts' => 19]); $methodError = substr($case, strlen('final-method-'));
    if ($methodError !== 'connection') $methodError = (int) $methodError;
    $invoke('retrySavedMethodNotices');
    $state = json_decode($user->properties()->where('key', $key)->first()->value, true);
    $assert($state['state'] === 'pending' && $state['attempts'] === 19, 'A saving-stage missing method preserves the succeeded intent confirmation budget.');
    $methodError = null; Illuminate\Support\Carbon::setTestNow(now()->addDays(2)); $invoke('retrySavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'Method access restoration completes the original confirmation.');
} elseif ($case === 'final-orphans') {
    $orphan = User::create(['email' => 'orphan@example.com', 'password' => 'synthetic']);
    $rows = [];
    for ($i = 0; $i < 440; ++$i) $rows[] = ['model_type' => User::class, 'model_id' => $orphan->id, 'key' => 'ae_stripe_notice_orphan_' . $i,
        'value' => json_encode(['state' => $i % 2 ? 'pending' : 'ready', 'created_at' => now()->subDays(31)->timestamp]), 'created_at' => now(), 'updated_at' => now()];
    DB::table('properties')->insert($rows); $orphan->delete();
    $assert(Property::where('model_id', $orphan->id)->where('model_type', User::class)->count() === 440, 'Native user deletion leaves orphan property rows.');
    $expired = $notice(str_repeat('a', 32), ['state' => 'ready', 'created_at' => now()->subDays(31)->timestamp]);
    $key = $notice($token); $invoke('retrySavedMethodNotices'); $invoke('pruneSavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'Orphans cannot consume the live recovery batch.');
    $assert(!$user->properties()->where('key', $expired)->exists(), 'Orphans cannot consume the live retention batch.');
} elseif ($case === 'final-retention') {
    $receipt = $user->properties()->create(['key' => 'ae_stripe_saved_retention', 'value' => '{"notice_consumed":false}']);
    foreach (['pending', 'ready', 'consumed'] as $i => $state) {
        $key = $notice(str_repeat((string) ($i + 1), 32), ['state' => $state, 'created_at' => 'invalid date', 'receipt_key' => $receipt->key, 'consumed' => $state === 'consumed']);
        $user->properties()->where('key', $key)->update(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)]);
    }
    $legacy = $notice(str_repeat('a', 32), ['created_at' => null]);
    $user->properties()->where('key', $legacy)->update(['created_at' => null, 'updated_at' => null]);
    $live = $notice($token, ['created_at' => now()->subDays(29)->timestamp]); $invoke('pruneSavedMethodNotices');
    $assert($user->properties()->where('key', 'like', 'ae_stripe_notice_%')->count() === 2, 'Malformed old dates expire using safe property timestamps; unknown age stays recoverable.');
    $assert(json_decode($receipt->fresh()->value, true)['notice_consumed'] === true, 'Date fallback preserves receipt consumption history.');
    $assert($user->properties()->where('key', $live)->exists() && is_numeric(json_decode($user->properties()->where('key', $legacy)->first()->value, true)['created_at']), 'Unknown age is initialized once without discarding a recent save.');
} elseif ($case === 'final-rotation') {
    for ($i = 0; $i < 210; ++$i) $notice(str_pad(dechex($i), 32, '0', STR_PAD_LEFT), ['retry_at' => now()->addDay()->timestamp]);
    $key = $notice($token); $writes = 0; $locks = 0;
    DB::listen(function ($query) use (&$writes, &$locks) {
        if (str_starts_with($query->sql, 'update "properties"')) ++$writes;
        if (DB::transactionLevel() && str_starts_with($query->sql, 'select * from "users"')) ++$locks;
    });
    $invoke('pruneSavedMethodNotices'); $invoke('pruneSavedMethodNotices'); $invoke('retrySavedMethodNotices');
    $assert($writes === 0 && $locks === 0, 'Young and future-due notices rotate without owner locks or metadata writes.');
    $invoke('retrySavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'A bounded cursor reaches live work past a full future-due batch.');
} elseif (str_starts_with($case, 'final-row-')) {
    $error = (int) substr($case, strlen('final-row-')); $first = $notice(str_repeat('a', 32), ['intent_id' => 'pi_missing', 'attempts' => 19]);
    $key = $notice($token); $invoke('retrySavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'A row-specific refusal does not stop another customer confirmation.');
    $state = json_decode($user->properties()->where('key', $first)->first()->value, true);
    $assert($state['attempts'] === 19 && $state['state'] === 'pending', 'An uncertain row refusal never exhausts consented saving.');
} elseif (str_starts_with($case, 'final-backoff') || $case === 'final-diagnostics') {
    $logs = []; Log::listen(function ($event) use (&$logs) { $logs[] = [$event->message, $event->context]; });
    $backingOff = str_starts_with($case, 'final-backoff'); $attempts = $case === 'final-backoff' ? 19 : 0;
    $error = $backingOff ? 'malformed' : 404;
    $key = $notice($token, ['intent_id' => 'pi_missing', 'attempts' => $attempts]); $delays = [];
    for ($i = 0; $i < 8; ++$i) {
        $invoke('retrySavedMethodNotices'); $state = json_decode($user->properties()->where('key', $key)->first()->value, true);
        $delays[] = $state['retry_at'] - now()->timestamp;
        if ($backingOff) $assert($state['state'] === 'pending' && $state['attempts'] === $attempts, 'Independent backoff cannot exhaust the intent budget.');
        Illuminate\Support\Carbon::setTestNow(now()->addSeconds($delays[array_key_last($delays)] + 1));
    }
    if ($backingOff) $assert($delays[1] > $delays[0] && max($delays) <= 3600, 'Transient delays grow independently and remain bounded.');
    else $assert(count($logs) > 0 && count($logs) <= 8 && !str_contains(json_encode($logs), 'rk_test_fixture'), 'Missing account access is diagnosed before exhaustion without exposing the key.');
    $error = 'restored'; $invoke('retrySavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'Restored access completes the retained destination.');
} elseif ($case === 'final-late-notice' || $case === 'final-delay-notice') {
    $key = $notice($token, ['state' => $case === 'final-late-notice' ? 'failed' : 'pending', 'backoff' => 3]); $invoice->update(['status' => 'paid']);
    $page = Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    if ($case === 'final-late-notice') $assert(!str_contains($page->html(), 'data-ae-stripe-notice-poll'), 'Failed notices stop automatic polling.');
    else {
        $assert(count($delivered($page)) === 1 && str_contains($delivered($page)[0]['params'][0]['message'], 'delayed'), 'The original tab explains delayed saving.');
        $assert(str_contains($page->html(), 'wire:poll.60s'), 'A backed-off save bounds browser polling.');
        $page->call('$refresh'); $assert(!$delivered($page), 'The delay warning is shown once per component.');
    }
    $invoke('savePaidMethod', $intent); $page->call('$refresh');
    $assert(count($delivered($page)) === 1 && $delivered($page)[0]['params'][0]['type'] === 'success', 'An explicit refresh can see a late webhook success after failure.');
    $page->call('$refresh'); $assert(!$delivered($page), 'The durable claim prevents repeated late confirmations.');
} elseif ($case === 'final-logging' || $case === 'final-clean-log') {
    $key = $notice($token); $logs = [];
    Log::listen(function ($event) use (&$logs) { $logs[] = $event->message; });
    if ($case === 'final-logging') DB::listen(function ($query) {
        if (DB::transactionLevel() && str_starts_with($query->sql, 'select') && in_array('ae_stripe_notice_%', $query->bindings, true)) throw new RuntimeException('Synthetic notice completion outage');
    });
    for ($i = 0; $i < ($case === 'final-logging' ? 5 : 1); ++$i) {
        $invoke('retrySavedMethodNotices'); $state = json_decode($user->properties()->where('key', $key)->first()->value, true);
        if ($case === 'final-logging') Illuminate\Support\Carbon::setTestNow(now()->addSeconds($state['retry_at'] - now()->timestamp + 1));
    }
    $assert(count($logs) === ($case === 'final-logging' ? 1 : 0), 'Recovery warnings are bounded and successful saving emits no outage warning.');
} elseif ($case === 'final-replacement') {
    $key = $notice($token); $replacement = null;
    $user->properties()->create(['key' => 'fixture_anchor', 'value' => '1']);
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests();
    Http::fake(function () use ($user, $key, &$replacement) {
        $value = $user->properties()->where('key', $key)->first()->value;
        $user->properties()->where('key', $key)->delete();
        $replacement = $user->properties()->create(['key' => $key, 'value' => $value]);
        return Http::response(['error' => ['code' => 'resource_missing']], 404);
    });
    $invoke('retrySavedMethodNotices');
    $assert($replacement && !isset(json_decode($replacement->fresh()->value, true)['retry_at']), 'An equal-value replacement row is not the reservation read before HTTP.');
} elseif ($case === 'final-prune-isolation') {
    $first = $notice(str_repeat('a', 32), ['state' => 'ready', 'created_at' => now()->subDays(31)->timestamp]);
    $key = $notice($token, ['state' => 'ready', 'created_at' => now()->subDays(31)->timestamp]);
    DB::listen(function ($query) use ($first) {
        if (DB::transactionLevel() && str_starts_with($query->sql, 'select') && in_array($first, $query->bindings, true)) throw new RuntimeException('Synthetic per-row retention failure');
    });
    try { $invoke('pruneSavedMethodNotices'); } catch (Throwable $e) { $assert(false, 'A per-row retention failure must not stop the batch.'); }
    $assert($user->properties()->where('key', $first)->exists() && !$user->properties()->where('key', $key)->exists(), 'An individual failure leaves its row retryable and retires unrelated old metadata.');
} elseif ($case === 'final-terminal-status' || $case === 'final-diagnostics-account') {
    $logs = []; Log::listen(function ($event) use (&$logs) { $logs[] = $event->message; });
    if ($case === 'final-terminal-status') {
        $intent->status = 'processing'; $notice($token, ['attempts' => 19]); $invoke('retrySavedMethodNotices');
        $assert(count($logs) === 1 && str_contains($logs[0], 'intent did not succeed') && !str_contains($logs[0], 'account'), 'A genuine non-succeeded intent has a truthful exhaustion diagnostic.');
    } else {
        for ($i = 0; $i < 30; ++$i) $notice(str_pad(dechex($i), 32, '0', STR_PAD_LEFT), ['intent_id' => 'pi_missing_' . $i]);
        $invoke('retrySavedMethodNotices');
        $assert(count($logs) === 1 && count($requests) === 30, 'Wrong-account diagnostics are aggregated across rows before their budgets expire.');
    }
} elseif (str_starts_with($case, 'final-method-row-')) {
    $status = (int) substr($case, strlen('final-method-row-'));
    $first = $notice(str_repeat('a', 32), ['intent_id' => 'pi_badmethod', 'attempts' => 19]); $key = $notice($token);
    Http::swap(new Illuminate\Http\Client\Factory); Http::preventStrayRequests();
    Http::fake(function ($request) use ($status, $intent, &$methods, $assert) {
        $assert(DB::transactionLevel() === 0, 'Saving-stage isolation HTTP stays outside owner locks.');
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, '/payment_intents/')) return Http::response(str_ends_with($path, '/pi_badmethod') ? array_replace((array) $intent, ['id' => 'pi_badmethod', 'payment_method' => 'pm_bad']) : (array) $intent);
        if (str_ends_with($path, '/pm_bad')) return Http::response(['error' => ['code' => 'api_error']], $status);
        return Http::response($methods[basename($path)]);
    });
    $invoke('retrySavedMethodNotices');
    $assert(json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready', 'A saving-stage row refusal does not block a later live confirmation.');
    $assert(json_decode($user->properties()->where('key', $first)->first()->value, true)['attempts'] === 19, 'A saving-stage refusal preserves the intent budget.');
} elseif ($case === 'final-render-pcre') {
    // Exercise the real invoice render hook: a callback-only unit cannot prove checkout survives.
    require __DIR__ . '/native-render-failure.php';
    $key = $notice($token); $rendered = null;
    app(\Livewire\EventBus::class)->after('render', function ($component) use (&$rendered) {
        if (!$component instanceof App\Livewire\Invoices\Show) return;
        return function ($html) use (&$rendered) { $rendered = $html; };
    });
    $failure = null;
    try { Livewire::withQueryParams(['saved_notice' => $token])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]); }
    catch (Throwable $e) { $failure = $e; }
    $assert(Paymenter\Extensions\Gateways\AeStripe\RenderFailure::$hits === 1, 'The extension notice injection actually received the PCRE null result.');
    $assert(!$failure && is_string($rendered) && str_contains($rendered, 'wire:id') && str_contains($rendered, 'showPayModal'), 'A failed notice injection preserves the unpaid invoice and checkout controls.');
    $assert(!$requests && $invoice->fresh()->status === 'pending' && json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'pending', 'Render failure neither charges nor consumes the saved-card confirmation.');
} elseif ($case === 'final-browser') {
    mkdir($fixtureViews . '/invoices', 0700, true);
    $view = file_get_contents(($themeRoot ?: $release . '/themes/default') . '/views/invoices/show.blade.php');
    $view = preg_replace('/<div\b/', '<div data-fixture="quoted > root"', $view, 1);
    file_put_contents($fixtureViews . '/invoices/show.blade.php', " \n<!-- invoice fixture -->\n" . $view);
    $app['view']->prependLocation($fixtureViews);
    $key = $notice($token); $invoice->update(['status' => 'paid']);
    $page = Livewire::withQueryParams(['saved_notice' => $token, 'checkPayment' => 'true'])->test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $page->call('checkPaymentStatus'); $assert($page->get('checkPayment') === false, 'The native payment poll has stopped.');
    $initial = \Livewire\Drawer\Utils::insertAttributesIntoHtmlRoot($page->html(), ['wire:snapshot' => json_encode($page->snapshot), 'wire:effects' => '{}']);
    $methodError = 503; $invoke('retrySavedMethodNotices'); $page->call('$refresh');
    $response = fn () => (new ReflectionProperty($page, 'lastState'))->getValue($page)->getResponse()->json();
    $delayed = $response(); $methodError = null; $invoke('savePaidMethod', $intent); $invoke('savePaidMethod', $intent); $page->call('$refresh'); $ready = $response();
    $otherTab = Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()])->html();
    $fixture = $root . '/browser.json';
    file_put_contents($fixture, json_encode(['html' => $initial, 'other' => $otherTab, 'responses' => [$delayed, $ready], 'javascript' => $release . '/vendor/livewire/livewire/dist/livewire.js'], JSON_THROW_ON_ERROR));
    $run = proc_open([getenv('PAYMENTER_TEST_NODE') ?: 'node', __DIR__ . '/native-notice-poll.mjs', $fixture], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    $assert(is_resource($run) && proc_close($run) === 0, 'The real browser polls the native component and delivers once without manual refresh.');
} else throw new RuntimeException('Unknown final follow-up case');
echo "PASS: $checks final follow-up checks ($case, $theme)\n";
