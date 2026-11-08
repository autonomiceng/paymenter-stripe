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
    let ready = false;
    paymentForm.addEventListener('submit', (event) => event.preventDefault());
    function initializationError() {
        ready = false;
        const submit = paymentForm.querySelector('#submit');
        const message = paymentForm.querySelector('#error-message');
        if (submit) submit.disabled = true;
        if (message) message.textContent = 'Could not load the payment form. Reload to try again.';
    }
    const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onerror = initializationError;
        script.onload = () => {
            try {

            var stripe = Stripe(
                @js($stripePublishableKey)
            );

            const form = document.getElementById('payment-form');
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

            const resuming = @js(in_array($intent->status, ['requires_action', 'requires_confirmation'], true));
            const savedConsent = @js(($intent->metadata->ae_stripe_consent ?? null) === '1');
            let intentId = @js($intent->id);
            // The server still checks the invoice balance before confirming.
            const {elements, paymentElement, expressCheckout, mountExpress} = createStripeElements(stripe, options, {
                linkDisplay: @js($linkDisplay),
                name: {{ \Illuminate\Support\Js::from(auth()->user()->name) }},
                email: {{ \Illuminate\Support\Js::from(auth()->user()->email) }},
                businessName: @js($businessName),
                allowExpress: !resuming,
                onLinkAvailability: updateLinkAvailability,
            });
            let finishOptionsUpdate = null;
            elements.on('update-end', () => finishOptionsUpdate?.());
            let canSave = resuming && savedConsent;
            let linkAvailable = false;
            let loading = false;
            let refreshRequired = false;
            const refreshPayment = document.getElementById('refresh-payment');
            refreshPayment.addEventListener('click', () => window.location.reload());
            function clearDeclinedPayment() {
                paymentElement.clear();
                refreshPayment.hidden = false;
                if (resuming) {
                    refreshRequired = true;
                }
            }
            const saveCard = document.getElementById('save-card');
            function updateSaveCard() {
                saveCard.disabled = resuming || loading || !(canSave || linkAvailable);
                if (!(canSave || linkAvailable)) saveCard.checked = false;
            }
            function updateLinkAvailability(available) {
                linkAvailable = available;
                document.getElementById('express-checkout').hidden = !linkAvailable;
                updateSaveCard();
            }
            // https://docs.stripe.com/js/element/events/on_change?type=paymentElement
            paymentElement.on('change', (event) => {
                // clear() also reports an empty form after submission has finished.
                if (!loading && event.empty !== true) clearMessage();
                canSave = resuming ? savedConsent : ['card', 'link'].includes(event.value?.type);
                updateSaveCard();
            });

            saveCard.addEventListener('change', clearMessage);
            const stopThemeSync = syncStripeTheme(form, elements);

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                confirmPayment();
            });
            expressCheckout?.on('confirm', (event) => confirmPayment(event));

            async function confirmPayment(expressEvent = null) {
                if (!ready || loading || refreshRequired) {
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
                    let token = null;
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
                                amount: @js($intent->amount), save}),
                        });
                        const result = await response.json();
                        if (result.intent) intentId = result.intent;
                        if (!response.ok) {
                            // A declined card number stays out of the form, so the customer types a different card.
                            if (result.declined) clearDeclinedPayment();
                            failPayment(refreshRequired ? 'This payment was declined. Refresh the payment form to try another card.'
                                : result.message || 'Could not confirm your payment. Refresh the invoice for its status.');
                            return;
                        }
                        if (result.status === 'requires_action') {
                            const next = await stripe.handleNextAction({clientSecret: result.client_secret});
                            if (next.error) throw next.error;
                            token = null;
                            continue;
                        }
                        if (['succeeded', 'processing', 'requires_capture'].includes(result.status)) {
                            window.location.assign(@js(route('invoices.show', $invoice) . '?checkPayment=true'));
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
                    failPayment(refreshRequired ? 'This payment was declined. Refresh the payment form to try another card.'
                        : ['card_error', 'validation_error'].includes(error?.type) && error.message
                        ? error.message : 'Could not check the payment. Refresh the invoice for its status.');
                } finally {
                    setLoading(false);
                }
            }

            // The alert region stays mounted so screen readers announce new text; it keeps the
            // error until the customer edits the form or submits again.
            function showMessage(messageText) {
                const message = document.createElement('p');
                message.className = 'mb-2';
                message.textContent = messageText;
                const alert = document.getElementById('error-message');
                alert.replaceChildren(message);
                alert.scrollIntoView({block: 'nearest', behavior: 'instant'});
            }

            function clearMessage() {
                if (refreshRequired) return;
                document.getElementById('error-message').replaceChildren();
            }

            // Show a spinner on payment submission
            function setLoading(isLoading) {
                loading = isLoading;
                document.getElementById("payment-element").inert = isLoading || refreshRequired;
                document.getElementById('express-checkout').inert = isLoading || refreshRequired;
                updateSaveCard();
                if (isLoading) {
                    // Disable the button and show a spinner
                    document.querySelector("#submit").disabled = true;
                    document.querySelector("#submit").classList.add("hidden");
                } else {
                    document.querySelector("#submit").disabled = !ready || refreshRequired;
                    document.querySelector("#submit").classList.remove("hidden");
                }
            }

            paymentElement.on('ready', () => { ready = true; setLoading(false); });
            paymentElement.on('loaderror', initializationError);
            paymentElement.mount('#payment-element');
            mountExpress('#express-checkout-element');

            $wire.on('invoice.payment.cancelled', () => {
                // destroy the payment element
                stopThemeSync();
                paymentElement.destroy();
                expressCheckout?.destroy();
            });
            } catch (error) { initializationError(); }
        };
        document.body.appendChild(script);
}
initializePaymentForm();
</script>
@endscript
