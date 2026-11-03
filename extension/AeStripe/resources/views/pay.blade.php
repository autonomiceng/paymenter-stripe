<form id="payment-form" class="mt-2">
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <div id="payment-element">

    </div>
    <label class="mt-4 block">
        <input type="checkbox" id="save-card" @checked(in_array($intent->status, ['requires_action', 'requires_confirmation'], true) && ($intent->metadata->ae_stripe_consent ?? null) === '1') disabled />
        Save this card or Link for future payments
    </label>
    <p class="text-sm">Saving does not turn on Auto Pay.</p>
    <button id="submit" disabled
        class="mt-4 bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full bg-gradient-to-tr from-secondary via-50% via-20% via-secondary to-[#5573FD80] duration-300">
        Pay
    </button>
</form>
@script
<script>
    @include('gateways.ae-stripe::stripe-theme')
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
                excludedPaymentMethodTypes: ['blik', 'acss_debit', 'customer_balance'],
                paymentMethodCreation: 'manual',
                appearance: stripeAppearance(form),
            };

            const customerSessionSecret = @js($customerSessionSecret);
            if (customerSessionSecret) options.customerSessionClientSecret = customerSessionSecret;

            const paymentElementOptions = {
                layout: {
                    type: 'accordion',
                    defaultCollapsed: false,
                    radios: false,
                    spacedAccordionItems: true
                },
                defaultValues: {
                    billingDetails: {
                        name: {{ \Illuminate\Support\Js::from(auth()->user()->name) }},
                        email: {{ \Illuminate\Support\Js::from(auth()->user()->email) }},
                    }
                }
            }

            // Deferred Elements + ConfirmationToken: the server checks the balance before confirming.
            // https://docs.stripe.com/payments/finalize-payments-on-the-server
            const elements = stripe.elements(options);
            const paymentElement = elements.create('payment', paymentElementOptions);
            const resuming = @js(in_array($intent->status, ['requires_action', 'requires_confirmation'], true));
            const savedConsent = @js(($intent->metadata->ae_stripe_consent ?? null) === '1');
            let canSave = resuming && savedConsent;
            let loading = false;
            const saveCard = document.getElementById('save-card');
            // https://docs.stripe.com/js/element/events/on_change?type=paymentElement
            paymentElement.on('change', (event) => {
                if (!loading) clearMessage();
                canSave = resuming ? savedConsent : ['card', 'link'].includes(event.value?.type);
                saveCard.disabled = resuming || loading || !canSave;
                if (!canSave) saveCard.checked = false;
            });

            saveCard.addEventListener('change', clearMessage);
            const stopThemeSync = syncStripeTheme(form, elements);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearMessage();
                setLoading(true);
                
                try {
                    let token = null;
                    if (!resuming) {
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
                            body: JSON.stringify({intent: @js($intent->id), confirmation_token: token,
                                amount: @js($intent->amount), save: resuming ? savedConsent : canSave && saveCard.checked}),
                        });
                        const result = await response.json();
                        if (!response.ok) { showMessage(result.message || 'Could not confirm your payment. Refresh the invoice for its status.'); return; }
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
                        showMessage('This payment was declined. Choose another payment method.');
                        return;
                    }
                    showMessage('Payment still needs authentication. Refresh the invoice to continue.');
                } catch (error) {
                    showMessage(['card_error', 'validation_error'].includes(error?.type) && error.message
                        ? error.message : 'Could not check the payment. Refresh the invoice for its status.');
                } finally {
                    setLoading(false);
                }
            });

            // The alert region stays mounted so screen readers announce new text; it keeps the
            // error until the customer edits the form or submits again.
            function showMessage(messageText) {
                const message = document.createElement('p');
                message.className = 'mb-2';
                message.textContent = messageText;
                document.getElementById('error-message').replaceChildren(message);
            }

            function clearMessage() {
                document.getElementById('error-message').replaceChildren();
            }

            // Show a spinner on payment submission
            function setLoading(isLoading) {
                loading = isLoading;
                document.getElementById("payment-element").inert = isLoading;
                saveCard.disabled = resuming || isLoading || !canSave;
                if (isLoading) {
                    // Disable the button and show a spinner
                    document.querySelector("#submit").disabled = true;
                    document.querySelector("#submit").classList.add("hidden");
                } else {
                    document.querySelector("#submit").disabled = !ready;
                    document.querySelector("#submit").classList.remove("hidden");
                }
            }

            paymentElement.on('ready', () => { ready = true; setLoading(false); });
            paymentElement.on('loaderror', initializationError);
            paymentElement.mount('#payment-element');

            $wire.on('invoice.payment.cancelled', () => {
                // destroy the payment element
                stopThemeSync();
                paymentElement.destroy();
            });
            } catch (error) { initializationError(); }
        };
        document.body.appendChild(script);
}
initializePaymentForm();
</script>
@endscript
