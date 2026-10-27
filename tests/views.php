<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Static view contracts complement the offline browser lifecycle test.
$views = __DIR__ . '/../extension/AeStripe/resources/views/';
$theme = file_get_contents($views . 'stripe-theme.blade.php');
foreach (['pay', 'billing-agreement'] as $name) {
    $view = file_get_contents($views . $name . '.blade.php');
    $scriptPosition = strpos($view, '<script>');
    check($scriptPosition !== false, "$name: payment script exists");
    $script = substr($view, $scriptPosition + strlen('<script>'));
    if ($name === 'pay') {
        $entry = strpos($script, 'async function initializePaymentForm() {');
        check($entry !== false && !preg_match('/\b(?:getElementById|querySelector(?:All)?)\s*\(/', substr($script, 0, $entry)),
            "$name: no DOM lookup runs before deferred initialization");
        check(preg_match('/\n}\s*initializePaymentForm\(\);\s*<\/script>/', $script) === 1,
            "$name: only the deferred entry point runs after its definition");
        $readyPosition = strpos($script, 'let ready = false;');
        check($readyPosition !== false && $entry < $readyPosition, "$name: readiness follows initialization");
        $wait = substr($script, $entry, $readyPosition - $entry);
        check(str_contains($wait, 'const deadline = performance.now() + 5000;')
            && str_contains($wait, 'if (performance.now() >= deadline) return resolve(null);')
            && str_contains($wait, 'requestAnimationFrame(findForm);')
            && str_contains($wait, 'findForm();'), "$name: form lookup checks now and waits at most five seconds");
        check(str_contains($wait, 'const paymentForm = await new Promise(')
            && str_contains($wait, "if (!paymentForm) {")
            && str_contains($wait, "console.error('AeStripe: payment form did not appear within 5 seconds.');")
            && str_contains($wait, 'return;'), "$name: missing form logs once and stops initialization");
        check(str_contains($view, "if (submit) submit.disabled = true;")
            && str_contains($view, "if (message) message.textContent = 'Could not load the payment form. Reload to try again.';"),
            "$name: initialization errors tolerate missing button and alert");
    } else {
        check(str_contains($view, 'x-data="aeStripeSetup({') && str_contains($script, "Alpine.data('aeStripeSetup'"),
            'Setup initializes for each Alpine form, including reopened modals');
        check(str_contains($script, 'const form = this.$el;') && !str_contains($script, 'document.getElementById(')
            && !str_contains(substr($script, strpos($script, "Alpine.data('aeStripeSetup'")), 'document.querySelector('), 'Setup DOM access stays scoped to its own form');
        check(str_contains($script, 'destroy() {') && str_contains($script, 'controller.abort();')
            && str_contains($script, 'paymentElement?.destroy();'), 'Setup form removal releases listeners and Elements');
    }
    check(!str_contains($view, 'setTimeout'), "$name: payment errors are never hidden on a timer");
    check(str_contains($view, '<div id="error-message" role="alert"'), "$name: errors are announced and stay mounted");
    check(preg_match('/preventDefault\(\);\s+(?:if \(disposed \|\| loading \|\| !ready\) return;\s+)?(clearMessage\(\)|message\.replaceChildren\(\));\s+setLoading\(true\)/', $view) === 1,
        "$name: the next submit clears the previous error");
    check(!preg_match("/theme:\s*'(night|stripe)'/", $view), "$name: Stripe theme is not hard-coded");
    check(str_contains($view, "@include('gateways.ae-stripe::stripe-theme')")
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
    check(str_contains($view, "paymentElement.on('ready'") && str_contains($view, $name === 'pay' ? 'script.onerror = initializationError' : 'script.onerror = () => {'), "$name: readiness gates submission and script failure shows an error");
    $handlerPosition = strpos($view, "form.addEventListener('submit'");
    $mountPosition = strpos($view, "paymentElement.mount(");
    check($handlerPosition !== false && $mountPosition !== false && $handlerPosition < $mountPosition, "$name: submit handler is installed before mounting");
}
$setupView = file_get_contents($views . 'billing-agreement.blade.php');
check(str_contains($setupView, 'id="setup-consent" required disabled') && str_contains($setupView, 'I agree to save this payment method and allow charges to it for my future invoices, including renewals, when I am not present.')
    && str_contains($setupView, 'Saving does not turn on Auto Pay; you can choose it for a service later.'), 'Setup has an explicit future off-session opt-in');
check(str_contains($setupView, "document.querySelector('script[src^=\"https://js.stripe.com/v3\"]')"), 'Setup reuses an existing Stripe.js script');
check(str_contains($setupView, 'if (window.Stripe) {')
    && str_contains($setupView, 'stripeScript = null;')
    && !str_contains($setupView, 'script.onload = resolve;'), 'Setup checks the loaded Stripe global and clears failed cached loads');
check(str_contains($setupView, 'AbortSignal.timeout(5000)')
    && str_contains($setupView, "timeout.addEventListener('abort', failed, {once: true})")
    && str_contains($setupView, "timeout.removeEventListener('abort', failed)"), 'Setup bounds already-finished script waits and releases the timeout listener');
check(str_contains($setupView, 'if (!existingScript) script.remove();'), 'Setup removes failed scripts only when it owns them');
$recordConsentCall = 'await fetch(config.consentUrl';
$confirmSetupCall = 'await stripe.confirmSetup(';
$recordsConsentBeforeConfirmation = static function ($view) use ($recordConsentCall, $confirmSetupCall) {
    $consentPosition = strpos($view, $recordConsentCall);
    $confirmPosition = strpos($view, $confirmSetupCall);
    return $consentPosition !== false && $confirmPosition !== false && $consentPosition < $confirmPosition;
};
check($recordsConsentBeforeConfirmation($setupView), 'Setup records consent before confirmation');
check(!$recordsConsentBeforeConfirmation(str_replace($recordConsentCall, '', $setupView)),
    'Setup order check rejects missing consent recording despite the DOM reference');
check(!$recordsConsentBeforeConfirmation(str_replace($recordConsentCall, substr($recordConsentCall, strlen('await ')), $setupView)),
    'Setup order check requires awaited consent recording');
check(!$recordsConsentBeforeConfirmation(str_replace($confirmSetupCall, '', $setupView)),
    'Setup order check rejects missing confirmation');
check(!$recordsConsentBeforeConfirmation(strtr($setupView, [$recordConsentCall => $confirmSetupCall, $confirmSetupCall => $recordConsentCall])),
    'Setup order check rejects confirmation before consent recording');
$consentFailureGuard = "if (!result.ok) throw new Error('Could not record your agreement. Try again.');";
$guardsConsentBeforeConfirmation = static function ($view) use ($recordConsentCall, $consentFailureGuard, $confirmSetupCall) {
    $consentPosition = strpos($view, $recordConsentCall);
    $guardPosition = strpos($view, $consentFailureGuard);
    $confirmPosition = strpos($view, $confirmSetupCall);
    return $consentPosition !== false && $guardPosition !== false && $confirmPosition !== false
        && $consentPosition < $guardPosition && $guardPosition < $confirmPosition;
};
check($guardsConsentBeforeConfirmation($setupView), 'Setup stops on failed consent before confirmation');
$setupWithoutConsentGuard = str_replace($consentFailureGuard, '', $setupView);
check(!$guardsConsentBeforeConfirmation($setupWithoutConsentGuard),
    'Setup consent guard check rejects a missing failure guard');
check(!$guardsConsentBeforeConfirmation(str_replace($recordConsentCall, $consentFailureGuard . $recordConsentCall, $setupWithoutConsentGuard)),
    'Setup consent guard check rejects a failure guard before the consent request');
check(!$guardsConsentBeforeConfirmation(str_replace($confirmSetupCall, $confirmSetupCall . $consentFailureGuard, $setupWithoutConsentGuard)),
    'Setup consent guard check rejects a failure guard after confirmation');
check(str_contains($setupView, 'if (error) throw error;') && str_contains($setupView, 'message.replaceChildren(text);')
    && str_contains($setupView, 'finally {') && str_contains($setupView, 'setLoading(false);'), 'Setup errors remain in alert and unlock retry');

$payView = file_get_contents($views . 'pay.blade.php');
check(str_contains($payView, "@checked($" . "intent->status === 'requires_action'")
    && str_contains($payView, 'save: resuming ? savedConsent :')
    && str_contains($payView, 'saveCard.disabled = resuming ||'), '3DS resume visibly preserves its recorded save choice');

check(str_contains($payView, 'if (customerSessionSecret) options.customerSessionClientSecret = customerSessionSecret;'),
    'Checkout only includes a Customer Session secret when available');

$syncPosition = strpos($theme, 'function syncStripeTheme');
check($syncPosition !== false, 'Theme synchronization function exists');
$syncTheme = substr($theme, $syncPosition);
check(str_contains($syncTheme, 'for (let node = form; node; node = node.parentElement)')
    && str_contains($syncTheme, "observer.observe(node, {attributes: true, attributeFilter: ['class', 'data-theme', 'style']})"),
    'Theme observer follows every ancestor read by stripeAppearance');
