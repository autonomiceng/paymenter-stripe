<form id="payment-form" class="mt-2">
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <div id="payment-element">

    </div>
    <label class="mt-4 block">
        <input type="checkbox" id="save-card" @checked($intent->status === 'requires_action' && ($intent->metadata->stripe_saved_consent ?? null) === '1') disabled />
        Save this card for future payments
    </label>
    <p class="text-sm">Saving a card does not enable Auto Pay.</p>
    <button id="submit" disabled
        class="mt-4 bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full bg-gradient-to-tr from-secondary via-50% via-20% via-secondary to-[#5573FD80] duration-300">
        Pay
    </button>
</form>
@script
<script>
    @include('gateways.stripe-saved::stripe-theme')
    let ready = false;
    const paymentForm = document.getElementById('payment-form');
    paymentForm.addEventListener('submit', (event) => event.preventDefault());
    function initializationError() {
        ready = false;
        document.getElementById('submit').disabled = true;
        document.getElementById('error-message').textContent = 'Could not load the payment form. Reload to try again.';
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
                clientSecret: @js($intent->client_secret),
                customerSessionClientSecret: @js($customerSessionSecret),
                appearance: stripeAppearance(form),
            };

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

            // Set up Stripe.js and Elements to use in checkout form, passing the client secret obtained in a previous step
            const elements = stripe.elements(options);
            const paymentElement = elements.create('payment', paymentElementOptions);
            const resuming = @js($intent->status === 'requires_action');
            const savedConsent = @js(($intent->metadata->stripe_saved_consent ?? null) === '1');
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
                    // The authenticated, CSRF-protected endpoint binds consent to this intent.
                    const result = await fetch(@js(route('extensions.gateways.stripe-saved.consent')), {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': @js(csrf_token())},
                        body: JSON.stringify({intent: @js($intent->id),
                            save: resuming ? savedConsent : canSave && saveCard.checked}),
                    });
                    if (!result.ok) throw new Error('Could not update your payment preferences. Try again.');
                    // https://docs.stripe.com/js/elements_object/fetch_updates
                    const update = await elements.fetchUpdates();
                    if (update.error) throw update.error;
                    const {error} = await stripe.confirmPayment({elements, confirmParams: {
                        return_url: @js(route('invoices.show', $invoice) . '?checkPayment=true'),
                    }});
                    if (error) showMessage(error.message || 'Could not confirm payment.');
                } catch (error) {
                    showMessage(error.message || 'Could not confirm payment.');
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
</script>
@endscript
