// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Exercise the production submit handlers with offline browser/Stripe boundaries.
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const views = new URL('../extension/AeStripe/resources/views/', import.meta.url);
const theme = readFileSync(new URL('stripe-theme.blade.php', views), 'utf8');
function render(name, linkDisplay = 'above_card', businessName = 'Fixture Hosting', resuming = false, savedConsent = false, amount = 2000, destination = 'https://example.com/return') {
    let source = readFileSync(new URL(`${name}.blade.php`, views), 'utf8')
        .replace("@include('gateways.ae-stripe::stripe-theme')", theme)
        .replace("@include('gateways.ae-stripe::stripe-elements')", () => readFileSync(new URL('stripe-elements.blade.php', views), 'utf8'))
        .replace("@include('gateways.ae-stripe::stripe-form')", () => readFileSync(new URL('stripe-form.blade.php', views), 'utf8'))
        .replace(/@if\(config\('settings.tos'\)\)[\s\S]*?@endif/g, '')
        .replace(/@js\(route\('([^']+)'\)\)/g, (_, route) => JSON.stringify('/' + route))
        .replace("@js(route('invoices.show', $invoice) . '?checkPayment=true')", "'/return'")
        .replace("@js(route('invoices.show', $invoice))", JSON.stringify(destination))
        .replace('@js(csrf_token())', "'fixture-csrf'")
        .replaceAll('@js($stripePublishableKey)', "'fixture-key'")
        .replaceAll('@js($intent->client_secret)', "'fixture-secret'")
        .replaceAll('@js($intent->id)', "'fixture-intent'")
        .replace('@js($customerSessionSecret)', 'null')
        .replace('@js($linkDisplay)', JSON.stringify(linkDisplay))
        .replace('@js($businessName)', JSON.stringify(businessName))
        .replace("@js(in_array($intent->status, ['requires_action', 'requires_confirmation'], true))", JSON.stringify(resuming))
        .replaceAll('@js($intent->amount)', JSON.stringify(amount)).replace("@js(strtolower($intent->currency))", "'usd'")
        .replace("@js(($intent->metadata->ae_stripe_consent ?? null) === '1')", JSON.stringify(savedConsent))
        .replace(/@checked\([^\n]+? disabled/, resuming && savedConsent ? 'checked disabled' : 'disabled')
        .replace(/\{\{[^\n]+?\}\}/g, "'Fixture'")
        .replaceAll('@script', '').replaceAll('@endscript', '');
    assert(!/@js|@checked|\{\{|@include/.test(source), 'Blade expressions are rendered');
    return source;
}
function paymentFields(body) {
    assert.match(body.saved_notice, /^[a-f0-9]{32}$/, 'Each checkout sends its opaque notice token');
    const {saved_notice, ...fields} = body;
    return fields;
}
// Offline Stripe.js and fetch boundaries; each Element records its options and handlers for the checks.
async function installStripe(page, expressThrows) {
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
            elements: (options) => { window.elementOptions = options; let updated; return window.elementsInstance = {
                on(name, fn) { if (name === 'update-end') updated = fn; }, update() { updated?.(); }, async submit() {
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
                    const ready = () => handlers.ready?.({availablePaymentMethods: expressThrows === 'unavailable' ? null : {link: true}});
                    // A held Element reports ready only when the check calls heldReady[type]().
                    if (window.holdReady?.includes(type)) (window.heldReady ??= {})[type] = ready;
                    else ready();
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
                window.tokenCalls?.push(elements === elementsInstance ? 'current' : 'replaced');
                if (elements !== elementsInstance) throw new Error('Token must use the mounted Elements instance');
                if (failure === 'confirmation') throw new TypeError('Failed to tokenize');
                if (failure === 'decline') return {error: {type: 'card_error', message: 'Your card was declined.'}};
                return {confirmationToken: {id: 'ctoken_fixture'}};
            },
            async handleNextAction(options) { if (window.onAction) await window.onAction(options); return confirm(); },
            async confirmSetup({elements, confirmParams}) {
                window.tokenCalls?.push(elements === elementsInstance ? 'current' : 'replaced');
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
        await installStripe(page, expressThrows);
        await page.setContent(render(name, linkDisplay, businessName));
        if (name === 'pay') {
            await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
            assert.equal(await page.locator('script[src]').count(), 0, 'Stripe.js already on the page needs no second request');
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
            assert.equal(await page.locator('#submit').isEnabled(), true, 'Save card is ready once Stripe is');
            assert.equal(await page.locator('[role=alert]').textContent(), '', 'The form opens without an error');
            assert.equal(await page.locator('#setup-consent').count(), 0, 'There is no box to tick');
            assert.equal(await page.locator('#payment-element').evaluate(element => element.inert), false, 'Card fields are live at once');
            assert.equal(await page.locator('#express-checkout').evaluate(element => element.inert), false, 'Link is live at once');
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
            assert.equal(await page.evaluate(() => document.querySelector('#express-checkout').compareDocumentPosition(
                document.querySelector('#payment-element')) & Node.DOCUMENT_POSITION_FOLLOWING), 4, 'Link sits above the card fields');
            await page.evaluate(() => {
                window.setupRequests = [];
                window.linkFailures = [];
                window.failure = 'decline';
                window.fetch = async (url, options) => {
                    setupRequests.push({url, method: options.method, csrf: options.headers['X-CSRF-TOKEN'], body: JSON.parse(options.body)});
                    return {ok: true};
                };
            });
            const confirmLink = () => page.evaluate(() => elementHandlers.expressCheckout.confirm({paymentFailed: value => linkFailures.push(value)}));
            await confirmLink();
            assert.deepEqual(await page.evaluate(() => setupRequests), [{url: '/consent', method: 'POST', csrf: 'fixture-csrf',
                body: {intent: 'fixture-intent', consent: true, version: 'off_session_v2'}}]);
            assert.deepEqual(await page.evaluate(() => setupConfirmParams), {return_url: '/return'});
            assert.equal(await page.locator('[role=alert]').textContent(), 'Your card was declined.');
            assert.deepEqual(await page.evaluate(() => linkFailures), [{reason: 'fail'}], 'Only the decline fails Link');
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
            assert.equal(await page.locator('#submit').isEnabled(), false, 'Save card waits while Link saves');
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
                assert.deepEqual((await page.evaluate(() => linkRequests)).map(r => ({...r, body: paymentFields(r.body)})), [false, true, false].map(save => ({
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
            await page.route('https://example.com/return**', route => route.fulfill({contentType: 'text/html', body: 'Returned'}));
            await page.evaluate(() => {
                const base = document.createElement('base'); base.href = 'https://example.com/'; document.head.append(base);
                window.failure = 'action-success';
                let calls = 0;
                window.fetch = async (_, options) => {
                    await onRequest(JSON.parse(options.body));
                    return {ok: true, async json() { return ++calls === 1
                        ? {status: 'requires_action', client_secret: 'fixture-secret'} : {status: 'succeeded', saved_notice: 'a'.repeat(32)}; }};
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
            await page.waitForURL(url => url.origin === 'https://example.com' && url.pathname === '/return');
            assert.equal(page.url(), 'https://example.com/return?checkPayment=true&saved_notice=' + 'a'.repeat(32),
                'The checkout-tab notice token reaches the invoice destination');
            assert.equal(new Set(requests.map(r => r.saved_notice)).size, 1, 'Authentication keeps the same checkout-tab token');
            assert.deepEqual(actions, [{clientSecret: 'fixture-secret'}]);
            assert.deepEqual(requests.map(paymentFields), [
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
    for (const decline of ['response', 'status', 'sdk-error']) {
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
                elements: () => ({on() {}, update() {}, async submit() {return {};}, create(type) {
                    if (type !== 'payment') throw new Error('A resumed payment cannot offer Express Checkout');
                    return {on(name, fn) {handlers[name] = fn;}, mount() {handlers.ready();},
                        clear() {handlers.change?.({empty: true});}, destroy() {}};
                }}),
                async handleNextAction() {actions++; return decline === 'sdk-error'
                    ? {error: {type: 'card_error', message: 'Authentication declined.'}} : {};},
                async createConfirmationToken() {tokens++; return {confirmationToken: {id: 'ctoken_new'}};},
            });
        }, decline);
        await page.setContent(render('pay', 'above_card', 'Fixture Hosting', true, true));
        await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
        assert.equal(await page.locator('#save-card').isChecked(), true, 'Resume keeps the original save consent');
        await page.locator('#submit').click();
        await page.waitForFunction(() => requests.length >= 1 && !document.querySelector('#submit').classList.contains('hidden'));
        assert.equal(await page.locator('#submit').isDisabled(), true,
            'A declined resumed payment cannot submit another card in tokenless mode');
        assert.match(await page.locator('[role=alert]').textContent(), /[Rr]efresh/);
        assert.equal(await page.locator('#refresh-payment').isVisible(), true, 'A visible refresh action prepares a new card checkout');
        assert.equal(await page.locator('#payment-element').evaluate(element => element.inert), true);
        await page.evaluate(() => {
            handlers.change({empty: false, value: {type: 'card'}});
            document.querySelector('form').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
        });
        const requestCount = decline === 'sdk-error' ? 1 : 2;
        assert.equal(await page.evaluate(() => requests.length), requestCount, 'A second card cannot send another tokenless request');
        assert.equal(await page.evaluate(() => tokens), 0);
        assert.deepEqual((await page.evaluate(() => requests)).map(paymentFields), Array.from({length: requestCount}, () => ({
            intent: 'fixture-intent', confirmation_token: null, amount: 2000, save: true,
        })), 'Authentication keeps the resumed intent, amount and consent');
        await page.route('https://example.com/resume', route => route.fulfill({contentType: 'text/html', body: 'Fresh payment checkout'}));
        await page.locator('#refresh-payment').click();
        await page.getByText('Fresh payment checkout').waitFor();
        await page.close();
    }
    // Add card offers two choices under one consent line: the Link wallet, or a card. Each saves at once, phone and desktop.
    for (const viewport of [{width: 390, height: 844}, {width: 1440, height: 900}]) {
        const page = activePage = await browser.newPage({viewport});
        await page.route('**/*', route => route.fulfill({ contentType: 'text/javascript', body: '' }));
        page.setDefaultTimeout(5000);
        await page.setContent('<html><body></body></html>');
        await installStripe(page, false);
        await page.setContent(render('billing-agreement'));
        await page.evaluate(async () => {
            window.failure = 'action-success';
            window.setupRequests = [];
            window.linkFailures = [];
            window.consentOk = false;
            window.fetch = async (url, options) => { setupRequests.push({url, body: JSON.parse(options.body)}); return {ok: consentOk}; };
            window.component = setupFactory({ publishableKey: 'fixture-key', clientSecret: 'fixture-secret', intent: 'fixture-intent', consentUrl: '/consent', returnUrl: '/return', csrf: 'fixture-csrf', name: 'Fixture', email: 'Fixture', linkDisplay: 'above_card', businessName: 'Fixture Hosting' });
            component.$el = document.querySelector('form');
            await component.init();
        });
        const at = `${viewport.width}px`;
        const terms = page.locator('#setup-terms');
        assert.match(await terms.textContent(), /By saving with Link or a card, you agree to save it for future charges, including renewals\./, `${at}: one consent line`);
        assert.match(await terms.textContent(), /Saving does not turn on Auto Pay\./, `${at}: saving stays apart from Auto Pay`);
        for (const [label, locator] of [['consent line', terms], ['Link choice', page.getByText('Use your Link wallet')],
            ['divider', page.getByText('or add a card')], ['card fields', page.locator('#payment-element')], ['Save card', page.getByRole('button', {name: 'Save card'})]]) {
            assert.equal(await locator.isVisible(), true, `${at}: ${label} shows before any choice`);
        }
        const boxes = await page.evaluate(() => ['#setup-terms', '#express-checkout', '#payment-element', '#submit']
            .map(selector => document.querySelector(selector).getBoundingClientRect().toJSON()));
        for (let i = 1; i < boxes.length; i++) assert(boxes[i - 1].bottom <= boxes[i].top, `${at}: consent, Link, card fields and Save read top to bottom`);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${at}: nothing runs off the side`);
        assert.equal(await page.locator('[role=alert]').textContent(), '', `${at}: no error before a choice`);
        for (const choice of ['Link', 'card']) {
            if (choice === 'Link') await page.evaluate(() => elementHandlers.expressCheckout.confirm({paymentFailed: value => linkFailures.push(value)}));
            else await page.getByRole('button', {name: 'Save card'}).click();
            await page.waitForFunction(() => document.querySelector('[role=alert]').textContent.length > 0);
            assert.equal(await page.evaluate(() => confirms), 0, `${at}: failed ${choice} consent cannot confirm setup`);
            assert.equal(await page.locator('#submit').isEnabled(), true, `${at}: failed ${choice} consent permits retry`);
        }
        assert.deepEqual(await page.evaluate(() => linkFailures), [{reason: 'fail'}], `${at}: consent failure dismisses Link`);
        assert.deepEqual(await page.evaluate(() => setupRequests), Array.from({length: 2}, () => ({url: '/consent', body: {intent: 'fixture-intent', consent: true, version: 'off_session_v2'}})));
        await page.evaluate(() => {consentOk = true; setupRequests = []; linkFailures = [];});
        await page.evaluate(() => elementHandlers.expressCheckout.confirm({paymentFailed: value => linkFailures.push(value)}));
        assert.deepEqual(await page.evaluate(() => setupRequests), [{url: '/consent', body: {intent: 'fixture-intent', consent: true, version: 'off_session_v2'}}],
            `${at}: choosing Link records the agreement first`);
        assert.equal(await page.evaluate(() => confirms), 1, `${at}: Link saves at once`);
        assert.deepEqual(await page.evaluate(() => linkFailures), [], `${at}: Link never fails for consent`);
        assert.equal(await page.locator('[role=alert]').textContent(), '', `${at}: Link shows no error`);
        await page.getByRole('button', {name: 'Save card'}).click();
        await page.waitForFunction(() => confirms === 2);
        assert.equal(await page.evaluate(() => setupRequests.length), 2, `${at}: choosing a card records the agreement first`);
        assert.equal(await page.locator('[role=alert]').textContent(), '', `${at}: the card saves without an error`);
        await page.close();
    }
    for (const scenario of ['decline', 'save-card', 'once-card', 'save-link', 'once-link']) {
        const page = activePage = await browser.newPage({viewport: {width: 390, height: 844}});
        const sent = [];
        page.setDefaultTimeout(5000);
        await page.exposeFunction('captureNewCard', request => sent.push(request));
        await page.route('**/*', route => route.fulfill({contentType: 'text/javascript', body: ''}));
        await page.route('https://example.com/new-card', route => route.fulfill({contentType: 'text/html', body: '<html><body></body></html>'}));
        await page.route('https://example.com/return**', route => route.fulfill({contentType: 'text/html', body: 'Payment received'}));
        await page.goto('https://example.com/new-card');
        await page.evaluate(scenario => {
            window.handlers = {}; window.order = []; window.$wire = {on() {}};
            window.fetch = async (_, options) => {
                await captureNewCard({body: JSON.parse(options.body), tokenOptions, order});
                return {ok: scenario !== 'decline', async json() { return scenario === 'decline'
                    ? {declined: true, message: 'Your bank declined this card. Try a different card, or contact your bank.'}
                    : {status: 'succeeded'}; }};
            };
            window.Stripe = () => ({
                elements: options => {
                    window.currentOptions = {...options};
                    return {on(name, fn) {handlers[name] = fn;}, update(changes) {
                        // Stripe.js v3 returns void; the options reach the iframe later.
                        setTimeout(() => {Object.assign(currentOptions, changes); order.push('updated'); handlers['update-end']?.();}, 20);
                    }, async submit() {order.push('submit'); return {};},
                        create(type) {
                            const events = handlers[type] = {};
                            return {on(name, fn) {events[name] = fn;}, mount() {events.ready?.({availablePaymentMethods: {link: true}});},
                                clear() {events.change?.({empty: true});}, destroy() {}};
                        }};
                },
                async createConfirmationToken() {
                    order.push('token');
                    window.tokenOptions = JSON.parse(JSON.stringify(currentOptions));
                    return {confirmationToken: {id: 'ctoken_newcard'}};
                },
            });
        }, scenario);
        await page.setContent(render('pay', 'above_card', 'Fixture Hosting', false, false, 1099));
        await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
        await page.evaluate(() => handlers.payment.change({empty: false, value: {type: 'card'}}));
        await page.locator('#save-card').setChecked(scenario.startsWith('save'));
        if (scenario.endsWith('link')) {
            await page.evaluate(() => handlers.expressCheckout.confirm({paymentFailed() {}}));
        } else await page.locator('#submit').click();
        if (scenario === 'decline') {
            await page.waitForFunction(() => document.querySelector('[role=alert]').textContent.includes('Your bank declined'));
            assert.equal(await page.locator('#refresh-payment').isVisible(), true,
                'A fresh-card bank decline offers Refresh payment form');
        } else await page.getByText('Payment received', {exact: true}).waitFor();
        assert.equal(sent.length, 1, 'One submit sends one confirmation');
        assert.deepEqual(sent[0].order, ['updated', 'submit', 'token'], 'Card and Link submit only after options apply');
        const save = scenario.startsWith('save');
        assert.deepEqual(paymentFields(sent[0].body), {intent: 'fixture-intent', confirmation_token: 'ctoken_newcard', amount: 1099, save});
        assert.deepEqual(sent[0].tokenOptions.paymentMethodTypes, ['card', 'link']);
        assert.deepEqual(sent[0].tokenOptions.paymentMethodOptions, {
            card: {setup_future_usage: save ? 'off_session' : 'none'},
            link: {setup_future_usage: save ? 'off_session' : 'none'},
        }, 'The token collects the same saving options the server will confirm');
        if (scenario === 'decline') {
            await page.route('https://example.com/new-card', route => route.fulfill({contentType: 'text/html', body: 'Fresh payment checkout'}));
            await page.locator('#refresh-payment').click();
            await page.getByText('Fresh payment checkout', {exact: true}).waitFor();
        }
        await page.close();
    }
    // A missing iframe update event must release the form without creating a token or payment.
    for (const choice of ['card', 'Link']) for (const updateFailure of ['missing-event', 'throw', 'reject']) {
        const page = activePage = await browser.newPage();
        await page.route('**/*', route => route.fulfill({contentType: 'text/javascript', body: ''}));
        await page.setContent('<html><body></body></html>');
        await page.clock.install();
        await installStripe(page, false);
        await page.evaluate(updateFailure => {
            window.updates = 0; window.submits = 0; window.tokens = 0; window.requests = 0; window.linkFailures = [];
            window.failure = 'action-success';
            window.fetch = async () => {requests++; return {ok: false, async json() {return {message: 'Try again.'};}};};
            const makeStripe = window.Stripe;
            window.Stripe = () => {
                const stripe = makeStripe(); const makeElements = stripe.elements;
                stripe.elements = options => {
                    const elements = makeElements(options);
                    elements.update = () => {updates++; if (updateFailure === 'throw') throw new Error('Update failed'); if (updateFailure === 'reject') return Promise.reject(new Error('Update failed'));};
                    elements.submit = async () => {submits++; return {};};
                    return elements;
                };
                stripe.createConfirmationToken = async () => {tokens++; return {confirmationToken: {id: 'ctoken_fixture'}};};
                return stripe;
            };
        }, updateFailure);
        await page.setContent(render('pay'));
        await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
        const submit = () => page.evaluate(choice => {
            if (choice === 'Link') elementHandlers.expressCheckout.confirm({paymentFailed: value => linkFailures.push(value)});
            else document.querySelector('form').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
        }, choice);
        await submit();
        if (updateFailure === 'missing-event') {
            await submit();
            assert.equal(await page.evaluate(() => updates), 1, `${choice}: overlapping submission cannot start another update`);
            await page.clock.fastForward(5001);
        }
        // Rejected updates release the form immediately, without advancing the timeout clock.
        assert.equal(await page.locator('#submit').isEnabled(), true, `${choice}: ${updateFailure} releases the form`);
        assert.equal(await page.locator('#submit').isVisible(), true, `${choice}: ${updateFailure} restores Pay`);
        assert.match(await page.locator('[role=alert]').textContent(), /Could not check the payment/);
        const message = await page.locator('[role=alert]').textContent();
        await page.clock.fastForward(30000);
        assert.equal(await page.locator('[role=alert]').textContent(), message, 'The timeout error stays visible until another customer action');
        assert.deepEqual(await page.evaluate(() => [submits, tokens, requests]), [0, 0, 0], `${choice}: unresolved options cannot reach payment`);
        if (choice === 'Link') assert.deepEqual(await page.evaluate(() => linkFailures), Array.from({length: updateFailure === 'missing-event' ? 2 : 1}, () => ({reason: 'fail'})), 'Overlapping Link and unresolved options each dismiss their own payment UI');
        await page.close();
    }
    for (const destination of ['https://example.com/invoices/fixture', 'https://example.com/account/billing/invoices/fixture?tab=history']) {
        const page = activePage = await browser.newPage({viewport: {width: 390, height: 844}});
        await page.route('**/*', route => route.fulfill({contentType: 'text/javascript', body: ''}));
        await page.goto('https://example.com/checkout');
        await installStripe(page, false);
        await page.evaluate(() => {
            window.fetch = async () => ({ok: true, async json() { return {status: 'succeeded', saved_notice: 'b'.repeat(32)}; }});
        });
        await page.setContent(render('pay', 'above_card', 'Fixture', false, false, 2000, destination));
        await page.waitForFunction(() => !document.querySelector('#submit').disabled);
        await page.locator('#submit').click();
        const expected = new URL(destination);
        expected.searchParams.set('checkPayment', 'true'); expected.searchParams.set('saved_notice', 'b'.repeat(32));
        await page.waitForURL(expected.toString());
        await page.close();
    }
    // A Payment Element load error keeps its Try again through edits; the retry starts without the old Link state
    // and ignores late events from the released Elements.
    for (const name of forms) {
        const page = activePage = await browser.newPage({viewport: {width: 390, height: 844}});
        page.setDefaultTimeout(5000);
        await page.route('**/*', route => route.fulfill({contentType: 'text/javascript', body: ''}));
        await page.setContent('<html><body></body></html>');
        await installStripe(page, false);
        await page.setContent(render(name));
        if (name === 'pay') await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
        else await page.evaluate(async () => {
            window.component = setupFactory({ publishableKey: 'fixture-key', clientSecret: 'fixture-secret', intent: 'fixture-intent', consentUrl: '/consent', returnUrl: '/return', csrf: 'fixture-csrf', name: 'Fixture', email: 'Fixture', linkDisplay: 'above_card', businessName: 'Fixture Hosting' });
            component.$el = document.querySelector('form');
            await component.init();
        });
        const row = page.locator('#express-checkout');
        const alert = page.locator('[role=alert] p');
        const retry = page.getByRole('button', {name: 'Try again'});
        const loadError = 'Could not load the payment form. Check your connection and try again.';
        assert.equal(await row.isVisible(), true, `${name}: Link reports available first`);
        if (name === 'pay') assert.equal(await page.locator('#save-card').isEnabled(), true, 'Available Link enables Save this card');
        await page.evaluate(() => { window.oldHandlers = {...elementHandlers}; oldHandlers.payment.loaderror({error: {message: 'Element failed'}}); });
        assert.equal(await alert.textContent(), loadError, `${name}: the Payment Element load error is shown`);
        assert.equal(await page.locator('#submit').isDisabled(), true, `${name}: submission stays off`);
        if (name === 'pay') await page.locator('#save-card').setChecked(true);
        await page.evaluate(() => elementHandlers.payment.change({empty: false, complete: false, value: {type: 'card'}}));
        assert.equal(await alert.textContent(), loadError, `${name}: editing keeps the load error`);
        assert.equal(await retry.isVisible(), true, `${name}: editing keeps Try again`);
        await page.evaluate(() => { window.holdReady = ['payment', 'expressCheckout']; });
        await retry.click();
        await page.waitForFunction(() => elementCalls.filter(call => call.type === 'payment').length === 2);
        const assertWaiting = async when => {
            assert.equal(await row.isVisible(), false, `${name}: ${when}: the old Link row is hidden`);
            assert.equal(await page.locator('#submit').isDisabled(), true, `${name}: ${when}: submission waits for the new form`);
            assert.equal(await page.locator('[role=alert]').textContent(), '', `${name}: ${when}: no error`);
            if (name === 'pay') {
                assert.equal(await page.locator('#save-card').isDisabled(), true, `${when}: Save this card waits for a fresh choice`);
                assert.equal(await page.locator('#save-card').isChecked(), false, `${when}: the old saving choice is cleared`);
            }
        };
        await assertWaiting('after Try again');
        await page.evaluate(() => {
            oldHandlers.expressCheckout.availablepaymentmethodschange({paymentMethods: {link: {available: true}}});
            oldHandlers.expressCheckout.ready({availablePaymentMethods: {link: true}});
            oldHandlers.payment.change({empty: false, complete: true, value: {type: 'card'}});
            oldHandlers.payment.ready();
        });
        await assertWaiting('after late events from the old form');
        if (name === 'pay') {
            await page.evaluate(() => elementHandlers.payment.change({empty: false, complete: false, value: {type: 'card'}}));
            assert.equal(await page.locator('#save-card').isEnabled(), true, 'A fresh card selection enables Save this card');
            assert.equal(await row.isVisible(), false, 'A card selection does not report Link');
            assert.equal(await page.locator('#submit').isDisabled(), true, 'Pay still waits for the new form');
        }
        await page.evaluate(() => heldReady.expressCheckout());
        assert.equal(await row.isVisible(), true, `${name}: fresh Link availability shows the row`);
        await page.evaluate(() => heldReady.payment());
        await page.waitForFunction(() => document.querySelector('#submit').disabled === false);
        // A Link confirmation from the replaced Elements is refused before any Stripe or server call.
        const before = await page.evaluate(() => ({confirms, checked: document.querySelector('#save-card')?.checked ?? null}));
        await page.evaluate(async () => {
            window.tokenCalls = [];
            window.lateRequests = [];
            window.lateFailures = [];
            window.failure = 'action-success';
            window.fetch = async url => { lateRequests.push(url); return {ok: true, async json() { return {status: 'succeeded'}; }}; };
            await oldHandlers.expressCheckout.confirm({expressPaymentType: 'link', paymentFailed: value => lateFailures.push(value)});
            await new Promise(resolve => requestAnimationFrame(resolve));
        });
        assert.deepEqual(await page.evaluate(() => [tokenCalls, lateRequests]), [[], []], `${name}: the old Link confirmation reaches neither Stripe nor the server`);
        assert.deepEqual(await page.evaluate(() => lateFailures), [{reason: 'fail'}], `${name}: the old Link confirmation is dismissed`);
        assert.deepEqual(await page.evaluate(() => ({confirms, checked: document.querySelector('#save-card')?.checked ?? null})), before, `${name}: nothing is confirmed`);
        assert.equal(await page.locator('[role=alert]').textContent(), '', `${name}: the old Link confirmation shows no error`);
        assert.equal(await page.locator('#submit').isEnabled(), true, `${name}: the new form stays ready after the old Link confirmation`);
        assert.equal(await row.isVisible(), true, `${name}: the new Link row stays`);
        await page.evaluate(() => oldHandlers.payment.loaderror({error: {message: 'Late failure'}}));
        assert.equal(await page.locator('[role=alert]').textContent(), '', `${name}: a late load error from the old form is ignored`);
        assert.equal(await page.locator('#submit').isEnabled(), true, `${name}: the new form stays ready`);
        await page.close();
    }
    // Stripe.js that fails or never loads shows the error with Try again; a Stripe.js tag already on the page is reused.
    if (forms.includes('pay')) for (const scenario of ['timeout', 'error', 'existing']) {
        const page = activePage = await browser.newPage({viewport: {width: 390, height: 844}});
        page.setDefaultTimeout(5000);
        await page.clock.install();
        const requests = [];
        let requested = null;
        await page.route('**/*', route => route.fulfill({contentType: 'text/javascript', body: ''}));
        await page.route('https://js.stripe.com/**', route => { requests.push(route); requested?.(); });
        const nextRequest = async count => {
            while (requests.length < count) await new Promise(resolve => { requested = resolve; });
            return requests[count - 1];
        };
        const load = route => route.fulfill({contentType: 'text/javascript', body: 'window.Stripe = window.stripeFixture;'});
        await page.setContent('<html><body></body></html>');
        await installStripe(page, false);
        await page.evaluate(() => { window.stripeFixture = window.Stripe; delete window.Stripe; });
        const existing = scenario === 'existing'
            ? '<script id="existing-stripe" src="https://js.stripe.com/v3/" async onload="window.existingLoaded = true"></script>' : '';
        await page.setContent(existing + render('pay'), {waitUntil: 'domcontentloaded'});
        const first = await nextRequest(1);
        const alert = page.locator('[role=alert] p');
        const retry = page.getByRole('button', {name: 'Try again'});
        if (scenario === 'existing') {
            assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 1, 'Pay reuses the Stripe.js tag already on the page');
            await load(first);
        } else {
            if (scenario === 'timeout') {
                await page.clock.fastForward(29000);
                assert.equal(await page.locator('[role=alert]').textContent(), '', 'A slow Stripe.js load gets the full wait');
                assert.equal(await page.locator('#submit').isDisabled(), true, 'Pay waits for Stripe.js');
                await page.clock.fastForward(1000);
            } else await first.abort('failed');
            await alert.waitFor();
            assert.equal(await alert.textContent(), 'Could not load the payment form. Check your connection and try again.', `${scenario}: the customer sees why`);
            assert.equal(await retry.isVisible(), true, `${scenario}: the customer can retry`);
            assert.equal(await page.locator('#submit').isDisabled(), true, `${scenario}: Pay stays off until Stripe.js loads`);
            assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 0, `${scenario}: the failed tag is removed`);
            // End the held request before retry so the browser does not join the new request to it.
            if (scenario === 'timeout') await first.abort('failed');
            await retry.click();
            await load(await nextRequest(2));
        }
        await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
        assert.equal(await page.locator('#payment-element').textContent(), 'Card form', `${scenario}: the card form mounts`);
        assert.equal(await page.locator('[role=alert]').textContent(), '', `${scenario}: no error once Stripe.js loads`);
        assert.equal(requests.length, scenario === 'existing' ? 1 : 2, `${scenario}: one Stripe.js request per attempt`);
        if (scenario === 'existing') assert.equal(await page.evaluate(() => window.existingLoaded), true, 'The existing tag keeps its own load handler');
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
