<?php
// Synthetic optional Domains boundary; MIT, Copyright (c) 2026 Autonomic Engineering.
class TestDomainsAutoPay
{
    public static ?array $context = null;
    public static bool $ownerOn = true;
    public static int $checks = 0;
    public function renewalAutoPayContext(int $invoiceId): ?array
    {
        check(\Illuminate\Support\Facades\DB::transactionLevel() === 0, 'Domains enrollment runs outside payment transactions');
        return self::$ownerOn ? self::$context : null;
    }
    public function renewalAutoPayStillAllowed(int $invoiceId, array $expected): bool
    {
        check(\Illuminate\Support\Facades\DB::$userLocked, 'Domains final authority is checked under the existing payment locks');
        ++self::$checks;
        return self::$ownerOn && self::$context !== null && self::$context === $expected;
    }
}
class_alias(TestDomainsAutoPay::class, 'Paymenter\\Extensions\\Servers\\Domains\\Services\\RenewalAutoPay');
