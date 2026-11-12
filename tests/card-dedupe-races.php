<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
// Concurrent writers commit between the save's transactions (afterNextCommit), as another process would.
use App\Models\BillingAgreement;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Sleep;
use Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

nativeSharedCache();
$otherGateway = App\Models\Gateway::create(['name' => 'Other', 'extension' => 'Other', 'type' => 'gateway', 'enabled' => true]);
$setup = nativeSetupIntent('races');
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
$handler = nativeMethodHandler($methods, $setup);
$GLOBALS['stripeHandler'] = $handler;
$change = fn (string $reference, array $values) => DB::table('billing_agreements')->where('external_reference', $reference)->update($values);

// Pending confirmation protects only its card and verified copies, including a late claim.
foreach (['unrelated', 'active', 'duplicate', 'late duplicate'] as $case) {
    resetSavedMethods();
    $methods = ['pm_old' => nativeCard('pm_old', 'fp_active'),
        'pm_new' => nativeCard('pm_new', $case === 'unrelated' ? 'fp_other' : 'fp_active')];
    $active = nativeAgreement(19, 'pm_old'); $removing = nativeAgreement(20, 'pm_new');
    $claim = fn () => $owner->properties()->create(['key' => 'ae_stripe_checkout_9983', 'value' => json_encode([
        'confirmation' => ['phase' => 'sending', 'agreement' => $active->id, 'params' => ['payment_method' => $active->external_reference]]])]);
    if ($case !== 'late duplicate') $claim();
    $late = false;
    $GLOBALS['stripeHandler'] = function ($verb, $path, $data) use ($handler, $case, $claim, &$late) {
        $answer = $handler($verb, $path, $data);
        if ($case === 'late duplicate' && !$late && $verb === 'get') { $late = true; $claim(); }
        return $answer;
    };
    $start = count($GLOBALS['stripeCalls']);
    $removed = invoke($gateway, 'removeSavedMethodCopies', $case === 'active' ? $active : $removing);
    $detaches = array_filter(array_slice($GLOBALS['stripeCalls'], $start), fn ($c) => str_ends_with($c[1], '/detach'));
    check($case === 'unrelated' ? $removed && count($detaches) === 1 && $methods['pm_new']->customer === null
        : !$removed && !$detaches && $methods['pm_new']->customer === 'cus_owner',
        'Removal permits an unrelated card and protects the active card and copies: ' . $case);
    check($methods['pm_old']->customer === 'cus_owner' && agreements()['pm_old']['deleted_at'] === null,
        'Card removal preserves the sending confirmation method');
}
resetSavedMethods(); $methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
$GLOBALS['stripeHandler'] = $handler;

// A removed comparison agreement cannot be written through a stale plan.
resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(20, 'pm_new');
afterNextCommit(fn () => $change('pm_old', ['deleted_at' => '2026-10-23 13:00:00']));
$result = setupReturn($gateway, $setup);
check($result['type'] === 'danger' && $result['message'] === 'Saved payment methods changed. Try again.', 'A changed agreement set shows a retry notice');
setupReturn($gateway, $setup);
check($methods['pm_new']->customer === 'cus_owner' && agreements()['pm_new']['deleted_at'] === null,
    'Retry preserves the current agreement and provider reference');

// A second save cannot interleave provider writes while the first holds the shared save lock. The lock is the real
// database cache lock; the clock moves with the waiting loop so the second save reaches its timeout at once.
resetSavedMethods();
nativeAgreement(19, 'pm_old');
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
$entered = false;
$GLOBALS['stripeHandler'] = function ($verb, $path, $data) use ($handler, $gateway, $owner, &$entered) {
    if ($verb === 'post' && !$entered) {
        $entered = true;
        $before = count($GLOBALS['stripeCalls']);
        check(Cache::lock('ae-stripe-save-method-7', 600)->get() === false, 'The first save holds the shared save lock during provider writes');
        Sleep::fake(syncWithCarbon: true);
        try { invoke($gateway, 'persistAgreement', $owner, (object) ['id' => 'pm_new']); throw new RuntimeException('Expected lock contention'); }
        catch (SavedMethodRetryException $e) { check(str_contains($e->getMessage(), 'in progress'), 'Concurrent saving stays retryable'); }
        finally { Sleep::fake(false); Illuminate\Support\Carbon::setTestNow(); }
        check(count($GLOBALS['stripeCalls']) === $before, 'Lock contention never starts provider work');
    }
    return $handler($verb, $path, $data);
};
setupReturn($gateway, $setup);
check($entered && $methods['pm_old']->allow_redisplay === 'always', 'The serialized save completes its redisplay consent');
$GLOBALS['stripeHandler'] = $handler;

foreach (['incoming user', 'incoming gateway', 'incoming removed', 'incoming bound', 'retained user', 'retained gateway', 'method customer'] as $case) {
    $methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
    resetSavedMethods();
    nativeAgreement(19, 'pm_old');
    nativeAgreement(20, 'pm_new');
    $injected = null;
    afterNextCommit(function () use ($case, &$methods, &$injected, $change, $otherGateway) {
        switch ($case) {
            case 'incoming user': $change('pm_new', ['user_id' => 99]); break;
            case 'incoming gateway': $change('pm_new', ['gateway_id' => $otherGateway->id]); break;
            case 'incoming removed': $change('pm_new', ['deleted_at' => '2026-10-23 00:00:00']); break;
            case 'incoming bound': nativeService(null)->forceFill(['billing_agreement_id' => 20])->save(); break;
            case 'retained user': $change('pm_old', ['user_id' => 99]); break;
            case 'retained gateway': $change('pm_old', ['gateway_id' => $otherGateway->id]); break;
            case 'method customer': $methods['pm_new']->customer = 'cus_other'; break;
        }
        $injected = agreements();
    });
    $start = count($GLOBALS['stripeCalls']);
    $refused = false;
    try { $result = setupReturn($gateway, $setup); $refused = ($result['type'] ?? null) === 'danger'; }
    catch (HttpExceptionInterface $e) {
        check($e->getStatusCode() === 409, 'Only an expected HTTP 409 may escape the concurrent save');
        $refused = true;
    }
    check($injected !== null, "The concurrent change is injected for $case");
    check($refused || agreements() === $injected, "Setup refuses or preserves the injected agreements after $case");
    if ($case !== 'incoming bound') check($refused, "Setup refuses the unsafe concurrent change after $case");
    check(!array_filter(array_slice($GLOBALS['stripeCalls'], $start), fn ($call) => str_ends_with($call[1], '/detach')),
        "Setup preserves provider references after $case");
    if ($case === 'incoming bound') check(DB::table('services')->value('billing_agreement_id') === 20, 'A concurrent service choice is retained');
}

$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
resetSavedMethods();
nativeAgreement(19, 'pm_old', ['user_id' => 99]);
// The clicked row as the page loaded it, before another writer moved the agreement to another account.
$stale = BillingAgreement::find(19)->forceFill(['user_id' => 7]);
$gateway->cancelBillingAgreement($stale);
check($methods['pm_old']->customer === 'cus_owner', 'Cancellation revalidates agreement ownership before detaching');
