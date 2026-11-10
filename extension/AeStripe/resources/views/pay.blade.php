<form id="payment-form" class="mt-2">
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <div id="payment-element">

    </div>
    <label class="mt-4 flex items-center gap-2">
        <input type="checkbox" id="save-card" @checked(in_array($intent->status, ['requires_action', 'requires_confirmation'], true) && ($intent->metadata->ae_stripe_consent ?? null) === '1') disabled />
        Save this card for next time
    </label>
    <button id="submit" disabled
        class="mt-4 bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full bg-gradient-to-tr from-secondary via-50% via-20% via-secondary to-[#5573FD80] duration-300">
        {{ __('Pay :amount', ['amount' => $paymentAmount]) }}
    </button>
    <button id="refresh-payment" type="button" hidden class="mt-4 underline w-full">
        {{ __('Refresh payment form') }}
    </button>
    {{-- Link is offered below Pay, small enough that Pay stays the main button. --}}
    <div id="express-checkout" hidden>
        <div class="mt-4 flex items-center justify-between gap-3">
            <span class="text-sm">Or pay with Link</span>
            {{-- An inline width: a theme built before this release has no w-40 class. At 160px Stripe shows the Link mark alone. --}}
            <div id="express-checkout-element" class="shrink-0" style="width: 160px"></div>
        </div>
    </div>
</form>
@script
<script>
    @include('gateways.ae-stripe::stripe-theme')
    @include('gateways.ae-stripe::stripe-elements')
    @include('gateways.ae-stripe::stripe-form')
async function initializePaymentForm() {
    // Livewire effects run before Alpine teleports the modal into body.
    const paymentForm = await new Promise((resolve) => {
        const deadline = performance.now() + 5000;
        function findForm() {
            const form = document.getElementById('payment-form');
            if (form) return resolve(form);
            if (performance.now() >= deadline) return resolve(null);
            requestAnimationFrame(findForm);
        }
        findForm();
    });
    if (!paymentForm) {
        console.error('AeStripe: payment form did not appear within 5 seconds.');
        return;
    }
    const form = paymentForm;
    form.addEventListener('submit', (event) => event.preventDefault());
    const resuming = @js(in_array($intent->status, ['requires_action', 'requires_confirmation'], true));
    const savedConsent = @js(($intent->metadata->ae_stripe_consent ?? null) === '1');
    let intentId = @js($intent->id);
    const savedNoticeToken = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    let canSave = resuming && savedConsent;
    let linkAvailable = false;
    let refreshRequired = false;
    let complete = false;
    // A declined resumed payment keeps the form and its error until the customer refreshes.
    const controls = stripeFormControls(form, {
        scrollToMessage: true, locked: () => refreshRequired, onLoading: updateSaveCard, onRetry: start,
    });
    const {showMessage, clearMessage, setLoading, initializationError} = controls;
    const refreshPayment = document.getElementById('refresh-payment');
    refreshPayment.addEventListener('click', () => window.location.reload());
    const saveCard = document.getElementById('save-card');
    // A reference, so releasing after the modal closes still works.
    const expressRow = form.querySelector('#express-checkout');
    function updateSaveCard() {
        saveCard.disabled = resuming || controls.loading || !(canSave || linkAvailable);
        if (!(canSave || linkAvailable)) saveCard.checked = false;
    }
    function updateLinkAvailability(available) {
        linkAvailable = available;
        expressRow.hidden = !linkAvailable;
        updateSaveCard();
    }
    saveCard.addEventListener('change', clearMessage);
    let release = () => {};
    $wire.on('invoice.payment.cancelled', () => release());

    // Try again releases the failed attempt and starts a new one.
    async function start() {
        release();
        const attempt = new AbortController();
        let paymentElement = null;
        let expressCheckout = null;
        let stopThemeSync = () => {};
        release = () => {
            attempt.abort();
            stopThemeSync();
            paymentElement?.destroy();
            expressCheckout?.destroy();
            paymentElement = null;
            expressCheckout = null;
            stopThemeSync = () => {};
            // Link and the card choice belong to this attempt; the next one starts without them.
            canSave = resuming && savedConsent;
            updateLinkAvailability(false);
        };
        const current = forAttempt(attempt.signal);
        try {
            await loadStripe();
            if (attempt.signal.aborted || !form.isConnected) return;

            var stripe = Stripe(
                @js($stripePublishableKey)
            );

            const options = {
                mode: 'payment',
                amount: @js($intent->amount),
                currency: @js(strtolower($intent->currency)),
                // Card and Link only: the methods Auto Pay and saved payments can charge later.
                paymentMethodTypes: ['card', 'link'],
                paymentMethodCreation: 'manual',
                appearance: stripeAppearance(form),
            };

            const customerSessionSecret = @js($customerSessionSecret);
            if (customerSessionSecret) options.customerSessionClientSecret = customerSessionSecret;

            // The server still checks the invoice balance before confirming.
            const created = createStripeElements(stripe, options, {
                linkDisplay: @js($linkDisplay),
                name: {{ \Illuminate\Support\Js::from(auth()->user()->name) }},
                email: {{ \Illuminate\Support\Js::from(auth()->user()->email) }},
                businessName: @js($businessName),
                allowExpress: !resuming,
                onLinkAvailability: current(updateLinkAvailability),
            });
            const {elements, mountExpress} = created;
            paymentElement = created.paymentElement;
            expressCheckout = created.expressCheckout;
            let finishOptionsUpdate = null;
            elements.on('update-end', () => finishOptionsUpdate?.());
            function clearDeclinedPayment() {
                paymentElement.clear();
                complete = false;
                // The theme can show the new failed row without reloading the open dialog.
                form.dispatchEvent(new CustomEvent('ae-stripe:payment-failed', {bubbles: true}));
                refreshPayment.hidden = false;
                if (resuming) {
                    refreshRequired = true;
                }
            }
            // https://docs.stripe.com/js/element/events/on_change?type=paymentElement
            paymentElement.on('change', current((event) => {
                // clear() also reports an empty form after submission has finished.
                if (!controls.loading && event.empty !== true) clearMessage();
                complete = event.complete === true;
                canSave = resuming ? savedConsent : ['card', 'link'].includes(event.value?.type);
                updateSaveCard();
            }));

            stopThemeSync = syncStripeTheme(form, elements);

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                confirmPayment();
            }, {signal: attempt.signal});
            expressCheckout?.on('confirm', (event) => confirmPayment(event));

            async function confirmPayment(expressEvent = null) {
                if (attempt.signal.aborted || !controls.ready || controls.loading || refreshRequired) {
                    expressEvent?.paymentFailed({reason: 'fail'});
                    return;
                }
                // Link uses the same explicit opt-in, even when another method is selected below it.
                const save = resuming ? savedConsent : (expressEvent !== null || canSave) && saveCard.checked;
                function failPayment(message) {
                    showMessage(message);
                    expressEvent?.paymentFailed({reason: 'fail'});
                }
                clearMessage();
                setLoading(true);

                try {
                    // An unfinished card shows its field errors from Stripe.js, with no server request and no second
                    // copy of the first error under the form.
                    if (!resuming && !expressEvent && !complete) {
                        const checked = await elements.submit();
                        if (checked.error?.type === 'validation_error') return;
                        if (checked.error) throw checked.error;
                    }
                    let token = null;
                    let authFailed = false;
                    if (!resuming) {
                        // Deferred Elements and confirmation must agree on the customer's saving choice.
                        // Current releases return a Promise; older releases signal update-end.
                        await new Promise((resolve, reject) => {
                            const finish = (error = null) => {
                                if (finishOptionsUpdate !== finish) return;
                                finishOptionsUpdate = null;
                                clearTimeout(timer);
                                error ? reject(error) : resolve();
                            };
                            const timer = setTimeout(() => finish(new Error('Payment options did not update.')), 5000);
                            finishOptionsUpdate = finish;
                            try {
                                const updating = elements.update({paymentMethodOptions: {
                                    card: {setup_future_usage: save ? 'off_session' : 'none'},
                                    link: {setup_future_usage: save ? 'off_session' : 'none'},
                                }});
                                if (typeof updating?.then === 'function') {
                                    updating.then(() => finish(), error => finish(error || new Error('Payment options did not update.')));
                                }
                            } catch (error) { finish(error); }
                        });
                        const submitted = await elements.submit();
                        if (submitted.error) throw submitted.error;
                        const created = await stripe.createConfirmationToken({elements});
                        if (created.error) throw created.error;
                        token = created.confirmationToken.id;
                    }
                    // The first confirmation checks the balance. Automatic 3DS completes at that amount;
                    // the next server call reads its result. Native manual payments may need reconfirmation.
                    for (let step = 0; step < 3; step++) {
                        const response = await fetch(@js(route('extensions.gateways.ae-stripe.confirm')), {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                                'X-CSRF-TOKEN': @js(csrf_token())},
                            body: JSON.stringify({intent: intentId, confirmation_token: token,
                                amount: @js($intent->amount), save, saved_notice: savedNoticeToken}),
                        });
                        const result = await response.json();
                        if (result.intent) intentId = result.intent;
                        if (!response.ok) {
                            // A declined card number stays out of the form, so the customer types a different card.
                            // After a failed bank check the reason is that check, even when Stripe no longer reports it.
                            if (result.declined) clearDeclinedPayment();
                            failPayment(refreshRequired ? 'This payment was declined. Refresh the payment form to try another card.'
                                : authFailed && result.declined ? 'Your bank did not confirm this payment. You were not charged. Try again or use a different card.'
                                : result.message || 'Could not confirm your payment. Refresh the invoice for its status.');
                            return;
                        }
                        if (result.status === 'requires_action') {
                            // A bank check that failed or was cancelled still asks the server, which records the
                            // refusal and answers with the reason. One that did not finish is not opened again.
                            if (authFailed) {
                                failPayment('Your bank did not confirm this payment. You were not charged. Try again or use a different card.');
                                return;
                            }
                            const next = await stripe.handleNextAction({clientSecret: result.client_secret});
                            authFailed = Boolean(next.error);
                            token = null;
                            continue;
                        }
                        if (['succeeded', 'processing', 'requires_capture'].includes(result.status)) {
                            const destination = new URL(@js(route('invoices.show', $invoice)), window.location.href);
                            destination.searchParams.set('checkPayment', 'true');
                            if (result.saved_notice) destination.searchParams.set('saved_notice', result.saved_notice);
                            window.location.assign(destination.toString());
                            return;
                        }
                        clearDeclinedPayment();
                        failPayment(refreshRequired ? 'This payment was declined. Refresh the payment form to try another card.'
                            : 'This payment was declined. Try a different card or payment method.');
                        return;
                    }
                    failPayment('Payment still needs authentication. Refresh the invoice to continue.');
                } catch (error) {
                    if (resuming && error?.type === 'card_error') clearDeclinedPayment();
                    // Stripe.js already shows a validation error beside its field.
                    if (error?.type === 'validation_error') {
                        expressEvent?.paymentFailed({reason: 'fail'});
                        return;
                    }
                    failPayment(refreshRequired ? 'This payment was declined. Refresh the payment form to try another card.'
                        : ['card_error', 'validation_error'].includes(error?.type) && error.message
                        ? error.message : 'Could not check the payment. Refresh the invoice for its status.');
                } finally {
                    setLoading(false);
                }
            }

            paymentElement.on('ready', current(() => { controls.ready = true; setLoading(false); }));
            paymentElement.on('loaderror', current(initializationError));
            paymentElement.mount('#payment-element');
            mountExpress('#express-checkout-element');
        } catch (error) {
            release();
            initializationError();
        }
    }
    return start();
}
initializePaymentForm();
</script>
@endscript
