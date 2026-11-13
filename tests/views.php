<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Static view contracts complement the offline browser lifecycle test.
$views = __DIR__ . '/../extension/AeStripe/resources/views/';
$theme = file_get_contents($views . 'stripe-theme.blade.php');
$formView = file_get_contents($views . 'stripe-form.blade.php');
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
        $readyPosition = strpos($script, 'const controls = stripeFormControls(form, {');
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
    } else {
        check(str_contains($view, 'x-data="aeStripeSetup({') && str_contains($script, "Alpine.data('aeStripeSetup'"),
            'Setup initializes for each Alpine form, including reopened modals');
        check(str_contains($script, 'const form = this.$el;') && !str_contains($script, 'document.getElementById(')
            && !str_contains(substr($script, strpos($script, "Alpine.data('aeStripeSetup'")), 'document.querySelector('), 'Setup DOM access stays scoped to its own form');
        check(str_contains($script, 'destroy() {') && str_contains($script, 'controller.abort();')
            && str_contains($script, 'paymentElement?.destroy();'), 'Setup form removal releases listeners and Elements');
    }
    check(str_contains($script, "@include('gateways.ae-stripe::stripe-form')")
        && !preg_match('/function (?:loadStripe|showMessage|clearMessage|setLoading|initializationError)\(/', $script)
        && str_contains($script, 'const {showMessage, clearMessage, setLoading, initializationError} = controls;'),
        "$name: the Stripe.js loader, alert and loading state come from the shared form script");
    check(str_contains($view, '<div id="error-message" role="alert"'), "$name: errors are announced and stay mounted");
    // The form and the Link button share one confirm function; it clears the previous error before loading.
    check(preg_match('/preventDefault\(\);\s+confirm(Payment|Setup)\(\);/', $view, $submit) === 1
        && preg_match('/async function confirm' . $submit[1] . '\(expressEvent = null\) \{.*?(clearMessage\(\)|message\.replaceChildren\(\));\s+setLoading\(true\)/s', $view) === 1,
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
check(!preg_match('/#[0-9a-f]{3,6}\b/i', $theme), 'No brand colours are hard-coded');
check(str_contains($theme, "getPropertyValue('--ae-stripe-' + name)") && str_contains($theme, 'colorPrimary: primary,')
    && str_contains($theme, 'colorBackground: input,') && str_contains($theme, "if (border) rules['.Input']")
    && str_contains($theme, 'JSON.stringify(appearance) === current'),
    'Stripe fields take the theme colours from --ae-stripe-* properties and follow their changes');
foreach (['pay', 'billing-agreement'] as $name) {
    $view = file_get_contents($views . $name . '.blade.php');
    check(str_contains($view, 'Js::from(auth()->user()->name)') && str_contains($view, 'Js::from(auth()->user()->email)'), "$name: customer details use script-safe values");
    check(str_contains($view, '<button id="submit" disabled'), "$name: submission starts disabled");
    check(str_contains($view, "paymentElement.on('ready', current(() => { controls.ready = true; setLoading(false); }));")
        && str_contains($view, "paymentElement.on('loaderror', current(initializationError));")
        && preg_match('/await loadStripe\(\);.*\} catch \(error\) \{\s+release\(\);\s+initializationError\(\);/s', $view) === 1
        && str_contains($view, 'onRetry: start') && !str_contains($view, "createElement('script')"),
        "$name: readiness gates submission, and a Stripe.js or Element failure shows the error with Try again");
    check(str_contains($view, 'const current = forAttempt(attempt.signal);')
        && str_contains($view, 'onLinkAvailability: current(') && str_contains($view, "paymentElement.on('change', current(")
        && preg_match('/async function confirm(?:Payment|Setup)\(expressEvent = null\) \{\s+if \((?:disposed \|\| )?attempt\.signal\.aborted \|\| /', $view) === 1,
        "$name: events from a released attempt cannot change Link, readiness, errors or confirmation");
    $handlerPosition = strpos($view, "form.addEventListener('submit'");
    $mountPosition = strpos($view, "paymentElement.mount(");
    check($handlerPosition !== false && $mountPosition !== false && $handlerPosition < $mountPosition, "$name: submit handler is installed before mounting");
}
// CI has no browser; payment-errors.mjs checks the options Stripe receives.
$elementsView = file_get_contents($views . 'stripe-elements.blade.php');
check(str_contains($elementsView, 'const business = businessName ? {business: {name: businessName}} : {};')
    && substr_count($elementsView, '...business,') === 2
    && str_contains(file_get_contents($views . 'pay.blade.php'), 'businessName: @js($businessName),')
    && str_contains(file_get_contents($views . 'billing-agreement.blade.php'), 'businessName: config.businessName'),
    'Both Payment Elements and the Link button name the site when it has a name');
$setupView = file_get_contents($views . 'billing-agreement.blade.php');
check(!str_contains($setupView, 'id="setup-consent"') && !str_contains($setupView, 'Tick the box'),
    'Setup has no box to tick, so neither choice can fail for a missing tick');
check(str_contains($formView, "document.querySelector('script[src^=\"https://js.stripe.com/v3\"]')"), 'Both forms reuse an existing Stripe.js script');
check(str_contains($formView, 'const state = window.aeStripeLoader ??= {pending: null, timedOut: new WeakSet()};'),
    'Every form and reopen on the page shares one Stripe.js request');
check(str_contains($formView, 'if (window.Stripe) {')
    && str_contains($formView, 'state.pending = null;')
    && !str_contains($formView, 'script.onload = resolve;'), 'Both forms check the loaded Stripe global and clear failed cached loads');
check(str_contains($formView, '}, existingScript ? 5000 : 30000)')
    && str_contains($formView, 'clearTimeout(timeout)'), 'Both forms allow fresh requests thirty seconds, bound reused waits at five seconds and clear the timer');
check(str_contains($formView, 'if (!existingScript) script.remove();'), 'Both forms remove failed scripts only when they own them');
check(str_contains($formView, 'if (isDisposed() || locked() || !controls.ready) return;'),
    'Editing the form keeps a load error and its Try again until the form is ready');
check(str_contains($formView, "controls.showMessage('Could not load the payment form. Check your connection and try again.', onRetry);")
    && str_contains($formView, "button.type = 'button';") && str_contains($formView, "button.textContent = 'Try again';"),
    'A failed load says so and offers Try again, so the form never stays silently disabled');
check(str_contains($formView, 'if (isDisposed() || !alert) return;') && str_contains($formView, 'if (submit) {'),
    'Initialization errors tolerate a missing button and alert');
preg_match_all('/controls\.(?:showMessage|clearMessage) = \([^)]*\) => \{.*?\n    \};/s', $formView, $messageFunctions);
check(count($messageFunctions[0]) === 2 && !str_contains(implode('', $messageFunctions[0]), 'setTimeout'), 'Displaying an error never schedules its removal');
$recordConsentCall = 'await fetch(config.consentUrl';
$order = array_map(fn ($needle) => strpos($setupView, $needle),
    ['id="setup-link"', 'id="express-checkout"', 'or add a card', 'id="payment-element"', 'id="error-message"', 'id="submit"']);
$sortedOrder = $order;
sort($sortedOrder);
check(!in_array(false, $order, true) && $order === $sortedOrder,
    'Setup shows the Link wallet, then "or add a card", the card fields and Save');
check(preg_match('/id="express-checkout"[^>]*\binert\b/', $setupView) === 1
    && str_contains($setupView, 'stripeFormControls(form, {inertUntilReady: true,')
    && str_contains($formView, 'const closed = isLoading || locked() || (inertUntilReady && !controls.ready);')
    && str_contains($formView, 'for (const field of fields) field.inert = closed;')
    && str_contains($formView, 'submit.disabled = isLoading || locked() || !controls.ready;'),
    'Link and the card form open together once Stripe is ready and lock together while one saves');
check(str_contains($setupView, 'body: JSON.stringify({intent: config.intent, consent: true, version: config.consentVersion}),')
    && str_contains($setupView, 'consentVersion: @js($saveConsentVersion)'),
    'Choosing Link or Save card records the saving consent version the gateway names');
check(str_contains($setupView, 'Save card') && !str_contains($setupView, 'Save payment method'), 'Setup button names what it saves');
check(str_contains($setupView, 'ownTerms: true,') && str_contains($elementsView, "if (ownTerms) paymentOptions.terms = {card: 'never'};"),
    'Setup shows one consent text: Stripe card terms are hidden where the form has its own');
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
$consentFailureGuard = "if (!result.ok) {\n                                failSetup('Could not record your agreement. Try again.');\n                                return;\n                            }";
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
check(str_contains($setupView, 'if (error) throw error;') && str_contains($formView, 'alert.replaceChildren(message);')
    && str_contains($setupView, 'finally {') && str_contains($setupView, 'setLoading(false);'), 'Setup errors remain in alert and unlock retry');

$payView = file_get_contents($views . 'pay.blade.php');
check(str_contains($payView, "paymentMethodTypes: ['card', 'link'],") && !str_contains($payView, 'excludedPaymentMethodTypes'),
    'The pay form offers only card and Link');
check(strpos($payView, 'id="express-checkout"') > strpos($payView, 'id="submit"') && str_contains($payView, '<div id="express-checkout-element" class="shrink-0" style="width: 160px"></div>'),
    'pay: Link is a small button below the main button');
check(str_contains($setupView, '<div id="express-checkout-element" class="shrink-0" style="width: 160px"></div>'),
    'billing-agreement: Link is a small button, so Save card stays the main button');
check(str_contains($elementsView, "layout: 'tabs',") && str_contains($elementsView, 'buttonHeight: 40,'),
    'Card fields show without a panel around them and the Link button keeps its smallest height');
check(str_contains($payView, "@checked(in_array($" . "intent->status, ['requires_action', 'requires_confirmation']")
    && str_contains($payView, 'const save = resuming ? savedConsent :') && str_contains($payView, 'amount: @js($intent->amount), save, saved_notice: savedNoticeToken}')
    && str_contains($payView, 'saveCard.disabled = resuming ||'), '3DS resume visibly preserves its recorded save choice');

check(str_contains($payView, 'if (customerSessionSecret) options.customerSessionClientSecret = customerSessionSecret;'),
    'Checkout only includes a Customer Session secret when available');

$syncPosition = strpos($theme, 'function syncStripeTheme');
check($syncPosition !== false, 'Theme synchronization function exists');
$syncTheme = substr($theme, $syncPosition);
check(str_contains($syncTheme, 'for (let node = form; node; node = node.parentElement)')
    && str_contains($syncTheme, "observer.observe(node, {attributes: true, attributeFilter: ['class', 'data-theme', 'style']})"),
    'Theme observer follows every ancestor read by stripeAppearance');

check(str_contains($payView, "['amount' => \$paymentAmount]") && !str_contains($payView, '$invoice->formattedRemaining'),
    'The Pay button uses the formatted intent amount passed by checkout');
