<form id="payment-form" class="mt-2">
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <div id="payment-element">

    </div>
    <label class="mt-4 block">
        <input type="checkbox" id="setup-consent" required disabled />
        I agree to save this payment method and allow charges to it for my future invoices, including renewals, when I am not present.
    </label>
    <p class="text-sm">Saving does not turn on Auto Pay; you can choose it for a service later.</p>
    <button id="submit" disabled
        class="mt-4 bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full bg-gradient-to-tr from-secondary via-50% via-20% via-secondary to-[#5573FD80] duration-300">
        Authorize
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

            const consent = document.getElementById('setup-consent');
            consent.addEventListener('change', () => setLoading(false));

            const form = document.getElementById('payment-form');
            const options = {
                clientSecret: @js($intent->client_secret),
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
            const message = document.getElementById('error-message');
            let loading = false;
            // The alert region stays mounted so screen readers announce new text.
            paymentElement.on('change', () => { if (!loading) message.replaceChildren(); });
            const stopThemeSync = syncStripeTheme(form, elements);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                message.replaceChildren();
                setLoading(true);
                
                try {
                    if (!consent.checked) throw new Error('Agree to future charges before saving your payment method.');
                    const result = await fetch(@js(route('extensions.gateways.ae-stripe.setup-consent')), {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': @js(csrf_token())},
                        body: JSON.stringify({intent: @js($intent->id), consent: consent.checked}),
                    });
                    if (!result.ok) throw new Error('Could not record your agreement. Try again.');
                    const {error} = await stripe.confirmSetup({elements, confirmParams: {
                        return_url: @js(route('extensions.gateways.ae-stripe.setup-agreement')),
                    }});
                    if (error) throw error;
                } catch (error) {
                    const text = document.createElement('p');
                    text.className = 'mb-2';
                    text.textContent = error.message || 'Could not save payment method.';
                    message.replaceChildren(text);
                } finally {
                    setLoading(false);
                }

            });

            // Show a spinner on payment submission
            function setLoading(isLoading) {
                loading = isLoading;
                consent.disabled = isLoading || !ready;
                document.getElementById("payment-element").inert = isLoading;
                if (isLoading) {
                    // Disable the button and show a spinner
                    document.querySelector("#submit").disabled = true;
                    document.querySelector("#submit").classList.add("hidden");
                } else {
                    document.querySelector("#submit").disabled = !ready || !consent.checked;
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
