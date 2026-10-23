<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

trait StripeMetadata
{
    private function readStripeObject($value)
    {
        if (!is_object($value) && !is_array($value)) return $value;
        foreach ($value as $key => $child) {
            if (is_object($value)) $value->$key = $this->readStripeObject($child);
            else $value[$key] = $this->readStripeObject($child);
        }
        if (is_object($value) && is_object($value->metadata ?? null)) {
            // Existing sandbox objects keep their metadata. New keys take precedence.
            foreach ($value->metadata as $key => $child) {
                if (!str_starts_with($key, 'stripe_saved_')) continue;
                $target = 'ae_stripe_' . substr($key, 13);
                if (!property_exists($value->metadata, $target)) $value->metadata->$target = $child;
            }
        }
        return $value;
    }
}
