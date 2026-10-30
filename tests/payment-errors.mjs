// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Exercise the production submit handlers with offline browser/Stripe boundaries.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const views = new URL('../extension/AeStripe/resources/views/', import.meta.url);
const theme = readFileSync(new URL('stripe-theme.blade.php', views), 'utf8');
function render(name) {
    let source = readFileSync(new URL(`${name}.blade.php`, views), 'utf8')
        .replace("@include('gateways.ae-stripe::stripe-theme')", theme)
        .replace(/@js\(route\('([^']+)'\)\)/g, (_, route) => JSON.stringify('/' + route))
        .replace("@js(route('invoices.show', $invoice) . '?checkPayment=true')", "'/return'")
        .replace('@js(csrf_token())', "'fixture-csrf'")
        .replaceAll('@js($stripePublishableKey)', "'fixture-key'")
        .replaceAll('@js($intent->client_secret)', "'fixture-secret'")
        .replaceAll('@js($intent->id)', "'fixture-intent'")
        .replace('@js($customerSessionSecret)', 'null')
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
try {
    for (const name of (process.argv.slice(2).length ? process.argv.slice(2) : ['pay', 'billing-agreement'])) {
        const page = await browser.newPage();
        await page.route('**/*', route => route.fulfill({ contentType: 'text/javascript', body: '' }));
        page.setDefaultTimeout(5000);
        await page.setContent('<html><body></body></html>');
        await page.evaluate(() => {
            window.failure = 'network';
            window.confirms = 0;
            window.fetch = async () => {
                if (failure === 'network') throw new TypeError('Failed to fetch');
                return { ok: failure !== 'consent', async json() { return {message: 'Could not record payment preferences. Refresh the invoice.', status: 'requires_action', client_secret: 'fixture-secret'}; } };
            };
            window.$wire = { on() {} };
            window.Alpine = { data: (_, factory) => { window.setupFactory = factory; } };
            window.Stripe = () => ({
                elements: (options) => { window.elementOptions = options; return { update() {}, async submit() {
                    if (failure === 'updates') return { error: { type: 'api_connection_error' } };
                    return {};
                }, async fetchUpdates() {
                    if (failure === 'updates') return { error: { type: 'api_connection_error', message: 'Raw update detail' } };
                    return {};
                }, create() {
                    const handlers = {};
                    return { on: (name, fn) => { handlers[name] = fn; }, mount() { handlers.ready(); }, destroy() {} };
                } }; },
                async createConfirmationToken() {
                    if (failure === 'confirmation') throw new TypeError('Failed to tokenize');
                    if (failure === 'decline') return {error: {type: 'card_error', message: 'Your card was declined.'}};
                    return {confirmationToken: {id: 'ctoken_fixture'}};
                },
                async handleNextAction(options) { if (window.onAction) await window.onAction(options); return confirm(); },
                async confirmSetup() { return confirm(); },
            });
            function confirm() {
                confirms++;
                if (failure === 'confirmation') throw new TypeError('NetworkError when attempting to fetch resource.');
                if (failure === 'action-success') return {};
                return { error: { type: 'card_error', message: 'Your card was declined.' } };
            }
        });
        await page.setContent(render(name));
        if (name === 'pay') {
            await page.waitForFunction(() => !!document.querySelector('script[src]'));
            await page.waitForFunction(() => document.querySelector('#submit')?.disabled === false);
            const options = await page.evaluate(() => elementOptions);
            assert.equal(options.mode, 'payment');
            assert.equal(options.amount, 2000);
            assert.equal(options.currency, 'usd');
            assert.equal(options.clientSecret, undefined);
            assert.deepEqual(options.excludedPaymentMethodTypes, ['blik', 'acss_debit', 'customer_balance']);
        } else {
            await page.evaluate(async () => {
                window.component = setupFactory({ publishableKey: 'fixture-key', clientSecret: 'fixture-secret', intent: 'fixture-intent', consentUrl: '/consent', returnUrl: '/return', csrf: 'fixture-csrf' });
                component.$el = document.querySelector('form');
                await component.init();
            });
            await page.locator('#setup-consent').check();
        }
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
        if (name === 'pay') {
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
            await page.locator('#submit').click();
            await page.waitForURL('https://example.com/return');
            assert.deepEqual(actions, [{clientSecret: 'fixture-secret'}]);
            assert.deepEqual(requests, [
                {intent: 'fixture-intent', confirmation_token: 'ctoken_fixture', amount: 2000, save: false},
                {intent: 'fixture-intent', confirmation_token: null, amount: 2000, save: false},
            ], 'Authentication reads the same payment without replaying its token before redirecting');
        }
        await page.close();
    }
    console.log('Pay and setup network failures, retry and card declines passed.');
} finally {
    await browser.close();
}
