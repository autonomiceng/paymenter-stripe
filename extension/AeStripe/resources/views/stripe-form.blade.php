// Both forms share one Stripe.js loader, alert and loading state; each keeps its own confirmation flow.
// The load state lives on window, so every form and every reopen on the page shares one request.
function loadStripe() {
    if (window.Stripe) return Promise.resolve();
    const state = window.aeStripeLoader ??= {pending: null, timedOut: new WeakSet()};
    if (!state.pending) {
        state.pending = new Promise((resolve, reject) => {
            let existingScript = document.querySelector('script[src^="https://js.stripe.com/v3"]');
            if (existingScript && state.timedOut.has(existingScript)) existingScript = null;
            const script = existingScript || document.createElement('script');
            const cleanup = () => {
                clearTimeout(timeout);
                script.removeEventListener('load', loaded);
                script.removeEventListener('error', failed);
                if (!existingScript) {
                    script.onload = null;
                    script.onerror = null;
                }
            };
            const loaded = () => {
                if (window.Stripe) {
                    cleanup();
                    return resolve();
                }
                failed();
            };
            const failed = () => {
                cleanup();
                state.pending = null;
                if (existingScript && !window.Stripe) state.timedOut.add(existingScript);
                if (!existingScript) script.remove();
                reject(new Error('Could not load Stripe.js.'));
            };
            // An existing tag may have finished before these listeners were added.
            // Give fresh requests more time, but allow retry if neither event fires.
            const timeout = setTimeout(() => {
                failed();
            }, existingScript ? 5000 : 30000);
            if (existingScript) {
                script.addEventListener('load', loaded, {once: true});
                script.addEventListener('error', failed, {once: true});
            } else {
                script.src = 'https://js.stripe.com/v3/';
                script.async = true;
                script.onload = loaded;
                script.onerror = () => { failed(); };
                document.body.appendChild(script);
            }
        });
    }
    return state.pending;
}
// Pay and Save card stay off until the Payment Element is ready, and lock while one confirms.
// The alert stays mounted so screen readers announce new text; an error stays until the
// customer edits the form or submits again. Options cover what only one form needs:
// locked keeps the form and its error in place after a decline that needs a refresh,
// inertUntilReady keeps the fields closed until Stripe is ready, and onRetry runs Try again.
function stripeFormControls(form, {onRetry, onLoading = () => {}, locked = () => false, isDisposed = () => false,
    inertUntilReady = false, scrollToMessage = false} = {}) {
    const submit = form.querySelector('#submit');
    const alert = form.querySelector('#error-message');
    const fields = ['#payment-element', '#express-checkout'].map(selector => form.querySelector(selector)).filter(Boolean);
    const controls = {ready: false, loading: false};
    controls.showMessage = (text, retry = null) => {
        if (isDisposed() || !alert) return;
        const message = document.createElement('p');
        message.className = 'mb-2';
        message.textContent = text;
        alert.replaceChildren(message);
        if (retry) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'mb-2 underline';
            button.textContent = 'Try again';
            button.addEventListener('click', () => {
                alert.replaceChildren();
                retry();
            }, {once: true});
            alert.append(button);
        }
        if (scrollToMessage) alert.scrollIntoView({block: 'nearest', behavior: 'instant'});
    };
    controls.clearMessage = () => {
        if (isDisposed() || locked()) return;
        alert?.replaceChildren();
    };
    controls.setLoading = (isLoading) => {
        controls.loading = isLoading;
        if (isDisposed()) return;
        const closed = isLoading || locked() || (inertUntilReady && !controls.ready);
        for (const field of fields) field.inert = closed;
        if (submit) {
            submit.disabled = isLoading || locked() || !controls.ready;
            submit.classList.toggle('hidden', isLoading);
        }
        onLoading(isLoading);
    };
    // Stripe.js or the Payment Element failed to load. The form stays off and Try again loads it again.
    controls.initializationError = () => {
        controls.ready = false;
        controls.setLoading(false);
        controls.showMessage('Could not load the payment form. Check your connection and try again.', onRetry);
    };
    return controls;
}
