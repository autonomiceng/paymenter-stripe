<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Real sessions with normal model and console auditing. A pauses before a statement while B finishes or waits.
// Check the declared wait, deadlock count, both results and exact final rows. Only rollback-retry creates a deadlock.
// Usage: PAY_MARIADB_PORT=... PAY_MARIADB_PASSWORD=... php tests/mariadb-locks.php
/* Fail-first cases: deleted-notice-recipients, refusal-events, notice-retry-paused, notice-retry-owner,
 * notice-retry-scope, notice-retry-fairness, staff-reversals, staff-attempt-review, staff-reversal-notice-retry,
 * pay-return-card-save, access-staff-settlement, subscription-review-vs-settlement, gateway-review,
 * gateway-access, pay-pay, pay-settlement, settlement-settlement, settlement-card-label,
 * subscription-subscription, fee-fee, receipt-returns, rollback-retry.
 * Continuity cases, with no separate red claim: access-notice-retry, subscription-audit,
 * subscription-review-duplicate, gateway-state-current, gateway-events, gateway-signature, saved-cards,
 * saved-cards-duplicate, same-payment-duplicate, same-payment-return, subscription-owner, reversal-duplicate.
 */
use App\Models\{Invoice, User};
use Illuminate\Support\Facades\DB;
$install = ($argv[1] ?? null) !== 'worker';
require __DIR__ . '/mariadb-app.php';
$count = 0;
function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
/** Customer $id, with Stripe customer cus_$id, owning a pending invoice of the total. */
function customerInvoice(int $userId, int $invoiceId, float $total = 20): Invoice
{
    $user = User::find($userId) ?? User::forceCreate(['id' => $userId, 'first_name' => 'Customer', 'last_name' => (string) $userId,
        'email' => "customer$userId@example.test", 'password' => 'synthetic']);
    if (!$user->properties()->where('key', 'stripe_id')->exists()) $user->properties()->create(['key' => 'stripe_id', 'value' => 'cus_' . $userId]);
    $invoice = Invoice::forceCreate(['id' => $invoiceId, 'user_id' => $userId, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => now()->addWeek()]);
    $invoice->items()->create(['quantity' => 1, 'price' => $total, 'description' => 'Fixture']);
    return $invoice;
}
/** The Stripe PaymentIntent pi_<invoice> of an invoice's customer, as Stripe reports it. */
function intentOf(int $invoiceId, string $status, int $received = 0): object
{
    $invoice = Invoice::findOrFail($invoiceId);
    return (object) ['id' => 'pi_' . $invoiceId, 'object' => 'payment_intent', 'status' => $status, 'amount' => 2000, 'amount_received' => $received,
        'currency' => 'usd', 'customer' => 'cus_' . $invoice->user_id, 'confirmation_method' => 'manual', 'payment_method_types' => ['card'],
        'client_secret' => 'pi_secret', 'metadata' => (object) ['invoice_id' => (string) $invoiceId, 'ae_stripe_gateway' => '1'], 'latest_charge' => (object) ['id' => 'ch_' . $invoiceId, 'disputed' => false,
            'payment_method_details' => (object) ['type' => 'card', 'card' => (object) ['brand' => 'visa', 'last4' => '4242']]]];
}
/** Settle an invoice's PaymentIntent as its succeeded webhook does, and any receipt return it queues after commit. */
function settle($gateway, int $invoiceId, int $received = 2000): string
{
    invoke($gateway, 'recordIntentPayment', intentOf($invoiceId, 'succeeded', $received), 'succeeded');
    return 'settled';
}
/** The customer's pending invoice for a subscription service, sub_<invoice>. */
function subscriptionInvoice(int $userId, int $invoiceId): void
{
    $invoice = customerInvoice($userId, $invoiceId);
    $product = App\Models\Product::create(['category_id' => App\Models\Category::create(['slug' => 'locks' . $invoiceId, 'name' => 'Locks'])->id,
        'name' => 'Locks', 'slug' => 'locks' . $invoiceId]);
    $plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
    $service = App\Models\Service::create(['user_id' => $userId, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 20,
        'currency_code' => 'USD', 'quantity' => 1, 'status' => 'active', 'subscription_id' => 'sub_' . $invoiceId]);
    $invoice->items()->update(['reference_type' => App\Models\Service::class, 'reference_id' => $service->id]);
}
/** The paid Stripe invoice of that subscription, paid with pi_<invoice>. */
function paidSubscription(int $invoiceId): object
{
    return (object) ['id' => 'in_' . $invoiceId, 'currency' => 'usd', 'amount_paid' => 2000, 'metadata' => (object) ['ae_stripe_invoice_id' => (string) $invoiceId],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_' . $invoiceId]]];
}
// Stripe as the requests see it: Pay creates one intent per invoice, a refund of a receipt return fails, so the return becomes account credit, a confirmation of pi_401 is declined, and each charge's fee is 0.50 USD.
$GLOBALS['stripeHandler'] = function ($verb, $path, $data) {
    static $declined = false;
    if ($verb === 'get' && preg_match('#^/customers/(cus_\d+)$#', $path, $id)) return ['id' => $id[1]];
    if ($verb === 'post' && $path === '/customer_sessions') return ['client_secret' => 'cuss_' . $data['customer']];
    if ($verb === 'post' && $path === '/payment_intents') return ['id' => 'pi_' . $data['metadata']['invoice_id'], 'status' => 'requires_payment_method',
        'amount' => $data['amount'], 'amount_received' => 0, 'currency' => strtolower($data['currency']), 'customer' => $data['customer'],
        'confirmation_method' => 'automatic', 'payment_method_types' => $data['payment_method_types'], 'latest_charge' => null,
        'client_secret' => 'pi_secret', 'metadata' => $data['metadata']];
    if (preg_match('#^/payment_intents/pi_(\d+)(/confirm)?$#', $path, $id)) {
        // Invoice 401 is paid with Pay now; every other intent has succeeded, overpaying 6xx invoices.
        if ($id[1] !== '401') return intentOf((int) $id[1], 'succeeded', $id[1][0] === '6' ? 3000 : 2000);
        $intent = intentOf(401, 'requires_payment_method');
        $intent->latest_charge = null;
        if (($id[2] ?? '') === '/confirm') $declined = true;
        if ($declined) $intent->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'];
        if (($id[2] ?? '') === '/confirm') throw new StripeFixtureError(402, ['error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds']]);
        return $intent;
    }
    if (preg_match('#^/payment_methods/(pm_(\\d+))$#', $path, $id)) return ['id' => $id[1], 'customer' => 'cus_' . $id[2],
        'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'card_' . $id[2], 'exp_year' => 2030, 'exp_month' => 5]];
    if ($verb === 'get' && preg_match('#^/balance_transactions/(txn_\d+)$#', $path, $id)) return ['id' => $id[1], 'fee' => 50, 'currency' => 'usd'];
    if ($verb === 'get' && $path === '/refunds') return ['has_more' => false, 'data' => []];
    if ($verb === 'get' && preg_match('#^/refunds/re_(\d+)$#', $path, $id)) return ['id' => 're_' . $id[1], 'payment_intent' => 'pi_' . $id[1],
        'currency' => 'usd', 'amount' => 2000, 'status' => 'succeeded', 'created' => time()];
    if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [['status' => 'paid', 'amount_paid' => 2000,
        'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_' . substr($data['invoice'], 3)]]]];
    if ($verb === 'post' && $path === '/refunds') return ['id' => 're_' . $data['payment_intent'], 'payment_intent' => $data['payment_intent'],
        'amount' => (int) $data['amount'], 'currency' => 'usd', 'status' => 'failed', 'metadata' => $data['metadata']];
    throw new RuntimeException('Unexpected Stripe request: ' . $verb . ' ' . $path);
};
// Cases: seed (driver), A and B (worker processes), where A pauses, and the rows both must leave behind.
$attempt = fn (int $user, int $invoice) => json_decode(User::find($user)->properties()->where('key', 'ae_stripe_checkout_' . $invoice)->value('value') ?? 'null', true);
$rows = fn (int $invoice) => DB::table('invoice_transactions')->where('invoice_id', $invoice)->orderBy('id')->get(['amount', 'status', 'is_credit_transaction'])
    ->map(fn ($row) => [(float) $row->amount, $row->status, (bool) $row->is_credit_transaction])->all();
$payAndSave = function ($gateway, int $userId, int $invoiceId) {
    config(['cache.default' => 'database']);
    Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class, fn ($event) => fwrite(STDERR, $event->message . ' ' . json_encode($event->context) . "\n"));
    auth()->login(User::find($userId));
    $page = $gateway->finishPayment(Illuminate\Http\Request::create('/', 'GET', ['invoice' => $invoiceId,
        'payment_intent' => 'pi_' . $invoiceId, 'payment_intent_client_secret' => 'pi_secret']));
    return $page instanceof Illuminate\Http\RedirectResponse ? 'redirect' : $page->getData()['message'];
};
$defaultStripe = $GLOBALS['stripeHandler'];
$GLOBALS['stripeHandler'] = function ($verb, $path, $data, $request) use ($defaultStripe) {
    if (preg_match('#^/payment_methods/pm_(80[12])$#', $path, $id)) return [ 'id' => 'pm_' . $id[1], 'customer' => 'cus_' . ($id[1] === '801' ? 7 : 8), 'type' => 'card',
        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030, 'fingerprint' => 'fingerprint' . $id[1]], 'allow_redisplay' => 'always'];
    $answer = $defaultStripe($verb, $path, $data, $request);
    if ($verb === 'get' && preg_match('#^/payment_intents/pi_(80[12])$#', $path, $id)) {
        $answer->metadata->ae_stripe_consent = '1';
        $answer->metadata->ae_stripe_consent_at = (string) time();
        $answer->payment_method = 'pm_' . $id[1];
        $answer->payment_method_options = (object) ['card' => (object) ['setup_future_usage' => 'off_session']];
    }
    return $answer;
};
function invoiceStaff(): void
{
    $role = App\Models\Role::create(['name' => 'Invoice staff', 'permissions' => ['admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update']]);
    foreach ([7, 8] as $id) User::find($id)->update(['role_id' => $role->id]);
}
function pendingNotice(string $event, string $type = Invoice::class): void
{
    App\Models\Audit::create(['event' => $event, 'auditable_type' => $type, 'auditable_id' => 591,
        'old_values' => [], 'new_values' => ['notice_sent' => false, 'notices' => [[
            'user_id' => 7, 'title' => 'Queued payment notice', 'body' => 'Fixture', 'url' => '/',
        ]]]]);
}
$cases = [
    'notice-retry-paused' => ['seed' => function () {
        customerInvoice(7, 591); customerInvoice(8, 592);
        pendingNotice('ae_stripe_attempt_review');
    }, 'pause' => '/^paused-notices-checked$/', 'a' => function ($gateway) {
        App\Models\Gateway::where('extension', 'AeStripe')->update(['enabled' => false]);
        $queries = 0;
        DB::listen(function ($query) use (&$queries) { if (str_contains($query->sql, 'notice_sent')) ++$queries; });
        $gateway->retryChargeAttempts(); $gateway->retryReceiptReturns(); $gateway->retryPaymentFees();
        check($queries === 0 && DB::table('notifications')->count() === 0, 'Paused gateway never queries or delivers the notice retry queue');
        pausePoint('paused-notices-checked');
        return 'paused';
    }, 'b' => fn ($gateway) => settle($gateway, 592),
    'check' => fn () => App\Models\Audit::where('event', 'ae_stripe_attempt_review')->where('new_values->notice_sent', false)->count() === 1
        && DB::table('notifications')->count() === 0 && Invoice::find(592)->status === 'paid'],
    'notice-retry-owner' => ['seed' => function () {
        customerInvoice(7, 591); customerInvoice(8, 592);
        pendingNotice('ae_stripe_attempt_review');
    }, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
        $gateway->retryReceiptReturns(); $gateway->retryPaymentFees();
        check(DB::table('notifications')->count() === 0, 'Receipt and fee schedulers leave queued notices to charge recovery');
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        return 'retried';
    }, 'b' => fn ($gateway) => settle($gateway, 592),
    'check' => fn () => App\Models\Audit::where('event', 'ae_stripe_attempt_review')->where('new_values->notice_sent', true)->count() === 1
        && DB::table('notifications')->count() === 1 && Invoice::find(592)->status === 'paid'],
    'notice-retry-scope' => ['seed' => function () {
        customerInvoice(7, 591); customerInvoice(8, 592);
        foreach (range(1, 100) as $unused) pendingNotice('updated');
        pendingNotice('ae_stripe_attempt_review', User::class);
        foreach (['ae_stripe_attempt_review', 'ae_stripe_payment_refused', 'ae_stripe_access_refused', 'ae_stripe_payment_review',
            'ae_stripe_fee_review', 'ae_stripe_receipt_return', 'ae_stripe_receipt_reversal'] as $event) {
            pendingNotice($event, $event === 'ae_stripe_access_refused' ? App\Models\Gateway::class : Invoice::class);
        }
    }, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        return 'retried';
    }, 'b' => fn ($gateway) => settle($gateway, 592),
    'check' => fn () => App\Models\Audit::where('event', 'updated')->where('new_values->notice_sent', false)->count() === 100
        && App\Models\Audit::where('auditable_type', User::class)->where('event', 'ae_stripe_attempt_review')->where('new_values->notice_sent', false)->count() === 1
        && App\Models\Audit::where('new_values->notice_sent', true)->count() === 7
        && DB::table('notifications')->count() === 7 && Invoice::find(592)->status === 'paid'],
    'notice-retry-fairness' => ['seed' => function () {
        customerInvoice(7, 591); customerInvoice(8, 592);
        foreach (range(1, 101) as $unused) pendingNotice('ae_stripe_attempt_review');
    }, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
        $attempted = [];
        App\Models\Notification::created(function ($notice) use (&$attempted) {
            $attempted[] = $notice->title;
            throw new RuntimeException('Inbox still unavailable');
        });
        invoke($gateway, 'retryPaymentNotices');
        check(count($attempted) === 100, 'The first failing batch is bounded to 100 notices');
        pendingNotice('ae_stripe_attempt_review');
        $new = App\Models\Audit::where('event', 'ae_stripe_attempt_review')->latest('id')->first();
        $values = $new->new_values; $values['notices'][0]['title'] = 'New payment notice';
        $new->update(['new_values' => $values]);
        invoke($gateway, 'retryPaymentNotices');
        check(count($attempted) === 102 && in_array('New payment notice', $attempted, true),
            '101 persistently failing notices cannot starve a new notice');
        $pending = App\Models\Audit::where('event', 'ae_stripe_attempt_review')->get();
        check($pending->every(fn ($audit) => !$audit->new_values['notice_sent']
            && ($audit->new_values['notice_retry_at'] ?? 0) > time()), 'Every failed delivery retains a future retry time');
        invoke($gateway, 'retryPaymentNotices');
        check(count($attempted) === 102, 'Notices in backoff are not attempted again before their retry time');
        foreach ($pending as $audit) $audit->update(['new_values' => array_replace($audit->new_values, ['notice_retry_at' => time() - 1])]);
        invoke($gateway, 'retryPaymentNotices');
        check(count($attempted) === 202, 'Due failures get another bounded batch after their retry time');
        return 'retried';
    }, 'b' => fn ($gateway) => settle($gateway, 592),
    'check' => fn () => App\Models\Audit::where('event', 'ae_stripe_attempt_review')->where('new_values->notice_sent', false)->count() === 102
        && DB::table('notifications')->count() === 0 && Invoice::find(592)->status === 'paid'],
    'refusal-events' => ['seed' => function () {
        customerInvoice(7, 591); customerInvoice(8, 592);
    }, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
        $fail = true;
        App\Models\Notification::created(function () use (&$fail) {
            if ($fail) { $fail = false; throw new RuntimeException('First refusal delivery interrupted'); }
        });
        foreach (['first', 'second'] as $event) {
            User::find(7)->properties()->create(['key' => 'ae_stripe_checkout_591', 'value' => json_encode([
                'version' => 2, 'key' => 'checkout_' . $event, 'state' => 'reconcile'])]);
            $gateway->resolveChargeAttempt(Invoice::find(591), null, 'Verified no charge in the original account');
            if ($event === 'first') check(App\Models\Audit::where('event', 'ae_stripe_payment_refused')
                ->where('new_values->notice_sent', false)->count() === 1, 'Failed refusal delivery retains its own retry marker');
        }
        foreach (App\Models\Audit::where('new_values->notice_sent', false)->get() as $audit) {
            $audit->update(['new_values' => array_replace($audit->new_values, ['notice_retry_at' => 0])]);
        }
        invoke($gateway, 'retryPaymentNotices');
        invoke($gateway, 'retryPaymentNotices');
        return 'resolved';
    }, 'b' => fn ($gateway) => settle($gateway, 592),
    'check' => fn () => App\Models\Audit::where('event', 'ae_stripe_attempt_resolved')->count() === 2
        && App\Models\Audit::where('event', 'ae_stripe_payment_refused')->where('new_values->notice_sent', true)->count() === 2
        && DB::table('notifications')->where('user_id', 7)->where('title', 'Payment not completed')->count() === 2
        && Invoice::find(591)->status === 'pending' && Invoice::find(592)->status === 'paid'],
    // Real recipient foreign keys and a full retry batch expose deleted users blocking later notices.
    'deleted-notice-recipients' => ['seed' => function () {
        customerInvoice(7, 591); customerInvoice(8, 592);
        $deleted = User::forceCreate(['id' => 98, 'first_name' => 'Former', 'last_name' => 'Staff',
            'email' => 'former@example.test', 'password' => 'synthetic']);
        $notice = fn ($id) => ['user_id' => $id, 'title' => 'Queued payment notice', 'body' => 'Fixture', 'url' => '/'];
        foreach (array_merge(array_fill(0, 100, [98]), [[98, 7], [8]]) as $recipients) {
            App\Models\Audit::create(['event' => 'ae_stripe_attempt_review', 'auditable_type' => Invoice::class,
                'auditable_id' => 591, 'old_values' => [], 'new_values' => [
                    'notice_sent' => false, 'notices' => array_map($notice, $recipients)]]);
        }
        $deleted->delete();
    }, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
        invoke($gateway, 'retryPaymentNotices');
        check(App\Models\Audit::where('event', 'ae_stripe_attempt_review')->where('new_values->notice_sent', true)->count() === 100,
            'Deleted-only recipients complete the first retry batch');
        invoke($gateway, 'retryPaymentNotices');
        invoke($gateway, 'retryPaymentNotices');
        return 'retried';
    }, 'b' => fn ($gateway) => settle($gateway, 592),
    'check' => fn () => DB::table('notifications')->orderBy('user_id')->pluck('user_id')->all() === [7, 8]
        && App\Models\Audit::where('event', 'ae_stripe_attempt_review')->where('new_values->notice_sent', true)->count() === 102
        && Invoice::find(592)->status === 'paid'],
    'staff-reversal-notice-retry' => ['seed' => function () use ($gateway) {
        customerInvoice(7, 561); customerInvoice(8, 562);
        invoiceStaff();
        settle($gateway, 561);
    }, 'waits' => true, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
        $GLOBALS['pauses'] = []; $inserts = 0;
        App\Models\Notification::created(function () use (&$inserts) { if (++$inserts === 2) throw new RuntimeException('Delivery failed'); });
        invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 're_561', 'payment_intent' => 'pi_561'], 'refund');
        check(DB::table('notifications')->count() === 0 && DB::table('audits')->where('event', 'ae_stripe_receipt_reversal')
            ->where('new_values->notice_sent', false)->count() === 1, 'Notice rollback retains the committed reversal and pending audit');
        foreach (App\Models\Audit::where('new_values->notice_sent', false)->get() as $audit) {
            $audit->update(['new_values' => array_replace($audit->new_values, ['notice_retry_at' => 0])]);
        }
        pauseBefore('/^insert into `notifications`/');
        invoke($gateway, 'retryPaymentNotices');
        return 'retried';
    }, 'b' => function ($gateway) {
        invoke($gateway, 'retryPaymentNotices');
        invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 're_561', 'payment_intent' => 'pi_561'], 'refund');
        return 'retried';
    }, 'check' => fn () => DB::table('ae_stripe_receipt_returns')->count() === 1 && DB::table('notifications')->count() === 2
        && DB::table('audits')->where('event', 'ae_stripe_receipt_reversal')->where('new_values->notice_sent', true)->count() === 1],
    'staff-reversals' => ['seed' => function () use ($gateway) {
        customerInvoice(7, 561); customerInvoice(8, 562);
        invoiceStaff();
        settle($gateway, 561); settle($gateway, 562);
    }, 'pause' => '/^insert into `notifications`/',
    'a' => fn ($gateway) => invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 're_561', 'payment_intent' => 'pi_561'], 'refund') ?? 'recorded',
    'b' => fn ($gateway) => invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 're_562', 'payment_intent' => 'pi_562'], 'refund') ?? 'recorded',
    'check' => fn () => DB::table('ae_stripe_receipt_returns')->count() === 2 && DB::table('audits')->where('event', 'ae_stripe_receipt_reversal')->count() === 2
        && DB::table('notifications')->count() === 4], 'staff-attempt-review' => ['seed' => function () {
        customerInvoice(7, 571); customerInvoice(8, 572);
        invoiceStaff();
        foreach ([7 => 571, 8 => 572] as $user => $invoice) {
            User::find($user)->properties()->create(['key' => 'ae_stripe_checkout_' . $invoice, 'value' => json_encode([
                'key' => 'checkout_' . $invoice, 'state' => 'reconcile', 'reason' => 'Verify the original payment'])]);
        }
    }, 'pause' => '/^insert into `notifications`/', 'a' => fn ($gateway) => invoke($gateway, 'sendAttemptReview', Invoice::find(571)) ?? 'reviewed',
    'b' => fn ($gateway) => invoke($gateway, 'sendAttemptReview', Invoice::find(572)) ?? 'reviewed',
    'check' => fn () => DB::table('audits')->where('event', 'ae_stripe_attempt_review')->count() === 2
        && DB::table('notifications')->where('title', 'Stripe payment needs review')->count() === 4],
    // Both public Pay returns finish preparation before overlapping final card saves.
    'pay-return-card-save' => ['prepared_b' => true, 'seed' => function () {
            foreach (['cache' => false, 'cache_locks' => true] as $table => $locks) {
                Illuminate\Support\Facades\Schema::create($table, function ($schema) use ($locks) {
                    $schema->string('key')->primary();
                    if ($locks) $schema->string('owner'); else $schema->text('value');
                    $schema->integer('expiration');
                });
            }
            customerInvoice(7, 801); customerInvoice(8, 802);
        }, 'pause' => '/^insert into `billing_agreements`/', 'a' => fn ($gateway) => $payAndSave($gateway, 7, 801), 'b' => fn ($gateway) => $payAndSave($gateway, 8, 802),
        'check' => fn ($results) => $results['a']['ok'] === 'redirect' && $results['b']['ok'] === 'redirect'
            && Invoice::whereIn('id', [801, 802])->where('status', 'paid')->count() === 2
            && App\Models\BillingAgreement::orderBy('user_id')->get()->map(fn ($row) => [$row->user_id, $row->external_reference])->all()
                === [[7, 'pm_801'], [8, 'pm_802']] && DB::table('audits')->where('auditable_type', App\Models\Property::class)->count() > 0],
    // A gateway staff member also owns the settling invoice (the access alert's notification recipient FK).
    'access-staff-settlement' => ['seed' => function () {
            customerInvoice(7, 801);
            $role = App\Models\Role::create(['name' => 'Gateway staff', 'permissions' => ['admin.gateways.viewAny', 'admin.gateways.update']]);
            User::find(7)->update(['role_id' => $role->id]);
            User::find(7)->properties()->create(['key' => 'ae_stripe_checkout_801', 'value' => json_encode([
                'version' => 2, 'key' => 'checkout_801', 'created' => time(), 'retry' => true])]);
        }, 'waits' => true, 'pause' => '/^insert into `invoice_transactions`/', 'a' => function ($gateway) {
            $intent = intentOf(801, 'succeeded', 2000);
            $intent->metadata->ae_stripe_attempt = 'checkout_801';
            invoke($gateway, 'recordIntentPayment', $intent, 'succeeded');
            return 'settled';
        }, 'b' => fn ($gateway) => invoke($gateway, 'notifyPaymentAccessRefusal') ?? 'reviewed',
        'check' => fn () => $rows(801) === [[20.0, 'succeeded', false]] && Invoice::find(801)->status === 'paid'
            && DB::table('audits')->where('event', 'ae_stripe_access_refused')->count() === 1
            && DB::table('audits')->where('auditable_type', App\Models\Property::class)->count() > 0
            && DB::table('notifications')->where('user_id', 7)->where('title', 'Stripe access needs attention')->count() === 1],
    // Two deliveries share one durable audit. A failed notification must leave a pending marker and no partial notice.
    'access-notice-retry' => ['seed' => function () {
            customerInvoice(7, 801);
            $role = App\Models\Role::create(['name' => 'Gateway staff', 'permissions' => ['admin.gateways.viewAny', 'admin.gateways.update']]);
            User::find(7)->update(['role_id' => $role->id]);
        }, 'waits' => true, 'pause' => '/^insert into `notifications`/', 'a' => function ($gateway) {
            $fail = true;
            App\Models\Notification::created(function () use (&$fail) { if ($fail) { $fail = false; throw new RuntimeException('Delivery failed'); } });
            // Let the first delivery fail after its insert; pause only the retry.
            $GLOBALS['pauses'] = [];
            invoke($gateway, 'notifyPaymentAccessRefusal');
            check(DB::table('notifications')->count() === 0 && DB::table('audits')->where('event', 'ae_stripe_access_refused')
                ->where('new_values->notice_sent', false)->count() === 1, 'Failed delivery keeps its pending audit only');
            pauseBefore('/^insert into `notifications`/');
            invoke($gateway, 'notifyPaymentAccessRefusal');
            return 'retried';
        }, 'b' => fn ($gateway) => DB::transaction(function () use ($gateway) {
            DB::table('audits')->count(); // Dedupe must also survive an older enclosing transaction snapshot.
            invoke($gateway, 'notifyPaymentAccessRefusal');
            return 'reviewed';
        }), 'check' => fn () => DB::table('notifications')->where('user_id', 7)->where('title', 'Stripe access needs attention')->count() === 1
            && DB::table('audits')->where('event', 'ae_stripe_access_refused')->where('new_values->notice_sent', true)->count() === 1],
    // Audited settlement against a missing-subscription review, with normal web auditing.
    'subscription-review-vs-settlement' => [ 'seed' => function () {
            customerInvoice(7, 801);
            User::find(7)->properties()->create(['key' => 'ae_stripe_checkout_801', 'value' => json_encode([
                'version' => 2, 'key' => 'checkout_801', 'created' => time(), 'retry' => true])]);
        }, 'pause' => '/^insert into `invoice_transactions`/', 'a' => function ($gateway) {
            $intent = intentOf(801, 'succeeded', 2000);
            $intent->metadata->ae_stripe_attempt = 'checkout_801';
            invoke($gateway, 'recordIntentPayment', $intent, 'succeeded');
            return 'settled';
        }, 'b' => fn ($gateway) => invoke($gateway, 'recordSubscriptionPayment', (object) [ 'id' => 'in_missing_subscription', 'amount_paid' => 2000, 'currency' => 'usd',
            'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) []]]) ?? 'reviewed',
        'check' => fn () => $rows(801) === [[20.0, 'succeeded', false]] && Invoice::find(801)->status === 'paid'
            && DB::table('audits')->where('auditable_type', App\Models\Property::class)->count() > 0
            && DB::table('audits')->where('event', 'ae_stripe_subscription_review')->count() === 1,
    ],
    // Subscription review must not lock an unrelated invoice's existing audit row while it records its own audit.
    'subscription-audit' => ['seed' => fn () => App\Models\Audit::create(['event' => 'fixture',
            'auditable_type' => Invoice::class, 'auditable_id' => 801, 'old_values' => [], 'new_values' => []]), 'pause' => '/^insert into `audits`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordSubscriptionReview', paidSubscription(801), 'Service missing') ?? 'recorded',
        'b' => fn () => DB::transaction(fn () => App\Models\Audit::where('id', 1)->update(['event' => 'invoice_checked'])),
        'check' => fn () => App\Models\Audit::where('id', 1)->first()->event === 'invoice_checked'
            && App\Models\Audit::where('event', 'ae_stripe_subscription_review')->count() === 1],
    // Duplicate review deliveries still serialize on the gateway and record exactly one entry.
    'subscription-review-duplicate' => ['seed' => fn () => null, 'waits' => true, 'pause' => '/^insert into `audits`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordSubscriptionReview', paidSubscription(802), 'Service missing') ?? 'recorded',
        'b' => fn ($gateway) => DB::transaction(function () use ($gateway) {
            DB::table('audits')->count(); // An older settlement snapshot must not hide A's later committed review.
            invoke($gateway, 'recordSubscriptionReview', paidSubscription(802), 'Service missing');
            return 'recorded';
        }), 'check' => fn () => App\Models\Audit::where('event', 'ae_stripe_subscription_review')->count() === 1],
    // Settings committed while the gateway lock was held must be current when the waiting job acquires that lock.
    'gateway-state-current' => ['seed' => fn () => DB::table('settings')->where('key', 'ae_stripe_webhook_events')->update(['value' => 'retry']),
        'waits' => true, 'pause' => '/^update `settings`/', 'a' => function () {
            $owner = App\Models\Gateway::where('extension', 'AeStripe')->firstOrFail();
            return DB::transaction(function () use ($owner) {
                App\Models\Gateway::where('id', $owner->id)->lockForUpdate()->firstOrFail();
                $owner->settings()->where('key', 'ae_stripe_webhook_events')->update(['value' => 'ready']);
                return 'updated';
            });
        }, 'b' => function ($gateway) {
            invoke($gateway, 'reconcileWebhookSubscriptions');
            return count($GLOBALS['stripeCalls']);
        }, 'check' => fn ($results) => $results['b']['ok'] === 0 && DB::table('settings')->where('key', 'ae_stripe_webhook_events')->value('value') === 'ready'],
    // The gateway owner lock must not block a different gateway's payment FK check. Other precedes AeStripe in the extensions scan (mariadb-app.php), so the old unindexed locking read also locks that other gateway's row.
    'gateway-review' => ['seed' => fn () => customerInvoice(7, 811), 'pause' => '/^insert into `audits`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordSubscriptionReview', paidSubscription(811), 'Service missing') ?? 'recorded',
        'b' => fn () => App\Helpers\ExtensionHelper::addPayment(811, 'Other', 20, null, 'other_811')->id,
        'check' => fn () => $rows(811) === [[20.0, 'succeeded', false]] && Invoice::find(811)->status === 'paid'], 'gateway-access' => ['seed' => fn () => customerInvoice(7, 812),
        'pause' => '/^insert into `audits`/', 'a' => fn ($gateway) => invoke($gateway, 'notifyPaymentAccessRefusal') ?? 'recorded',
        'b' => fn () => App\Helpers\ExtensionHelper::addPayment(812, 'Other', 20, null, 'other_812')->id,
        'check' => fn () => $rows(812) === [[20.0, 'succeeded', false]] && Invoice::find(812)->status === 'paid'], 'gateway-events' => ['seed' => fn () => customerInvoice(7, 813),
        'pause' => '/^select .* from `settings`/', 'a' => fn ($gateway) => invoke($gateway, 'reconcileWebhookSubscriptions') ?? 'ready',
        'b' => fn () => App\Helpers\ExtensionHelper::addPayment(813, 'Other', 20, null, 'other_813')->id,
        'check' => fn () => $rows(813) === [[20.0, 'succeeded', false]] && Invoice::find(813)->status === 'paid'], 'gateway-signature' => ['seed' => function () {
            customerInvoice(7, 814);
            App\Models\Gateway::where('extension', 'AeStripe')->first()->settings()->create(['key' => 'ae_stripe_webhook_continuity',
                'value' => json_encode(['credentials' => ['stripe_webhook_secret' => 'whsec_test'], 'previous' => [['secret' => 'whsec_old', 'until' => null]]])]);
        }, 'pause' => '/^select .* from `settings`/', 'a' => function ($gateway) {
            // Only pause after acquiring the gateway lock, past the initial unlocked state read.
            $GLOBALS['pauses'] = [];
            DB::listen(function ($query) { if (str_contains($query->sql, 'for update')) pauseBefore('/^select .* from `settings`/'); });
            $time = time();
            return invoke($gateway, 'validWebhookSignature', '{}', 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.{}', 'whsec_test'));
        }, 'b' => fn () => App\Helpers\ExtensionHelper::addPayment(814, 'Other', 20, null, 'other_814')->id,
        'check' => fn ($results) => $results['a']['ok'] === true && $rows(814) === [[20.0, 'succeeded', false]] && Invoice::find(814)->status === 'paid'],
    // Different customers have different save/cache locks. Exercise persistence directly: A has validated its absent agreement and pauses before inserting; B must save its first card without a shared empty-index gap deadlock.
    'saved-cards' => ['seed' => function () { customerInvoice(7, 821); customerInvoice(8, 822); }, 'pause' => '/^insert into `billing_agreements`/',
        'a' => fn ($gateway) => invoke($gateway, 'persistSavedMethod', User::find(7), (object) ['id' => 'pm_7'], ['source' => 'seti_7']),
        'b' => fn ($gateway) => invoke($gateway, 'persistSavedMethod', User::find(8), (object) ['id' => 'pm_8'], ['source' => 'seti_8']),
        'check' => fn () => App\Models\BillingAgreement::orderBy('user_id')->get()->map(fn ($row) => [$row->user_id, $row->external_reference])->all()
            === [[7, 'pm_7'], [8, 'pm_8']] && DB::table('properties')->where('key', 'like', 'ae_stripe_saved_%')->count() === 2],
    // An earlier completed read must not hide a save committed while the second request waits for the owner.
    'saved-cards-duplicate' => ['seed' => fn () => customerInvoice(7, 823), 'waits' => true, 'pause' => '/^insert into `billing_agreements`/',
        'a' => fn ($gateway) => invoke($gateway, 'persistSavedMethod', User::find(7), (object) ['id' => 'pm_7'], ['source' => 'seti_7']), 'b' => function ($gateway) {
            DB::transaction(fn () => App\Models\BillingAgreement::count());
            return invoke($gateway, 'persistSavedMethod', User::find(7), (object) ['id' => 'pm_7'], ['source' => 'seti_7']);
        }, 'check' => fn () => App\Models\BillingAgreement::count() === 1 && App\Models\BillingAgreement::first()->external_reference === 'pm_7'
            && DB::table('properties')->where('key', 'like', 'ae_stripe_saved_%')->count() === 1],
    // Two customers open Pay on invoices with no stored attempt; each reserves its attempt row in the properties index.
    'pay-pay' => ['seed' => function () { customerInvoice(7, 301); customerInvoice(8, 302); }, 'pause' => '/^insert into `properties`/',
        'a' => function ($gateway) { auth()->login(User::find(7)); return $gateway->pay(Invoice::find(301), 20)->getData()['intent']->id; },
        'b' => function ($gateway) { auth()->login(User::find(8)); return $gateway->pay(Invoice::find(302), 20)->getData()['intent']->id; },
        'check' => fn () => $attempt(7, 301)['intent'] === 'pi_301' && $attempt(8, 302)['intent'] === 'pi_302'],
    // Pay now on a card the bank declines, recording the failed payment, while a neighbouring invoice settles.
    'pay-settlement' => ['seed' => function () {
            customerInvoice(7, 401)->user->properties()->create(['key' => 'ae_stripe_checkout_401',
                'value' => json_encode(['version' => 2, 'key' => 'checkout_401', 'created' => time(), 'intent' => 'pi_401'])]);
            customerInvoice(8, 402);
        }, 'pause' => '#^POST /payment_intents/pi_401/confirm$#', 'a' => function ($gateway) {
            try { invoke($gateway, 'confirmAttempt', Invoice::find(401), 'pi_401', ['payment_method' => 'pm_401'], null, 2000, 'usd'); }
            catch (Paymenter\Extensions\Gateways\AeStripe\PaymentDeclinedException $e) { return $e->getMessage(); }
            return 'not declined';
        }, 'b' => fn ($gateway) => settle($gateway, 402), 'check' => fn ($results) => $results['a']['ok'] === 'Insufficient funds. Try a different card, or contact your bank.'
            && $rows(401) === [[20.0, 'failed', false]] && $rows(402) === [[20.0, 'succeeded', false]]
            && Invoice::find(401)->status === 'pending' && Invoice::find(402)->status === 'paid'],
    // Webhooks settle two neighbouring invoices of different customers at once.
    'settlement-settlement' => ['seed' => function () { customerInvoice(7, 501); customerInvoice(8, 502); }, 'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => settle($gateway, 501), 'b' => fn ($gateway) => settle($gateway, 502),
        'check' => fn () => $rows(501) === [[20.0, 'succeeded', false]] && $rows(502) === [[20.0, 'succeeded', false]]
            && Invoice::find(501)->status === 'paid' && Invoice::find(502)->status === 'paid'],
    // The same, paused after A has replaced any card label of its new payment, just before it saves the new label.
    'settlement-card-label' => ['seed' => function () { customerInvoice(7, 511); customerInvoice(8, 512); }, 'pause' => '/^insert ignore into `ae_stripe_transaction_methods`/',
        'a' => fn ($gateway) => settle($gateway, 511), 'b' => fn ($gateway) => settle($gateway, 512),
        'check' => fn () => $rows(511) === [[20.0, 'succeeded', false]] && $rows(512) === [[20.0, 'succeeded', false]]
            && DB::table('ae_stripe_transaction_methods')->pluck('label')->all() === ['Visa **** 4242', 'Visa **** 4242']],
    // Stripe settles two customers' subscription invoices at once. Each finds its own service, never the other's.
    'subscription-subscription' => ['seed' => function () { subscriptionInvoice(8, 582); subscriptionInvoice(7, 581); }, 'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordSubscriptionPayment', paidSubscription(581)) ?? 'settled',
        'b' => fn ($gateway) => invoke($gateway, 'recordSubscriptionPayment', paidSubscription(582)) ?? 'settled',
        'check' => fn () => $rows(581) === [[20.0, 'succeeded', false]] && $rows(582) === [[20.0, 'succeeded', false]]],
    // Two customers' charge fees are recorded at once, each on a settled payment with no fee row yet.
    'fee-fee' => ['seed' => function () use ($gateway) { customerInvoice(7, 701); customerInvoice(8, 702); settle($gateway, 701); settle($gateway, 702); },
        'pause' => '/^insert into `ae_stripe_payment_fees`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordChargeFee', (object) ['payment_intent' => 'pi_701', 'paid' => true, 'status' => 'succeeded',
            'id' => 'ch_701', 'balance_transaction' => 'txn_701']),
        'b' => fn ($gateway) => invoke($gateway, 'recordChargeFee', (object) ['payment_intent' => 'pi_702', 'paid' => true, 'status' => 'succeeded',
            'id' => 'ch_702', 'balance_transaction' => 'txn_702']),
        'check' => fn () => DB::table('ae_stripe_payment_fees')->orderBy('payment_intent_id')->get(['payment_intent_id', 'state'])
            ->map(fn ($row) => [$row->payment_intent_id, $row->state])->all() === [['pi_701', 'completed'], ['pi_702', 'completed']]
            && DB::table('invoice_transactions')->where('fee', 0.5)->count() === 2],
    // Two customers overpay neighbouring invoices; Stripe refuses each refund, so each excess becomes account credit. Another customer's finished return sorts between their payments in the receipt index, as in a used ledger.
    'receipt-returns' => ['seed' => function () {
            customerInvoice(7, 601); customerInvoice(8, 602);
            DB::table('ae_stripe_receipt_returns')->insert(['payment_intent_id' => 'pi_6015', 'allocation_key' => 'excess', 'user_id' => 9,
                'invoice_id' => 6015, 'currency' => 'usd', 'received_minor' => 3000, 'amount_minor' => 1000, 'return_key' => 'ae-return-6015',
                'state' => 'refunded', 'retry' => false, 'updated_at' => time()]);
        }, 'pause' => '/^insert into `credits`/', 'a' => fn ($gateway) => settle($gateway, 601, 3000), 'b' => fn ($gateway) => settle($gateway, 602, 3000),
        'check' => fn () => $rows(601) === [[30.0, 'succeeded', false], [-10.0, 'succeeded', true]] && $rows(602) === [[30.0, 'succeeded', false], [-10.0, 'succeeded', true]]
            && DB::table('credits')->orderBy('user_id')->get(['user_id', 'amount'])->map(fn ($c) => [$c->user_id, (float) $c->amount])->all() === [[7, 10.0], [8, 10.0]]
            && DB::table('ae_stripe_receipt_returns')->whereIn('invoice_id', [601, 602])->orderBy('invoice_id')->pluck('state')->all() === ['credited', 'credited']
            && DB::table('audits')->where('event', 'ae_stripe_receipt_return')->count() === 2 && DB::table('notifications')->where('title', 'Payment return')->count() === 2],
    // The same webhook is delivered twice at once: B waits for the customer, then finds the payment settled.
    'same-payment-duplicate' => ['seed' => fn () => customerInvoice(7, 521), 'waits' => true, 'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => settle($gateway, 521), 'b' => fn ($gateway) => settle($gateway, 521),
        'check' => fn () => $rows(521) === [[20.0, 'succeeded', false]] && Invoice::find(521)->status === 'paid' && DB::table('ae_stripe_transaction_methods')->count() === 1],
    // The customer comes back from the bank while the webhook settles the same payment.
    'same-payment-return' => ['seed' => fn () => customerInvoice(7, 531), 'waits' => true, 'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => settle($gateway, 531), 'b' => function ($gateway) {
            auth()->login(User::find(7));
            $page = $gateway->finishPayment(Illuminate\Http\Request::create('/', 'GET', ['invoice' => 531, 'payment_intent' => 'pi_531',
                'payment_intent_client_secret' => 'pi_secret']));
            return $page instanceof Illuminate\Http\RedirectResponse ? 'redirect' : $page->getData()['message'];
        }, 'check' => fn ($results) => $results['b']['ok'] === 'redirect' && $rows(531) === [[20.0, 'succeeded', false]]
            && Invoice::find(531)->status === 'paid' && DB::table('ae_stripe_transaction_methods')->count() === 1],
    // A settlement is rolled back after writing its payment row and runs again. Another session, which has written more rows than A, locks the card label row A inserts next and then waits for A's customer. InnoDB breaks that cycle by rolling back A, the smaller transaction; A runs again once the other session ends. Its first payment row id is gone.
    'rollback-retry' => ['seed' => function () {
            customerInvoice(7, 541);
            DB::statement('create table if not exists lock_ballast (id int primary key)');
            DB::table('lock_ballast')->truncate();
        }, 'waits' => true, 'deadlocks' => 1, 'pause' => '/^insert ignore into `ae_stripe_transaction_methods`/', 'a' => fn ($gateway) => settle($gateway, 541),
        'b' => function () {
            DB::beginTransaction();
            DB::table('lock_ballast')->insert(array_map(fn ($id) => ['id' => $id], range(1, 200)));
            DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', 1)->lockForUpdate()->first();
            User::where('id', 7)->lockForUpdate()->first();
            DB::rollBack();
            return 'released';
        }, 'check' => fn () => $rows(541) === [[20.0, 'succeeded', false]] && Invoice::find(541)->status === 'paid'
            && DB::table('invoice_transactions')->pluck('id')->all() === [2] && DB::table('ae_stripe_transaction_methods')->pluck('invoice_transaction_id')->all() === [2]],
    // Stripe settles a subscription invoice while the same customer's other invoice settles: it waits for the customer.
    'subscription-owner' => ['seed' => function () { customerInvoice(7, 551); subscriptionInvoice(7, 552); }, 'waits' => true, 'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => settle($gateway, 551), 'b' => fn ($gateway) => invoke($gateway, 'recordSubscriptionPayment', paidSubscription(552)) ?? 'settled',
        'check' => fn () => $rows(551) === [[20.0, 'succeeded', false]] && $rows(552) === [[20.0, 'succeeded', false]]
            && DB::table('invoice_transactions')->where('invoice_id', 552)->value('transaction_id') === 'pi_552' && Invoice::find(552)->status === 'paid'],
    // A refund event is delivered twice at once: B waits for the customer, then finds the refund already recorded.
    'reversal-duplicate' => ['seed' => function () use ($gateway) { customerInvoice(7, 561); settle($gateway, 561); }, 'waits' => true, 'pause' => '/^insert into `audits`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 're_561', 'payment_intent' => 'pi_561'], 'refund') ?? 'recorded',
        'b' => fn ($gateway) => invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 're_561', 'payment_intent' => 'pi_561'], 'refund') ?? 'recorded',
        'check' => fn () => DB::table('ae_stripe_receipt_returns')->where('payment_intent_id', 'pi_561')->orderBy('allocation_key')
                ->get(['allocation_key', 'state'])->map(fn ($row) => [$row->allocation_key, $row->state])->all() === [['stripe:refund:re_561', 'refunded']]
            && DB::table('audits')->where('event', 'ae_stripe_receipt_reversal')->count() === 1 && $rows(561) === [[20.0, 'succeeded', false]]],
];
if (($argv[1] ?? null) === 'worker') {
    $case = $cases[$argv[2]];
    if ($argv[3] === 'b' && !empty($case['prepared_b'])) {
        pauseBefore('/^final-save$/');
        $userLocks = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$userLocks) {
            if (preg_match('/^select .* from `users`.*for update$/', $sql) && ++$userLocks === 7) pausePoint('final-save');
        });
    }
    if ($argv[3] === 'a') pauseBefore($case['pause']);
    fwrite(STDOUT, 'ready ' . DB::selectOne('select connection_id() as id')->id . "\n"); fflush(STDOUT);
    try { $result = ['ok' => $case[$argv[3]]($gateway)]; } catch (Throwable $e) { $result = ['error' => get_class($e) . ': ' . $e->getMessage()]; }
    fwrite(STDOUT, 'done ' . json_encode($result) . "\n");
    exit(0);
}
/** The next line a worker writes, or null when $stop() becomes true first. Fails after 30 seconds. */
function workerLine(array $worker, ?callable $stop = null): ?string
{
    for ($deadline = microtime(true) + 30; microtime(true) < $deadline;) {
        $read = [$worker[2]]; $none = null;
        if (stream_select($read, $none, $none, 0, 50000)) return rtrim((string) fgets($worker[2]), "\n");
        if ($stop && $stop()) return null;
    }
    throw new RuntimeException('A worker stopped answering.');
}
$deadlocks = fn () => (int) DB::selectOne("show global status like 'Innodb_deadlocks'")->Value;
$php = getenv('PAYMENTER_TEST_PHP') ?: PHP_BINARY;
foreach ($cases as $name => $case) {
    if (getenv('PAY_MARIADB_CASE') && !in_array($name, explode(',', getenv('PAY_MARIADB_CASE')), true)) continue;
    DB::statement('set foreign_key_checks = 0');
    foreach (['invoice_transactions', 'ae_stripe_transaction_methods', 'ae_stripe_receipt_returns', 'ae_stripe_payment_fees', 'credits', 'audits', 'notifications',
        'billing_agreements', 'properties', 'invoice_items', 'invoices', 'services', 'plans', 'products', 'categories', 'users', 'roles'] as $table) DB::table($table)->truncate();
    DB::statement('set foreign_key_checks = 1');
    DB::table('settings')->where('key', 'ae_stripe_webhook_continuity')->delete();
    DB::table('settings')->where('key', 'ae_stripe_webhook_events')->update(['value' => 'ready']);
    App\Models\Gateway::where('extension', 'AeStripe')->update(['enabled' => true]);
    $case['seed']();
    $before = $deadlocks();
    $workers = [];
    foreach (!empty($case['prepared_b']) ? ['b', 'a'] : ['a', 'b'] as $role) {
        $process = proc_open([$php, '-d', 'auto_prepend_file=' . __DIR__ . '/entered.php', '-d', 'memory_limit=256M', __FILE__, 'worker', $name, $role],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes); // Errors go to the inherited stderr; passing it would rewind a log file.
        $workers[$role] = [$process, $pipes[0], $pipes[1], (int) substr(workerLine([$process, $pipes[0], $pipes[1]]), 6)];
        if ($role === 'b' && !empty($case['prepared_b'])) check(workerLine($workers['b']) === 'paused', $name . ': B prepares consent before its final save locks');
        if ($role === 'a') check(($line = workerLine($workers['a'])) === 'paused', $name . ': request A reaches its pause point: ' . $line);
    }
    // B runs until it finishes or waits on a lock that A holds. The InnoDB transaction list can leave out a session waiting to insert, so a statement still running after 1.5 seconds on this small database also counts as a lock wait.
    $waiting = fn () => (bool) DB::selectOne("select count(*) as n from information_schema.innodb_trx where trx_mysql_thread_id = ? and trx_state = 'LOCK WAIT'", [$workers['b'][3]])->n
        || (bool) DB::selectOne("select count(*) as n from information_schema.processlist where id = ? and command in ('Query', 'Execute') and time_ms > 1500", [$workers['b'][3]])->n;
    if (!empty($case['prepared_b'])) fwrite($workers['b'][1], "go\n");
    $early = workerLine($workers['b'], $waiting);
    fwrite($workers['a'][1], "go\n");
    $results = ['a' => json_decode(substr(workerLine($workers['a']), 5), true), 'b' => json_decode(substr($early ?? workerLine($workers['b']), 5), true)];
    foreach ($workers as $worker) { fclose($worker[1]); fclose($worker[2]); proc_close($worker[0]); }
    echo $name . ': B ' . ($early === null ? 'waited on A' : 'finished while A was paused') . "\n";
    echo $name . ': requests ' . json_encode($results) . '; deadlocks=' . ($deadlocks() - $before) . "\n";
    if (!empty($case['prepared_b'])) echo $name . ': saved_cards=' . DB::table('billing_agreements')->count()
        . '; paid_invoices=' . Invoice::where('status', 'paid')->count() . "\n";
    check(($early === null) === !empty($case['waits']), $name . ': B ' . (empty($case['waits']) ? 'never waits on A' : 'waits on A'));
    check(isset($results['a']['ok'], $results['b']['ok']), $name . ': both requests finish: ' . json_encode($results));
    check($deadlocks() - $before === ($case['deadlocks'] ?? 0), $name . ': InnoDB reports ' . ($case['deadlocks'] ?? 0) . ' deadlocks between the two');
    check($case['check']($results), $name . ': each request leaves its own rows, once: ' . json_encode($results));
}
echo "PASS: $count MariaDB lock assertions\n";
