<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
(function () use ($gateway) {
    $config = array_column($gateway->getConfig(), null, 'name');
    $address = $config['stripe_create_customers'];
    check($address['label'] === 'Send address and phone to Stripe', 'Customer details toggle says what it sends');
    check($address['description'] === "Copy the customer's address, phone and company to Stripe when their Stripe customer is first created. Used on Stripe receipts. Later profile changes are not sent.",
        'Customer details help describes the initial copy, receipts and no later profile updates');
    check(!($address['default'] ?? false) && !$address['required'], 'Sending address and phone remains optional and off by default');
})();
