// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Exercise the production submit handlers with offline browser/Stripe boundaries.
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const views = new URL('../extension/AeStripe/resources/views/', import.meta.url);
const theme = readFileSync(new URL('stripe-theme.blade.php', views), 'utf8');
function render(name, linkDisplay = 'above_card') {
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
        .replace("@js(in_array($intent->status, ['requires_action', 'requires_confirmation'], true))", 'false')
        .replaceAll('@js($intent->amount)', '2000').replace("@js(strtolower($intent->currency))", "'usd'")
        .replace("@js(($intent->metadata->ae_stripe_consent ?? null) === '1')", 'false')
        .replace(/@checked\([^\n]+? disabled/, 'disabled')
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
        [name, 'above_card', true],
        ...['above_card', 'inside_card', 'stripe_default'].map(mode => [name, mode, false]),
    ]);
    for (const [name, linkDisplay, expressThrows] of cases) {
        const page = activePage = await browser.newPage();
        await page.route('**/*', route => route.fulfill({ contentType: 'text/javascript', body: '' }));
        page.setDefaultTimeout(5000);
        await page.setContent('<html><body></body></html>');
        await page.evaluate(expressThrows => {
            window.expressThrows = expressThrows;
            window.failure = 'network';
            window.confirms = 0;
            window.elementCalls = [];
            window.elementHandlers = {};
            window.fetch = async () => {
                if (failure === 'network') throw new TypeError('Failed to fetch');
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
                    if (type === 'expressCheckout' && expressThrows) throw new Error('Express Checkout is unsupported');
                    if (type === 'payment') window.paymentElementOptions = options;
                    const handlers = elementHandlers[type] = {};
                    return { on: (name, fn) => { handlers[name] = fn; }, mount(selector) {
                        (typeof selector === 'string' ? document.querySelector(selector) : selector).textContent =
                            type === 'payment' ? 'Card form' : 'Pay with Link';
                        handlers.ready?.({availablePaymentMethods: {link: true}});
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
        await page.setContent(render(name, linkDisplay));
        if (name === 'pay') {
            await page.waitForFunction(() => !!document.querySelector('script[src]'));
            await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
            const options = await page.evaluate(() => elementOptions);
            assert.equal(options.mode, 'payment');
            assert.equal(options.amount, 2000);
            assert.equal(options.currency, 'usd');
            assert.equal(options.clientSecret, undefined);
            assert.deepEqual(options.excludedPaymentMethodTypes, ['blik', 'acss_debit', 'customer_balance']);
            const calls = await page.evaluate(() => elementCalls);
            const payment = calls.find(call => call.type === 'payment').options;
            assert.equal(payment.defaultValues.billingDetails.email, linkDisplay === 'stripe_default' ? 'Fixture' : undefined,
                `${linkDisplay}: only Stripe default prefills email`);
            assert.equal(payment.layout.type, 'accordion');
            assert.equal(payment.layout.defaultCollapsed, false);
            assert.deepEqual(calls.filter(call => call.type === 'expressCheckout').map(call => call.options),
                linkDisplay === 'above_card' ? [{paymentMethods: {link: 'auto', applePay: 'never', googlePay: 'never',
                    amazonPay: 'never', paypal: 'never', klarna: 'never'}}] : [],
                `${linkDisplay}: only the above-card choice mounts Link-only Express Checkout`);
        } else {
            await page.evaluate(async linkDisplay => {
                window.component = setupFactory({ publishableKey: 'fixture-key', clientSecret: 'fixture-secret', intent: 'fixture-intent', consentUrl: '/consent', returnUrl: '/return', csrf: 'fixture-csrf', name: 'Fixture', email: 'Fixture', linkDisplay });
                component.$el = document.querySelector('form');
                await component.init();
            }, linkDisplay);
            assert.equal(await page.locator('#submit').isEnabled(), false, 'Setup requires explicit consent');
            const options = await page.evaluate(() => elementOptions);
            assert.equal(options.clientSecret, 'fixture-secret', 'Setup keeps the existing SetupIntent flow');
            assert.equal(options.mode, undefined);
            const calls = await page.evaluate(() => elementCalls);
            assert.equal(calls.find(call => call.type === 'payment').options.defaultValues.billingDetails.email,
                linkDisplay === 'stripe_default' ? 'Fixture' : undefined, `${linkDisplay}: setup only prefills email for Stripe default`);
            assert.equal(calls.find(call => call.type === 'payment').options.layout.defaultCollapsed, false);
            assert.deepEqual(calls.filter(call => call.type === 'expressCheckout').map(call => call.options),
                linkDisplay === 'above_card' ? [{paymentMethods: {link: 'auto', applePay: 'never', googlePay: 'never',
                    amazonPay: 'never', paypal: 'never', klarna: 'never'}}] : []);
            if (expressThrows) assert.equal(await page.locator('#setup-consent').isEnabled(), true, 'Card setup stays ready when Express creation throws');
            await page.locator('#setup-consent').check();
        }
        if (expressThrows) {
            assert.equal(await page.locator('#payment-element').textContent(), 'Card form', `${name}: card form mounts when Express creation throws`);
            assert.equal(await page.locator('#express-checkout').isVisible(), false, `${name}: failed Express row stays hidden`);
            assert.equal(await page.locator('#submit').isEnabled(), true, `${name}: card submission remains available`);
        }
        assert.notEqual(await page.evaluate(() => paymentElementOptions.wallets?.link ?? 'auto'), 'never', `${name}: Link stays available`);
        const fallback = name === 'pay' ? 'Could not check the payment. Refresh the invoice for its status.' : 'Could not save your payment method. Try again.';
        for (const failure of ['network', 'confirmation', ...(name === 'pay' ? ['updates'] : []), 'consent', 'decline']) {
            await page.evaluate(value => { window.failure = value; }, failure);
            const before = await page.evaluate(() => confirms);
            await page.locator('#submit').click();
            const expected = failure === 'decline' ? 'Your card was declined.' : failure === 'consent'
                ? (name === 'pay' ? 'Could not record payment preferences. Refresh the invoice.' : 'Could not record your agreement. Try again.') : fallback;
            await page.waitForFunction(() => document.querySelector('[role=alert]').textContent.length > 0);
            assert.equal(await page.locator('[role=alert]').textContent(), expected, `${name}: ${failure}`);
            assert.equal(await page.locator('#submit').isEnabled(), true, `${name}: retry stays available after ${failure}`);
            if (['network', 'consent', 'updates'].includes(failure)) assert.equal(await page.evaluate(() => confirms), before, 'Failed preparation cannot confirm');
        }
        if (name === 'billing-agreement' && linkDisplay === 'above_card' && !expressThrows) {
            assert.equal(await page.locator('#express-checkout').isVisible(), true);
            assert.equal(await page.evaluate(() => document.querySelector('#express-checkout').compareDocumentPosition(
                document.querySelector('#payment-element')) & Node.DOCUMENT_POSITION_FOLLOWING), 4);
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
            assert.equal(await page.locator('[role=alert]').textContent(), 'Agree to future charges before saving your payment method.');
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
                assert.equal(await page.locator('#express-checkout').isVisible(), true, 'Available Link appears above the card form');
                assert.equal(await page.evaluate(() => document.querySelector('#express-checkout').compareDocumentPosition(
                    document.querySelector('#payment-element')) & Node.DOCUMENT_POSITION_FOLLOWING), 4);
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
