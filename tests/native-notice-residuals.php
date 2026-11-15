<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Real property queries and a fresh cache per scheduler turn reproduce retention and recovery stalls.
use App\Models\{Property, Service, User};
use Illuminate\Support\Facades\{Cache, DB, Http};

if ($case === 'final-residual-retry' || $case === 'final-residual-prune') {
    $retry = $case === 'final-residual-retry';
    for ($i = 0; $i < 210; ++$i) $notice(str_pad(dechex($i), 32, '0', STR_PAD_LEFT), ['retry_at' => now()->addDay()->timestamp]);
    $key = $notice($token, $retry ? [] : ['state' => 'ready', 'created_at' => now()->subDays(31)->timestamp]);
    $run = function () use ($retry) {
        // Evict cache state between cron processes while keeping the required shared lock store.
        Cache::flush(); Cache::purge('database');
        $worker = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_secret_key' => 'rk_test_fixture']);
        (new ReflectionMethod($worker, $retry ? 'retrySavedMethodNotices' : 'pruneSavedMethodNotices'))->invoke($worker);
    };
    $run();
    $assert($user->properties()->where('key', $key)->exists()
        && (!$retry || json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'pending'), 'The first bounded turn leaves the later reservation untouched.');
    $run();
    $assert($retry ? json_decode($user->properties()->where('key', $key)->first()->value, true)['state'] === 'ready'
        : !$user->properties()->where('key', $key)->exists(), 'Fresh scheduler caches still reach work beyond the first notice batch.');
} elseif ($case === 'final-residual-orphans') {
    $orphan = User::create(['email' => 'orphan@example.com', 'password' => 'synthetic']);
    $rows = [];
    for ($i = 0; $i < 210; ++$i) $rows[] = ['model_type' => $orphan->getMorphClass(), 'model_id' => $orphan->id,
        'key' => 'ae_stripe_notice_orphan_' . $i, 'value' => json_encode(['state' => 'pending', 'created_at' => now()->timestamp]), 'created_at' => now(), 'updated_at' => now()];
    $expired = ['model_type' => $orphan->getMorphClass(), 'model_id' => $orphan->id, 'key' => 'ae_stripe_notice_expired',
        'value' => json_encode(['state' => 'ready', 'created_at' => now()->subDays(31)->timestamp]), 'created_at' => now(), 'updated_at' => now()];
    DB::table('properties')->insert([...$rows, $expired]);
    $receipt = $orphan->properties()->create(['key' => 'ae_stripe_saved_retained', 'value' => '{"notice_consumed":false}']);
    $foreign = Property::create(array_replace($expired, ['model_type' => Service::class]));
    $orphan->delete();
    $liveExpired = $notice($token, ['state' => 'ready', 'created_at' => now()->subDays(31)->timestamp]);
    $liveRecent = $notice(str_repeat('c', 32));
    for ($turn = 0; $turn < 2; ++$turn) {
        Cache::flush(); Cache::purge('database');
        $invoke('pruneSavedMethodNotices');
        if ($turn === 0) $assert(!$user->properties()->where('key', $liveExpired)->exists(), 'Orphans do not consume live retention capacity.');
    }
    $assert(!Property::where('model_type', $orphan->getMorphClass())->where('model_id', $orphan->id)->where('key', $expired['key'])->exists(), 'Expired orphan notices are eventually retired with fresh scheduler caches.');
    $assert(Property::where('model_type', $orphan->getMorphClass())->where('model_id', $orphan->id)->where('key', 'like', 'ae_stripe_notice_%')->count() === 210
        && $receipt->fresh() && $foreign->fresh() && $user->properties()->where('key', $liveRecent)->exists(), 'Retention preserves recent orphans, saving receipts, other model types and live reservations.');
    Http::assertNothingSent();
}
echo "PASS: $checks native notice residual checks ($case)\n";
