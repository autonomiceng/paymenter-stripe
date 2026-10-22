<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Static checks: Stripe.js runs only in a browser, so assert the view contract offline.
$views = __DIR__ . '/../extension/StripeSaved/resources/views/';
$theme = file_get_contents($views . 'stripe-theme.blade.php');
foreach (['pay', 'billing-agreement'] as $name) {
    $view = file_get_contents($views . $name . '.blade.php');
    $script = substr($view, strpos($view, '<script>') + strlen('<script>'));
    $entry = strpos($script, 'async function initializePaymentForm() {');
    check($entry !== false && !preg_match('/\b(?:getElementById|querySelector(?:All)?)\s*\(/', substr($script, 0, $entry)),
        "$name: no DOM lookup runs before deferred initialization");
    check(preg_match('/\n}\s*initializePaymentForm\(\);\s*<\/script>/', $script) === 1,
        "$name: only the deferred entry point runs after its definition");
    $wait = substr($script, $entry, strpos($script, 'let ready = false;') - $entry);
    check(str_contains($wait, 'const deadline = performance.now() + 5000;')
        && str_contains($wait, 'if (performance.now() >= deadline) return resolve(null);')
        && str_contains($wait, 'requestAnimationFrame(findForm);')
        && str_contains($wait, 'findForm();'), "$name: form lookup checks now and waits at most five seconds");
    check(str_contains($wait, 'const paymentForm = await new Promise(')
        && str_contains($wait, "if (!paymentForm) {")
        && str_contains($wait, "console.error('StripeSaved: payment form did not appear within 5 seconds.');")
        && str_contains($wait, 'return;'), "$name: missing form logs once and stops initialization");
    check(str_contains($view, "if (submit) submit.disabled = true;")
        && str_contains($view, "if (message) message.textContent = 'Could not load the payment form. Reload to try again.';"),
        "$name: initialization errors tolerate missing button and alert");
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
foreach (['pay', 'billing-agreement'] as $name) {
    $view = file_get_contents($views . $name . '.blade.php');
    check(str_contains($view, 'Js::from(auth()->user()->name)') && str_contains($view, 'Js::from(auth()->user()->email)'), "$name: customer details use script-safe values");
    check(str_contains($view, '<button id="submit" disabled'), "$name: submission starts disabled");
    check(str_contains($view, "paymentElement.on('ready'") && str_contains($view, "script.onerror = initializationError"), "$name: readiness gates submission and script failure shows an error");
    check(strpos($view, "form.addEventListener('submit'") < strpos($view, "paymentElement.mount("), "$name: submit handler is installed before mounting");
}
$setupView = file_get_contents($views . 'billing-agreement.blade.php');
check(str_contains($setupView, 'id="setup-consent" required disabled') && str_contains($setupView, 'I agree to save this payment method and allow charges to it for my future invoices, including renewals, when I am not present.')
    && str_contains($setupView, 'Saving does not turn on Auto Pay; you can choose it for a service later.'), 'Setup has an explicit future off-session opt-in');
check(strpos($setupView, 'setup-consent\')') < strpos($setupView, 'stripe.confirmSetup'), 'Setup records consent before confirmation');
check(str_contains($setupView, 'if (error) throw error;') && str_contains($setupView, 'message.replaceChildren(text);')
    && str_contains($setupView, 'finally {') && str_contains($setupView, 'setLoading(false);'), 'Setup errors remain in alert and unlock retry');

$payView = file_get_contents($views . 'pay.blade.php');
check(str_contains($payView, "@checked($" . "intent->status === 'requires_action'")
    && str_contains($payView, 'save: resuming ? savedConsent :')
    && str_contains($payView, 'saveCard.disabled = resuming ||'), '3DS resume visibly preserves its recorded save choice');

check(str_contains($payView, 'if (customerSessionSecret) options.customerSessionClientSecret = customerSessionSecret;'),
    'Checkout only includes a Customer Session secret when available');
