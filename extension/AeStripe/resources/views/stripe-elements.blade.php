// Both pages share display options; each retains its own confirmation and consent flow.
// A form with its own saving consent passes ownTerms, so Stripe's card terms do not repeat it.
function createStripeElements(stripe, options, {linkDisplay, name, email, businessName, allowExpress = true, ownTerms = false, onLinkAvailability}) {
    // Without a name, Stripe shows the Stripe account's name to the customer.
    const business = businessName ? {business: {name: businessName}} : {};
    // With card as the only tab, Stripe shows the card fields alone, without a tab or panel around them.
    const paymentOptions = {
        layout: 'tabs',
        defaultValues: {billingDetails: {name}},
        ...business,
    };
    if (ownTerms) paymentOptions.terms = {card: 'never'};
    // Keep returning Link users in the separate Link button, leaving the card fields visible.
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
                // The smallest height; a narrow container shows the Link mark alone, so it stays quieter than the form's button.
                buttonHeight: 40,
                ...business,
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
