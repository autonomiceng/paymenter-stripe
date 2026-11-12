<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Support\Facades\DB;

final class ExtensionLocks
{
    private static ?string $scope = null;
    private static bool $currentReads = false;

    /** Enclosing transactions may already have a repeatable-read snapshot from before the payment lock. */
    public static function currentRead($query)
    {
        return self::$currentReads ? $query->lockForUpdate() : $query;
    }

    /** Serialize remote writes through finalization without holding database row locks over HTTP. */
    public static function provider(string $scope, callable $callback, bool $cancellation = false): mixed
    {
        $driver = config('cache.stores.' . config('cache.default') . '.driver');
        $shared = in_array($driver, ['database', 'redis', 'memcached', 'dynamodb'], true);
        if (!$shared && !($cancellation && in_array($driver, ['file', 'array'], true))) {
            throw new \App\Exceptions\DisplayException('Stripe updates need a shared cache store. Contact support.');
        }
        abort_unless(DB::transactionLevel() === 0, 409);
        // These stores cannot finalize invoices. Only deletion may proceed without their provider mutex.
        if (!$shared) return $callback();
        $lock = \Illuminate\Support\Facades\Cache::lock('ae-stripe-provider-' . $scope, 600);
        try { $lock->block(5); }
        catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new \App\Exceptions\DisplayException('Another Stripe update is in progress. Try again.');
        }
        try { return $callback(); }
        finally { $lock->release(); }
    }

    public static function transaction(string $scope, callable $callback, int $attempts = 1): mixed
    {
        if (DB::transactionLevel() > 0 && self::$scope !== null) {
            if (self::$scope !== $scope) throw new \LogicException('Stripe transactions cannot combine lock scopes.');
            return DB::transaction($callback, $attempts);
        }
        $enclosing = DB::transactionLevel() > 0;
        // Normally autocommit; a core caller may already own a transaction. Never select a missing lock key.
        DB::table('ae_stripe_locks')->insertOrIgnore(['scope' => $scope]);
        // Only the next transaction: do not change a caller's transaction or the session default. Current reads in
        // an owned transaction avoid shared index-gap locks; an enclosing snapshot still needs locking discovery.
        if (!$enclosing && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $round = 0;
        return DB::transaction(function () use ($scope, $callback, $enclosing, &$round) {
            ++$round;
            DB::table('ae_stripe_locks')->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $previous = self::$scope;
            $previousReads = self::$currentReads;
            // A deadlock retry starts a new transaction after the one-shot isolation setting has been consumed.
            self::$currentReads = $enclosing || $round > 1;
            self::$scope = $scope;
            try { return $callback(); }
            finally { self::$scope = $previous; self::$currentReads = $previousReads; }
        }, $attempts);
    }
}
