<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
(function () use ($gateway) {
    $config = array_column($gateway->getConfig(), null, 'name');
    $address = $config['stripe_create_customers'];
    check($address['label'] === 'Send address and phone to Stripe', 'Customer details toggle says what it sends');
    check(str_contains($address['description'], 'Stripe Tax') && str_contains($address['description'], 'receipts'), 'Customer details help names the tax and receipt use cases');
    check(!($address['default'] ?? false) && !$address['required'], 'Sending address and phone remains optional and off by default');
})();
