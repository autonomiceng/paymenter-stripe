<form id="payment-form" class="mt-2" x-data="aeStripeSetup({
    publishableKey: @js($stripePublishableKey), clientSecret: @js($intent->client_secret),
    intent: @js($intent->id), consentUrl: @js(route('extensions.gateways.ae-stripe.setup-consent')),
    returnUrl: @js(route('extensions.gateways.ae-stripe.setup-agreement')), csrf: @js(csrf_token()),
    name: {{ \Illuminate\Support\Js::from(auth()->user()->name) }},
    email: {{ \Illuminate\Support\Js::from(auth()->user()->email) }}
})">
    <div id="error-message" role="alert" class="text-red-600 dark:text-red-400"></div>
    <div id="payment-element" wire:ignore></div>
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
// Livewire runs this registration once. Alpine owns each teleported form's lifetime.
let stripeScript;
const timedOutScripts = new WeakSet();
function loadStripe() {
    if (window.Stripe) return Promise.resolve();
    if (!stripeScript) {
        stripeScript = new Promise((resolve, reject) => {
            let existingScript = document.querySelector('script[src^="https://js.stripe.com/v3"]');
            if (existingScript && timedOutScripts.has(existingScript)) existingScript = null;
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
                stripeScript = null;
                if (!existingScript) script.remove();
                reject(new Error('Could not load Stripe.js.'));
            };
            // An existing tag may have finished before these listeners were added.
            // Give fresh requests more time, but allow retry if neither event fires.
            const timeout = setTimeout(() => {
                if (existingScript && !window.Stripe) timedOutScripts.add(existingScript);
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
    return stripeScript;
}
Alpine.data('aeStripeSetup', (config) => {
    @include('gateways.ae-stripe::stripe-theme')
    let disposed = false;
    let cleanup = () => {};
    return {
        async init() {
            const form = this.$el;
            const consent = form.querySelector('#setup-consent');
            const submit = form.querySelector('#submit');
            const message = form.querySelector('#error-message');
            const container = form.querySelector('#payment-element');
            const controller = new AbortController();
            let paymentElement;
            let stopThemeSync = () => {};
            let ready = false;
            let loading = false;
            cleanup = () => {
                controller.abort();
                stopThemeSync();
                paymentElement?.destroy();
                paymentElement = null;
                stopThemeSync = () => {};
            };
            function setLoading(isLoading) {
                loading = isLoading;
                if (disposed) return;
                consent.disabled = isLoading || !ready;
                container.inert = isLoading;
                submit.disabled = isLoading || !ready || !consent.checked;
                submit.classList.toggle('hidden', isLoading);
            }
            function initializationError() {
                ready = false;
                setLoading(false);
                if (!disposed) message.textContent = 'Could not load the payment form. Reload to try again.';
            }
            // Block native submits even while Stripe.js is loading.
            form.addEventListener('submit', (event) => event.preventDefault(), {signal: controller.signal});
            try {
                await loadStripe();
                if (disposed || !form.isConnected) return;
                const stripe = Stripe(config.publishableKey);
                const elements = stripe.elements({clientSecret: config.clientSecret, appearance: stripeAppearance(form)});
                paymentElement = elements.create('payment', {
                    layout: {type: 'accordion', defaultCollapsed: false, radios: false, spacedAccordionItems: true},
                    defaultValues: {billingDetails: {name: config.name, email: config.email}},
                });
                stopThemeSync = syncStripeTheme(form, elements);
                consent.addEventListener('change', () => { if (!loading) setLoading(false); }, {signal: controller.signal});
                paymentElement.on('change', () => { if (!loading) message.replaceChildren(); });
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (disposed || loading || !ready) return;
                    message.replaceChildren();
                    setLoading(true);
                    try {
                        if (!consent.checked) throw new Error('Agree to future charges before saving your payment method.');
                        const result = await fetch(config.consentUrl, {
                            method: 'POST', signal: controller.signal,
                            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf},
                            body: JSON.stringify({intent: config.intent, consent: consent.checked}),
                        });
                        if (!result.ok) throw new Error('Could not record your agreement. Try again.');
                        if (disposed) return;
                        const {error} = await stripe.confirmSetup({elements, confirmParams: {return_url: config.returnUrl}});
                        if (error) throw error;
                    } catch (error) {
                        if (disposed) return;
                        const text = document.createElement('p');
                        text.className = 'mb-2';
                        text.textContent = error.message || 'Could not save payment method.';
                        message.replaceChildren(text);
                    } finally {
                        setLoading(false);
                    }
                }, {signal: controller.signal});
                paymentElement.on('ready', () => { ready = true; setLoading(false); });
                paymentElement.on('loaderror', initializationError);
                paymentElement.mount(container);
            } catch (error) {
                cleanup();
                initializationError();
            }
        },
        destroy() {
            disposed = true;
            cleanup();
        },
    };
});
</script>
@endscript
