<?php

namespace Paymenter\Extensions\Gateways\StripeSaved;

trait Amounts
{
    // UGX and ISK forbid fractions but retain a two-decimal API representation.
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    private function toMinorUnits(string|int|float $amount, string $currency): int
    {
        // Normalize binary float noise; decimal strings are rounded directly, without a float cast.
        $decimal = is_float($amount) ? sprintf('%.12F', $amount) : (string) $amount;
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $decimal, $parts)) {
            throw new \InvalidArgumentException('Invalid payment amount');
        }
        $compatibility = in_array(strtolower($currency), ['isk', 'ugx'], true);
        $places = $compatibility || in_array(strtolower($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
        $fraction = str_pad($parts[3] ?? '', $places + 1, '0');
        $digits = ltrim($parts[2] . substr($fraction, 0, $places), '0') ?: '0';
        // Reserve one integer unit for half-up rounding, including negative amounts.
        $limit = (string) ($compatibility ? intdiv(PHP_INT_MAX, 100) - 1 : PHP_INT_MAX - 1);
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new \InvalidArgumentException('Payment amount too large');
        }
        $minor = (int) $digits + ((int) $fraction[$places] >= 5 ? 1 : 0);
        $minor *= $compatibility ? 100 : 1;
        return $parts[1] === '-' ? -$minor : $minor;
    }

    private function fromMinorUnits(int $amount, string $currency): float
    {
        return $amount / (!in_array(strtolower($currency), ['isk', 'ugx'], true)
            && in_array(strtolower($currency), self::ZERO_DECIMAL, true) ? 1 : 100);
    }
}
