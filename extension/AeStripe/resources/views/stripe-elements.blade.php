// Both pages share display options; each retains its own confirmation and consent flow.
function createStripeElements(stripe, options, {linkDisplay, name, email, allowExpress = true, onLinkAvailability}) {
    const paymentOptions = {
        layout: {type: 'accordion', defaultCollapsed: false, radios: false, spacedAccordionItems: true},
        defaultValues: {billingDetails: {name}},
    };
    if (linkDisplay === 'stripe_default') paymentOptions.defaultValues.billingDetails.email = email;
    const elements = stripe.elements(options);
    const paymentElement = elements.create('payment', paymentOptions);
    const expressCheckout = linkDisplay === 'above_card' && allowExpress ? elements.create('expressCheckout', {
        paymentMethods: {link: 'auto', applePay: 'never', googlePay: 'never',
            amazonPay: 'never', paypal: 'never', klarna: 'never'},
    }) : null;
    function updateAvailability(methods) {
        // ready uses booleans; availablepaymentmethodschange uses {available: boolean}.
        onLinkAvailability(methods?.link === true || methods?.link?.available === true);
    }
    expressCheckout?.on('ready', ({availablePaymentMethods}) => updateAvailability(availablePaymentMethods));
    expressCheckout?.on('availablepaymentmethodschange', ({paymentMethods}) => updateAvailability(paymentMethods));
    return {elements, paymentElement, expressCheckout};
}
