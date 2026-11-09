<form id="payment-form" class="mt-2" x-data="aeStripeSetup({
    publishableKey: @js($stripePublishableKey), clientSecret: @js($intent->client_secret),
    linkDisplay: @js($linkDisplay), intent: @js($intent->id), consentUrl: @js(route('extensions.gateways.ae-stripe.setup-consent')),
    returnUrl: @js(route('extensions.gateways.ae-stripe.setup-agreement')), csrf: @js(csrf_token()),
    name: {{ \Illuminate\Support\Js::from(auth()->user()->name) }},
    email: {{ \Illuminate\Support\Js::from(auth()->user()->email) }},
    businessName: @js($businessName)
})">
    {{-- One line, shown before either choice, says what saving agrees to. Choosing Link or Save card is the agreement. --}}
    <p id="setup-terms">By saving with Link or a card, you agree to save it for future charges, including renewals.
        @if(config('settings.tos'))<a href="{{ config('settings.tos') }}" target="_blank" rel="noopener" class="underline">Terms of Service</a>@endif
        <span id="setup-consent-note" class="mt-1 block text-sm opacity-75">Auto Pay can charge when you are away. Saving does not turn on Auto Pay.</span>
    </p>
    {{-- Link uses what the customer saved there; the wrapper keeps it above the card fields if a theme reorders the form. --}}
    <div id="setup-link">
        <div id="express-checkout" hidden inert wire:ignore>
            <div class="mt-4 flex items-center justify-between gap-3">
                <span>Use your Link wallet</span>
                {{-- An inline width: a theme built before this release has no w-40 class. At 160px Stripe shows the Link mark alone. --}}
                <div id="express-checkout-element" class="shrink-0" style="width: 160px"></div>
            </div>
            <div class="mt-4 flex items-center gap-3 text-sm opacity-75" aria-hidden="true">
                <span style="flex: 1; border-top: 1px solid currentColor; opacity: 0.4"></span>or add a card<span style="flex: 1; border-top: 1px solid currentColor; opacity: 0.4"></span>
            </div>
        </div>
    </div>
    <div id="payment-element" class="mt-4" inert wire:ignore></div>
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <button id="submit" disabled
        class="mt-4 bg-secondary-500 text-white hover:bg-secondary py-2 px-4 rounded-md w-full bg-gradient-to-tr from-secondary via-50% via-20% via-secondary to-[#5573FD80] duration-300">
        Save card
    </button>
</form>
@script
<script>
// Livewire runs this registration once. Alpine owns each teleported form's lifetime.
@include('gateways.ae-stripe::stripe-form')
Alpine.data('aeStripeSetup', (config) => {
    @include('gateways.ae-stripe::stripe-theme')
    @include('gateways.ae-stripe::stripe-elements')
    let disposed = false;
    let cleanup = () => {};
    return {
        init() {
            const form = this.$el;
            const expressContainer = form.querySelector('#express-checkout');
            const controller = new AbortController();
            let release = () => {};
            cleanup = () => {
                controller.abort();
                release();
            };
            // Both choices open together once Stripe is ready, and lock together while one saves.
            const controls = stripeFormControls(form, {inertUntilReady: true, isDisposed: () => disposed, onRetry: start});
            const {showMessage, clearMessage, setLoading, initializationError} = controls;
            // Block native submits even while Stripe.js is loading.
            form.addEventListener('submit', (event) => event.preventDefault(), {signal: controller.signal});
            // Try again releases the failed attempt and starts a new one.
            async function start() {
                release();
                const attempt = new AbortController();
                let paymentElement;
                let expressCheckout;
                let stopThemeSync = () => {};
                release = () => {
                    attempt.abort();
                    stopThemeSync();
                    paymentElement?.destroy();
                    expressCheckout?.destroy();
                    expressCheckout = null;
                    paymentElement = null;
                    stopThemeSync = () => {};
                };
                try {
                    await loadStripe();
                    if (disposed || attempt.signal.aborted || !form.isConnected) return;
                    const stripe = Stripe(config.publishableKey);
                    const created = createStripeElements(stripe, {clientSecret: config.clientSecret, appearance: stripeAppearance(form)}, {
                        linkDisplay: config.linkDisplay, name: config.name, email: config.email, businessName: config.businessName, ownTerms: true,
                        onLinkAvailability: available => { if (!disposed) expressContainer.hidden = !available; },
                    });
                    const {elements} = created;
                    paymentElement = created.paymentElement;
                    expressCheckout = created.expressCheckout;
                    stopThemeSync = syncStripeTheme(form, elements);
                    paymentElement.on('change', () => { if (!controls.loading) clearMessage(); });
                    form.addEventListener('submit', (event) => {
                        event.preventDefault();
                        confirmSetup();
                    }, {signal: attempt.signal});
                    expressCheckout?.on('confirm', event => confirmSetup(event));
                    async function confirmSetup(expressEvent = null) {
                        if (disposed || controls.loading || !controls.ready) {
                            expressEvent?.paymentFailed({reason: 'fail'});
                            return;
                        }
                        function failSetup(value) {
                            showMessage(value);
                            expressEvent?.paymentFailed({reason: 'fail'});
                        }
                        clearMessage();
                        setLoading(true);
                        try {
                            const result = await fetch(config.consentUrl, {
                                method: 'POST', signal: attempt.signal,
                                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf},
                                // The customer chose Link or Save card under the agreement line, so the choice is the consent.
                                body: JSON.stringify({intent: config.intent, consent: true, version: 'off_session_v2'}),
                            });
                            if (!result.ok) {
                                failSetup('Could not record your agreement. Try again.');
                                return;
                            }
                            if (disposed) return;
                            const {error} = await stripe.confirmSetup({elements, confirmParams: {return_url: config.returnUrl}});
                            if (error) throw error;
                        } catch (error) {
                            if (disposed) return;
                            failSetup(['card_error', 'validation_error'].includes(error?.type) && error.message
                                ? error.message : 'Could not save your payment method. Try again.');
                        } finally {
                            setLoading(false);
                        }
                    }
                    paymentElement.on('ready', () => { controls.ready = true; setLoading(false); });
                    paymentElement.on('loaderror', initializationError);
                    paymentElement.mount(form.querySelector('#payment-element'));
                    created.mountExpress(form.querySelector('#express-checkout-element'));
                } catch (error) {
                    release();
                    initializationError();
                }
            }
            return start();
        },
        destroy() {
            disposed = true;
            cleanup();
        },
    };
});
</script>
@endscript
