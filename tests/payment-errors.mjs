// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Exercise the production submit handlers with offline browser/Stripe boundaries.
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const views = new URL('../extension/AeStripe/resources/views/', import.meta.url);
const theme = readFileSync(new URL('stripe-theme.blade.php', views), 'utf8');
function render(name, linkDisplay = 'above_card', businessName = 'Fixture Hosting', resuming = false, savedConsent = false) {
    let source = readFileSync(new URL(`${name}.blade.php`, views), 'utf8')
        .replace("@include('gateways.ae-stripe::stripe-theme')", theme)
        .replace("@include('gateways.ae-stripe::stripe-elements')", () => readFileSync(new URL('stripe-elements.blade.php', views), 'utf8'))
        .replace(/@if\(config\('settings.tos'\)\)[\s\S]*?@endif/g, '')
        .replace(/@js\(route\('([^']+)'\)\)/g, (_, route) => JSON.stringify('/' + route))
        .replace("@js(route('invoices.show', $invoice) . '?checkPayment=true')", "'/return'")
        .replace('@js(csrf_token())', "'fixture-csrf'")
        .replaceAll('@js($stripePublishableKey)', "'fixture-key'")
        .replaceAll('@js($intent->client_secret)', "'fixture-secret'")
        .replaceAll('@js($intent->id)', "'fixture-intent'")
        .replace('@js($customerSessionSecret)', 'null')
        .replace('@js($linkDisplay)', JSON.stringify(linkDisplay))
        .replace('@js($businessName)', JSON.stringify(businessName))
        .replace("@js(in_array($intent->status, ['requires_action', 'requires_confirmation'], true))", JSON.stringify(resuming))
        .replaceAll('@js($intent->amount)', '2000').replace("@js(strtolower($intent->currency))", "'usd'")
        .replace("@js(($intent->metadata->ae_stripe_consent ?? null) === '1')", JSON.stringify(savedConsent))
        .replace(/@checked\([^\n]+? disabled/, resuming && savedConsent ? 'checked disabled' : 'disabled')
        .replace(/\{\{[^\n]+?\}\}/g, "'Fixture'")
        .replaceAll('@script', '').replaceAll('@endscript', '');
    assert(!/@js|@checked|\{\{|@include/.test(source), 'Blade expressions are rendered');
    return source;
}
const browser = await chromium.launch({ headless: true });
let activePage;
try {
    const forms = process.argv.slice(2).length ? process.argv.slice(2) : ['pay', 'billing-agreement'];
    const cases = forms.flatMap(name => [
        [name, 'above_card', 'create'],
        [name, 'above_card', 'mount'],
        [name, 'above_card', 'unavailable'],
        ...['above_card', 'inside_card', 'stripe_default'].map(mode => [name, mode, false]),
        [name, 'above_card', false, null],
    ]);
    for (const [name, linkDisplay, expressThrows, businessName = 'Fixture Hosting'] of cases) {
        const page = activePage = await browser.newPage({viewport: {width: 390, height: 844}});
        await page.route('**/*', route => route.fulfill({ contentType: 'text/javascript', body: '' }));
        page.setDefaultTimeout(5000);
        await page.setContent('<html><body></body></html>');
        await page.evaluate(expressThrows => {
            window.expressThrows = expressThrows;
            window.failure = 'network';
            window.confirms = 0;
            window.clears = 0;
            window.elementCalls = [];
            window.elementHandlers = {};
            window.fetch = async () => {
                if (failure === 'network') throw new TypeError('Failed to fetch');
                if (failure === 'server-decline') return { ok: false, async json() { return {message: 'Server decline wording.', intent: 'fixture-intent', declined: true}; } };
                return { ok: failure !== 'consent', async json() { return {message: 'Could not record payment preferences. Refresh the invoice.', status: 'requires_action', client_secret: 'fixture-secret'}; } };
            };
            window.$wire = { on() {} };
            window.Alpine = { data: (_, factory) => { window.setupFactory = factory; } };
            window.Stripe = () => ({
                elements: (options) => { window.elementOptions = options; return window.elementsInstance = { update() {}, async submit() {
                    if (failure === 'updates') return { error: { type: 'api_connection_error' } };
                    return {};
                }, async fetchUpdates() {
                    if (failure === 'updates') return { error: { type: 'api_connection_error', message: 'Raw update detail' } };
                    return {};
                }, create(type, options) {
                    elementCalls.push({type, options});
                    if (type === 'expressCheckout' && expressThrows === 'create') throw new Error('Express Checkout is unsupported');
                    if (type === 'payment') window.paymentElementOptions = options;
                    const handlers = elementHandlers[type] = {};
                    return { on: (name, fn) => { handlers[name] = fn; }, mount(selector) {
                        if (type === 'expressCheckout' && expressThrows === 'mount') throw new Error('Express Checkout cannot mount');
                        (typeof selector === 'string' ? document.querySelector(selector) : selector).textContent =
                            type === 'payment' ? 'Card form' : 'Pay with Link';
                        // No available methods also models Link being disabled on the account.
                        handlers.ready?.({availablePaymentMethods: expressThrows === 'unavailable' ? null : {link: true}});
                    }, clear() {
                        if (type !== 'payment') return;
                        clears++;
                        // Stripe reports the empty form after clear() returns and submission unlocks.
                        window.clearChange = new Promise(resolve => requestAnimationFrame(() => {
                            handlers.change?.({empty: true, complete: false, value: {type: 'card'}});
                            resolve();
                        }));
                    }, destroy() {} };
                } }; },
                async createConfirmationToken({elements}) {
                    if (elements !== elementsInstance) throw new Error('Token must use the mounted Elements instance');
                    if (failure === 'confirmation') throw new TypeError('Failed to tokenize');
                    if (failure === 'decline') return {error: {type: 'card_error', message: 'Your card was declined.'}};
                    return {confirmationToken: {id: 'ctoken_fixture'}};
                },
                async handleNextAction(options) { if (window.onAction) await window.onAction(options); return confirm(); },
                async confirmSetup({elements, confirmParams}) {
                    if (elements !== elementsInstance) throw new Error('Setup must use the mounted Elements instance');
                    window.setupConfirmParams = confirmParams;
                    return confirm();
                },
            });
            function confirm() {
                confirms++;
                if (failure === 'confirmation') throw new TypeError('NetworkError when attempting to fetch resource.');
                if (failure === 'action-success') return {};
                return { error: { type: 'card_error', message: 'Your card was declined.' } };
            }
        }, expressThrows);
        await page.setContent(render(name, linkDisplay, businessName));
        if (name === 'pay') {
            await page.waitForFunction(() => !!document.querySelector('script[src]'));
            await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
            const options = await page.evaluate(() => elementOptions);
            assert.equal(options.mode, 'payment');
            assert.equal(options.amount, 2000);
            assert.equal(options.currency, 'usd');
            assert.equal(options.clientSecret, undefined);
            assert.deepEqual(options.paymentMethodTypes, ['card', 'link'], 'The pay form offers only card and Link');
            assert.equal(options.excludedPaymentMethodTypes, undefined);
            const calls = await page.evaluate(() => elementCalls);
            const payment = calls.find(call => call.type === 'payment').options;
            assert.equal(payment.defaultValues.billingDetails.email, linkDisplay === 'stripe_default' ? 'Fixture' : undefined,
                `${linkDisplay}: only Stripe default prefills email`);
            assert.equal(payment.layout, 'tabs');
            assert.equal(payment.terms, undefined, 'The pay form keeps Stripe card terms');
            assert.deepEqual(calls.filter(call => call.type === 'expressCheckout').map(call => call.options),
                linkDisplay === 'above_card' ? [{paymentMethods: {link: 'auto', applePay: 'never', googlePay: 'never',
                    amazonPay: 'never', paypal: 'never', klarna: 'never'}, buttonHeight: 40, ...(businessName ? {business: {name: businessName}} : {})}] : [],
                `${linkDisplay}: only the above-card choice mounts Link-only Express Checkout`);
        } else {
            await page.evaluate(async ({linkDisplay, businessName}) => {
                window.component = setupFactory({ publishableKey: 'fixture-key', clientSecret: 'fixture-secret', intent: 'fixture-intent', consentUrl: '/consent', returnUrl: '/return', csrf: 'fixture-csrf', name: 'Fixture', email: 'Fixture', linkDisplay, businessName });
                component.$el = document.querySelector('form');
                await component.init();
            }, {linkDisplay, businessName});
            assert.equal(await page.locator('#submit').isEnabled(), true, 'Save stays live so it can say why it waits');
            await page.locator('#submit').click();
            assert.equal(await page.locator('[role=alert]').textContent(), 'Tick the box to save this card.');
            assert.equal(await page.evaluate(() => document.activeElement?.id), 'setup-consent', 'The reason moves focus to the box');
            assert.equal(await page.evaluate(() => confirms), 0, 'Save without the tick confirms nothing');
            assert.equal(await page.locator('#payment-element').evaluate(element => element.inert), false, 'Card fields are live before the tick');
            assert.equal(await page.locator('#express-checkout').evaluate(element => element.inert), true, 'Link waits for the tick');
            const options = await page.evaluate(() => elementOptions);
            assert.equal(options.clientSecret, 'fixture-secret', 'Setup keeps the existing SetupIntent flow');
            assert.equal(options.mode, undefined);
            const calls = await page.evaluate(() => elementCalls);
            assert.equal(calls.find(call => call.type === 'payment').options.defaultValues.billingDetails.email,
                linkDisplay === 'stripe_default' ? 'Fixture' : undefined, `${linkDisplay}: setup only prefills email for Stripe default`);
            assert.equal(calls.find(call => call.type === 'payment').options.layout, 'tabs');
            assert.deepEqual(calls.find(call => call.type === 'payment').options.terms, {card: 'never'}, 'Setup shows its own consent only');
            assert.deepEqual(calls.filter(call => call.type === 'expressCheckout').map(call => call.options),
                linkDisplay === 'above_card' ? [{paymentMethods: {link: 'auto', applePay: 'never', googlePay: 'never',
                    amazonPay: 'never', paypal: 'never', klarna: 'never'}, buttonHeight: 40, ...(businessName ? {business: {name: businessName}} : {})}] : []);
            if (expressThrows) assert.equal(await page.locator('#setup-consent').isEnabled(), true, 'Card setup stays ready when Express fails');
            await page.locator('#setup-consent').check();
        }
        if (expressThrows) {
            assert.equal(await page.locator('#payment-element').textContent(), 'Card form', `${name}: card form mounts when Express fails`);
            assert.equal(await page.locator('#express-checkout').isVisible(), false, `${name}: failed Express row stays hidden`);
            assert.equal(await page.locator('#submit').isEnabled(), true, `${name}: card submission remains available`);
        }
        assert.deepEqual(await page.evaluate(() => paymentElementOptions.business),
            businessName ? {name: businessName} : undefined, `${name}: the Payment Element names the site, or leaves it to Stripe`);
        assert.deepEqual(await page.evaluate(() => paymentElementOptions.wallets),
            linkDisplay === 'above_card' ? {link: 'never'} : undefined,
            `${name}: ${linkDisplay} keeps Link only in Express Checkout while other modes keep Stripe's defaults`);
        if (linkDisplay === 'above_card') {
            assert.equal(await page.locator('#payment-element').textContent(), 'Card form', `${name}: above-card always mounts the card form`);
        }
        if (name === 'pay') {
            // A tall Stripe form puts the alert above the viewport when Pay is clicked on mobile.
            await page.locator('#payment-element').evaluate(element => { element.style.minHeight = '900px'; });
        }
        const fallback = name === 'pay' ? 'Could not check the payment. Refresh the invoice for its status.' : 'Could not save your payment method. Try again.';
        for (const failure of ['network', 'confirmation', ...(name === 'pay' ? ['updates', 'server-decline'] : []), 'consent', 'decline']) {
            await page.evaluate(value => { window.failure = value; }, failure);
            const before = await page.evaluate(() => confirms);
            const clearsBefore = await page.evaluate(() => clears);
            await page.locator('#submit').click();
            const expected = failure === 'decline' ? 'Your card was declined.' : failure === 'server-decline' ? 'Server decline wording.' : failure === 'consent'
                ? (name === 'pay' ? 'Could not record payment preferences. Refresh the invoice.' : 'Could not record your agreement. Try again.') : fallback;
            await page.waitForFunction(() => document.querySelector('[role=alert]').textContent.length > 0);
            await page.evaluate(() => window.clearChange);
            assert.equal(await page.locator('[role=alert]').textContent(), expected, `${name}: ${failure}`);
            assert.equal(await page.locator('#submit').isEnabled(), true, `${name}: retry stays available after ${failure}`);
            // Only a decline of the entered details clears them, so the customer types a different card.
            assert.equal(await page.evaluate(() => clears) - clearsBefore, failure === 'server-decline' ? 1 : 0, `${name}: ${failure} clears the card form only after a decline`);
            if (failure === 'server-decline') {
                const alert = await page.locator('[role=alert]').boundingBox();
                assert(alert && alert.y >= 0 && alert.y + alert.height <= 844,
                    'The decline stays in view at 390px after clearing the card');
                await page.evaluate(() => elementHandlers.payment.change({empty: true, complete: false, value: {type: 'card'}}));
                assert.equal(await page.locator('[role=alert]').textContent(), expected,
                    'Further empty form updates keep the decline visible');
                await page.evaluate(() => elementHandlers.payment.change({empty: false, complete: false, value: {type: 'card'}}));
                assert.equal(await page.locator('[role=alert]').textContent(), '',
                    'Entering another card clears the decline');
            }
            if (['network', 'consent', 'updates'].includes(failure)) assert.equal(await page.evaluate(() => confirms), before, 'Failed preparation cannot confirm');
        }
        if (name === 'billing-agreement' && linkDisplay === 'above_card' && !expressThrows) {
            assert.equal(await page.locator('#express-checkout').isVisible(), true);
            assert.equal(await page.evaluate(() => document.querySelector('#submit').compareDocumentPosition(
                document.querySelector('#express-checkout')) & Node.DOCUMENT_POSITION_FOLLOWING), 4, 'Link sits below Save');
            await page.evaluate(() => {
                window.setupRequests = [];
                window.linkFailures = [];
                window.failure = 'decline';
                window.fetch = async (url, options) => {
                    setupRequests.push({url, method: options.method, csrf: options.headers['X-CSRF-TOKEN'], body: JSON.parse(options.body)});
                    return {ok: true};
                };
            });
            await page.locator('#setup-consent').uncheck();
            const before = await page.evaluate(() => confirms);
            const confirmLink = () => page.evaluate(() => elementHandlers.expressCheckout.confirm({paymentFailed: value => linkFailures.push(value)}));
            await confirmLink();
            assert.equal(await page.locator('[role=alert]').textContent(), 'Tick the box to save with Link.');
            assert.equal(await page.evaluate(() => confirms), before);
            assert.deepEqual(await page.evaluate(() => setupRequests), [], 'Unchecked consent never reaches the server');
            await page.locator('#setup-consent').check();
            await confirmLink();
            assert.deepEqual(await page.evaluate(() => setupRequests), [{url: '/consent', method: 'POST', csrf: 'fixture-csrf',
                body: {intent: 'fixture-intent', consent: true, version: 'off_session_v2'}}]);
            assert.deepEqual(await page.evaluate(() => setupConfirmParams), {return_url: '/return'});
            assert.equal(await page.locator('[role=alert]').textContent(), 'Your card was declined.');
            assert.deepEqual(await page.evaluate(() => linkFailures), [{reason: 'fail'}, {reason: 'fail'}]);
            for (const failure of ['network', 'consent', 'confirmation']) {
                await page.evaluate(value => {
                    window.failure = value;
                    window.fetch = async () => {
                        if (failure === 'network') throw new TypeError('Failed to fetch');
                        return {ok: failure !== 'consent'};
                    };
                }, failure);
                const before = await page.evaluate(() => confirms);
                await confirmLink();
                assert.equal(await page.locator('[role=alert]').textContent(), failure === 'consent'
                    ? 'Could not record your agreement. Try again.' : fallback);
                if (failure !== 'confirmation') assert.equal(await page.evaluate(() => confirms), before);
                assert.equal(await page.locator('#submit').isEnabled(), true, `Setup Link retries after ${failure}`);
            }
            await page.evaluate(() => {
                window.failure = 'action-success';
                window.fetch = () => new Promise(resolve => { window.releaseConsent = () => resolve({ok: true}); });
                window.setupPending = elementHandlers.expressCheckout.confirm({paymentFailed() { throw new Error('Successful setup must not fail'); }});
            });
            assert.equal(await page.locator('#setup-consent').isEnabled(), false, 'Consent stays frozen during Link setup');
            assert.equal(await page.locator('#express-checkout').evaluate(element => element.inert), true);
            const beforeDuplicate = await page.evaluate(() => confirms);
            await confirmLink();
            await page.evaluate(async () => { releaseConsent(); await setupPending; });
            assert.equal(await page.evaluate(() => confirms), beforeDuplicate + 1, 'Only one setup confirms during an in-flight consent request');
            await page.evaluate(() => elementHandlers.expressCheckout.loaderror?.({error: {message: 'Link failed to load'}}));
            assert.equal(await page.locator('#express-checkout').isVisible(), false, 'A Link load error hides the setup Express row');
            await page.evaluate(() => { window.fetch = async () => ({ok: true}); });
            const beforeCard = await page.evaluate(() => confirms);
            await page.locator('#submit').click();
            await page.waitForFunction(before => confirms === before + 1, beforeCard);
            assert.equal(await page.locator('[role=alert]').textContent(), '', 'Card setup still confirms after a Link load error');
            await page.evaluate(() => elementHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: {link: {available: false}}}));
            assert.equal(await page.locator('#express-checkout').isVisible(), false);
            assert.equal(await page.locator('#submit').isEnabled(), true, 'Setup card form works without Link');
        }
        if (name === 'pay') {
            if (linkDisplay === 'above_card' && !expressThrows) {
                assert.equal(await page.evaluate(() => typeof elementHandlers.expressCheckout.confirm), 'function',
                    'Link Express Checkout has a confirm handler');
                assert.equal(await page.locator('#express-checkout').isVisible(), true, 'Available Link appears below Pay');
                assert.equal(await page.evaluate(() => document.querySelector('#submit').compareDocumentPosition(
                    document.querySelector('#express-checkout')) & Node.DOCUMENT_POSITION_FOLLOWING), 4);
                await page.evaluate(() => {
                    window.failure = 'action-success';
                    window.linkRequests = [];
                    window.linkFailures = [];
                    window.fetch = async (url, options) => {
                        linkRequests.push({url, method: options.method, csrf: options.headers['X-CSRF-TOKEN'], body: JSON.parse(options.body)});
                        return {ok: false, async json() { return {message: 'The invoice balance changed. Refresh before paying.'}; }};
                    };
                    elementHandlers.payment.change({value: {type: 'sepa_debit'}});
                });
                await page.evaluate(() => elementHandlers.expressCheckout.loaderror?.({error: {message: 'Link failed to load'}}));
                assert.equal(await page.locator('#express-checkout').isVisible(), false, 'A Link load error hides the pay Express row');
                assert.equal(await page.locator('#save-card').isEnabled(), false, 'A failed Link cannot enable saving a bank method');
                await page.evaluate(() => elementHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: {link: {available: true}}}));
                await page.evaluate(() => elementHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: {link: {available: false}}}));
                assert.equal(await page.locator('#express-checkout').isVisible(), false, 'Unavailable Link hides the Express row');
                assert.equal(await page.locator('#save-card').isEnabled(), false, 'Unavailable Link cannot enable saving a bank method');
                await page.evaluate(() => elementHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: {link: {available: true}}}));
                for (const save of [false, true]) {
                    await page.locator('#save-card').setChecked(save);
                    await page.evaluate(async () => {
                        await elementHandlers.expressCheckout.confirm({expressPaymentType: 'link', paymentFailed: value => linkFailures.push(value)});
                    });
                    assert.equal(await page.locator('[role=alert]').textContent(), 'The invoice balance changed. Refresh before paying.');
                    assert.equal(await page.locator('#submit').isEnabled(), true, 'A refused Link payment permits retry');
                }
                await page.locator('#submit').click();
                await page.waitForFunction(() => linkRequests.length === 3);
                assert.deepEqual(await page.evaluate(() => linkRequests), [false, true, false].map(save => ({
                    url: '/extensions.gateways.ae-stripe.confirm', method: 'POST', csrf: 'fixture-csrf',
                    body: {intent: 'fixture-intent', confirmation_token: 'ctoken_fixture', amount: 2000, save},
                })), 'Link uses the same deferred server confirm route and amount; a bank form cannot inherit Link saving');
                assert.deepEqual(await page.evaluate(() => linkFailures), [{reason: 'fail'}, {reason: 'fail'}],
                    'Server refusal also dismisses the Express payment UI');
                await page.evaluate(() => elementHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: null}));
                assert.equal(await page.locator('#express-checkout').isVisible(), false, 'Unsupported Link leaves the card form usable');
                assert.equal(await page.locator('#save-card').isEnabled(), false, 'A bank method alone cannot opt into card saving');
            }
            const requests = [], actions = [];
            await page.exposeFunction('onRequest', body => requests.push(body));
            await page.exposeFunction('onAction', options => actions.push(options));
            await page.route('https://example.com/return', route => route.fulfill({contentType: 'text/html', body: 'Returned'}));
            await page.evaluate(() => {
                const base = document.createElement('base'); base.href = 'https://example.com/'; document.head.append(base);
                window.failure = 'action-success';
                let calls = 0;
                window.fetch = async (_, options) => {
                    await onRequest(JSON.parse(options.body));
                    return {ok: true, async json() { return ++calls === 1
                        ? {status: 'requires_action', client_secret: 'fixture-secret'} : {status: 'succeeded'}; }};
                };
            });
            if (linkDisplay === 'above_card' && !expressThrows) {
                await page.evaluate(() => {
                    elementHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: {link: {available: true}}});
                    elementHandlers.expressCheckout.confirm({expressPaymentType: 'link', paymentFailed() {
                        throw new Error('Successful Link must not report failure');
                    }});
                });
            } else {
                await page.locator('#submit').click();
            }
            await page.waitForURL('https://example.com/return');
            assert.deepEqual(actions, [{clientSecret: 'fixture-secret'}]);
            assert.deepEqual(requests, [
                {intent: 'fixture-intent', confirmation_token: 'ctoken_fixture', amount: 2000, save: false},
                {intent: 'fixture-intent', confirmation_token: null, amount: 2000, save: false},
            ], `${linkDisplay}: authentication reads the same payment without replaying its token before redirecting`);
        }
        if (name === 'billing-agreement' && expressThrows) {
            await page.evaluate(() => { window.failure = 'action-success'; });
            const before = await page.evaluate(() => confirms);
            await page.locator('#submit').click();
            await page.waitForFunction(before => confirms === before + 1, before);
            assert.equal(await page.locator('[role=alert]').textContent(), '', 'Card setup confirms after Express creation fails');
            assert.deepEqual(await page.evaluate(() => setupConfirmParams), {return_url: '/return'});
        }
        await page.close();
    }
    // A resumed manual intent has no new token. After a proven decline the customer must
    // refresh into a new checkout before a different card can be submitted.
    for (const decline of ['response', 'status']) {
        const page = activePage = await browser.newPage();
        page.setDefaultTimeout(5000);
        await page.route('**/*', route => route.fulfill({contentType: 'text/javascript', body: ''}));
        await page.route('https://example.com/resume', route => route.fulfill({contentType: 'text/html', body: '<html><body></body></html>'}));
        await page.goto('https://example.com/resume');
        await page.evaluate(decline => {
            window.requests = []; window.tokens = 0; window.handlers = {}; window.actions = 0;
            window.$wire = {on() {}};
            window.fetch = async (_, options) => {
                requests.push(JSON.parse(options.body));
                if (requests.length === 1) return {ok: true, async json() {
                    return {intent: 'fixture-intent', status: 'requires_action', client_secret: 'fixture-secret'};
                }};
                return {ok: decline !== 'response', async json() { return decline === 'response'
                    ? {message: 'Your bank declined this card.', declined: true}
                    : {status: 'requires_payment_method'}; }};
            };
            window.Stripe = () => ({
                elements: () => ({update() {}, async submit() {return {};}, create(type) {
                    if (type !== 'payment') throw new Error('A resumed payment cannot offer Express Checkout');
                    return {on(name, fn) {handlers[name] = fn;}, mount() {handlers.ready();},
                        clear() {handlers.change?.({empty: true});}, destroy() {}};
                }}),
                async handleNextAction() {actions++; return {};},
                async createConfirmationToken() {tokens++; return {confirmationToken: {id: 'ctoken_new'}};},
            });
        }, decline);
        await page.setContent(render('pay', 'above_card', 'Fixture Hosting', true, true));
        await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
        assert.equal(await page.locator('#save-card').isChecked(), true, 'Resume keeps the original save consent');
        await page.locator('#submit').click();
        await page.waitForFunction(() => requests.length === 2 && !document.querySelector('#submit').classList.contains('hidden'));
        assert.equal(await page.locator('#submit').isDisabled(), true,
            'A declined resumed payment cannot submit another card in tokenless mode');
        assert.match(await page.locator('[role=alert]').textContent(), /[Rr]efresh/);
        assert.equal(await page.locator('#refresh-payment').isVisible(), true, 'A visible refresh action prepares a new card checkout');
        assert.equal(await page.locator('#payment-element').evaluate(element => element.inert), true);
        await page.evaluate(() => {
            handlers.change({empty: false, value: {type: 'card'}});
            document.querySelector('form').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
        });
        assert.equal(await page.evaluate(() => requests.length), 2, 'A second card cannot send another tokenless request');
        assert.equal(await page.evaluate(() => tokens), 0);
        assert.deepEqual(await page.evaluate(() => requests), [0, 1].map(() => ({
            intent: 'fixture-intent', confirmation_token: null, amount: 2000, save: true,
        })), 'Authentication keeps the resumed intent, amount and consent');
        await page.route('https://example.com/resume', route => route.fulfill({contentType: 'text/html', body: 'Fresh payment checkout'}));
        await page.locator('#refresh-payment').click();
        await page.getByText('Fresh payment checkout').waitFor();
        await page.close();
    }
    console.log('Pay and setup network failures, retry and card declines passed.');
} catch (error) {
    if (process.env.AE_STRIPE_FAILURE_DIR && activePage && !activePage.isClosed()) {
        const prefix = process.env.AE_STRIPE_FAILURE_DIR + '/failure';
        writeFileSync(prefix + '.html', await activePage.content());
        writeFileSync(prefix + '.url', activePage.url());
        await activePage.screenshot({path: prefix + '.png'});
    }
    throw error;
} finally {
    await browser.close();
}
