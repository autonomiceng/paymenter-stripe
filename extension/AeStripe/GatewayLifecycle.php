<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\AeStripe;

use App\Models\BillingAgreement;
use App\Models\Service;

final class GatewayLifecycle
{
    public static function hasReferences($gateway): bool
    {
        if (BillingAgreement::where('gateway_id', $gateway->id)->exists()) return true;
        $ids = BillingAgreement::withTrashed()->where('gateway_id', $gateway->id)->pluck('id');
        if (Service::whereIn('billing_agreement_id', $ids)->exists()) return true;
        // Legacy subscriptions have no gateway link. Preserve them when ownership is unknown.
        return Service::whereNull('billing_agreement_id')->whereNotNull('subscription_id')
            ->whereHas('properties', fn ($q) => $q->where('key', 'has_stripe_subscription')->where('value', '1'))->exists();
    }
    public static function disable($gateway): void { $gateway->update(['enabled' => false]); }
}
