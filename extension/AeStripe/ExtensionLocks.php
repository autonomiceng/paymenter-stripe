<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Support\Facades\DB;

final class ExtensionLocks
{
    private static ?string $scope = null;

    public static function transaction(string $scope, callable $callback, int $attempts = 1): mixed
    {
        if (DB::transactionLevel() > 0 && self::$scope !== null) {
            if (self::$scope !== $scope) throw new \LogicException('Stripe transactions cannot combine lock scopes.');
            return DB::transaction($callback, $attempts);
        }
        // Normally autocommit; a core caller may already own a transaction. Never select a missing lock key.
        DB::table('ae_stripe_locks')->insertOrIgnore(['scope' => $scope]);
        return DB::transaction(function () use ($scope, $callback) {
            DB::table('ae_stripe_locks')->where('scope', $scope)->lockForUpdate()->firstOrFail();
            $previous = self::$scope;
            self::$scope = $scope;
            try { return $callback(); }
            finally { self::$scope = $previous; }
        }, $attempts);
    }
}
