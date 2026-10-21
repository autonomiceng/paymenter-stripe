<form id="payment-form" class="mt-2">
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <div id="payment-element">

    </div>
    <button id="submit"
        class="mt-4 bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full bg-gradient-to-tr from-secondary via-50% via-20% via-secondary to-[#5573FD80] duration-300">
        Authorize
    </button>
</form>
@script
<script>
    @include('gateways.stripe-saved::stripe-theme')
    const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        document.body.appendChild(script);
        script.onload = () => {

            var stripe = Stripe(
                @js($stripePublishableKey)
            );

            const type = @js($type);

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
                        name: @js(auth()->user()->name),
                        email: @js(auth()->user()->email),
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
            paymentElement.mount('#payment-element');
            const stopThemeSync = syncStripeTheme(form, elements);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                message.replaceChildren();
                setLoading(true);
                
                try {
                    const {error} = await stripe.confirmSetup({elements, confirmParams: {
                        return_url: @js(route('extensions.gateways.stripe-saved.setup-agreement')),
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
                if (isLoading) {
                    // Disable the button and show a spinner
                    document.querySelector("#submit").disabled = true;
                    document.querySelector("#submit").classList.add("hidden");
                } else {
                    document.querySelector("#submit").disabled = false;
                    document.querySelector("#submit").classList.remove("hidden");
                }
            }

            $wire.on('invoice.payment.cancelled', () => {
                // destroy the payment element
                stopThemeSync();
                paymentElement.destroy();
            });
        };
</script>
@endscript