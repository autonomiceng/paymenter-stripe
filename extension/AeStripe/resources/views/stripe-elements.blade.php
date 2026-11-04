// Both pages share display options; each retains its own confirmation and consent flow.
function createStripeElements(stripe, options, {linkDisplay, name, email, allowExpress = true, onLinkAvailability}) {
    const paymentOptions = {
        layout: {type: 'accordion', defaultCollapsed: false, radios: false, spacedAccordionItems: true},
        defaultValues: {billingDetails: {name}},
    };
    // Keep returning Link users in the button above, leaving the card fields visible.
    if (linkDisplay === 'above_card' && allowExpress) paymentOptions.wallets = {link: 'never'};
    if (linkDisplay === 'stripe_default') paymentOptions.defaultValues.billingDetails.email = email;
    const elements = stripe.elements(options);
    const paymentElement = elements.create('payment', paymentOptions);
    let expressCheckout = null;
    if (linkDisplay === 'above_card' && allowExpress) {
        try {
            expressCheckout = elements.create('expressCheckout', {
                paymentMethods: {link: 'auto', applePay: 'never', googlePay: 'never',
                    amazonPay: 'never', paypal: 'never', klarna: 'never'},
            });
        } catch (error) {
            // Let the caller initialize its form state before notifying it.
            queueMicrotask(() => onLinkAvailability(false));
        }
    }
    function updateAvailability(methods) {
        // ready uses booleans; availablepaymentmethodschange uses {available: boolean}.
        onLinkAvailability(methods?.link === true || methods?.link?.available === true);
    }
    expressCheckout?.on('ready', ({availablePaymentMethods}) => updateAvailability(availablePaymentMethods));
    expressCheckout?.on('availablepaymentmethodschange', ({paymentMethods}) => updateAvailability(paymentMethods));
    expressCheckout?.on('loaderror', () => onLinkAvailability(false));
    // Mount the card form first. A failed Link mount only hides its row.
    function mountExpress(target) {
        if (!expressCheckout) return;
        try {
            expressCheckout.mount(target);
        } catch (error) {
            queueMicrotask(() => onLinkAvailability(false));
        }
    }
    return {elements, paymentElement, expressCheckout, mountExpress};
}
