<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Static checks: Stripe.js runs only in a browser, so assert the view contract offline.
$views = __DIR__ . '/../extension/StripeSaved/resources/views/';
$theme = file_get_contents($views . 'stripe-theme.blade.php');
foreach (['pay', 'billing-agreement'] as $name) {
    $view = file_get_contents($views . $name . '.blade.php');
    check(!str_contains($view, 'setTimeout'), "$name: payment errors are never hidden on a timer");
    check(str_contains($view, '<div id="error-message" role="alert"'), "$name: errors are announced and stay mounted");
    check(preg_match('/preventDefault\(\);\s+(clearMessage\(\)|message\.replaceChildren\(\));\s+setLoading\(true\)/', $view) === 1,
        "$name: the next submit clears the previous error");
    check(!preg_match("/theme:\s*'(night|stripe)'/", $view), "$name: Stripe theme is not hard-coded");
    check(str_contains($view, "@include('gateways.stripe-saved::stripe-theme')")
        && str_contains($view, 'appearance: stripeAppearance(form)')
        && str_contains($view, 'syncStripeTheme(form, elements)')
        && str_contains($view, 'stopThemeSync();'), "$name: theme derives from the page and follows changes");
    check(!str_contains($view, '<form id="payment-form" class="mt-2 text-white"'), "$name: form text inherits the page colour");
}
check(str_contains($theme, '[data-theme="dark"], [data-theme="light"], .dark, .light'), 'Explicit page theme markers win');
check(str_contains($theme, "matchMedia('(prefers-color-scheme: dark)')"), 'OS colour scheme is the last fallback');
check(str_contains($theme, 'elements.update({appearance})'), 'Open forms update without remounting');
check(!preg_match('/#[0-9a-f]{3,6}\b|colorPrimary/i', $theme), 'No brand colours are hard-coded');
