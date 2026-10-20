<?php

namespace Paymenter\Extensions\Gateways\StripeSaved;

trait Signature
{
    private function isValidSignature($payload, $header, $secret)
    {
        if (!is_string($header) || !is_string($secret) || $secret === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && ctype_digit($value ?? '')) {
                $timestamp = $value;
            } elseif ($key === 'v1' && is_string($value)) {
                $signatures[] = $value;
            }
        }
        // https://docs.stripe.com/webhooks/signature (five-minute replay tolerance)
        if ($timestamp === null || abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true; // Accept any v1 signature during webhook-secret rotation.
            }
        }
        return false;
    }
}
