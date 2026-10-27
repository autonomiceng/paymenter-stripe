// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Run against Paymenter's installed Livewire runtime, with all network I/O stubbed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert(process.env.LIVEWIRE_SCRIPT, 'Set LIVEWIRE_SCRIPT to Paymenter vendor/livewire/livewire/dist/livewire.js');
const livewire = readFileSync(process.env.LIVEWIRE_SCRIPT, 'utf8');
const root = fileURLToPath(new URL('../', import.meta.url));
const theme = readFileSync(`${root}extension/AeStripe/resources/views/stripe-theme.blade.php`, 'utf8');
let view = readFileSync(`${root}extension/AeStripe/resources/views/billing-agreement.blade.php`, 'utf8')
    .replace("@include('gateways.ae-stripe::stripe-theme')", theme)
    .replace(/@js\(route\('([^']+)'\)\)/g, (_, name) => `'/${name}'`)
    .replace(/@js\(csrf_token\(\)\)/g, "'fixture-csrf'")
    .replace(/@js\(\$stripePublishableKey\)/g, "'fixture-key'")
    .replace(/@js\(\$intent->client_secret\)/g, "'fixture-secret'")
    .replace(/@js\(\$intent->id\)/g, "'fixture-intent'")
    .replace(/\{\{ \\Illuminate\\Support\\Js::from\(auth\(\)->user\(\)->name\) \}\}/g, "'Test'")
    .replace(/\{\{ \\Illuminate\\Support\\Js::from\(auth\(\)->user\(\)->email\) \}\}/g, "'user@example.test'");
const script = view.match(/@script\s*(<script>[\s\S]*?<\/script>)\s*@endscript/);
assert(script, 'The production setup view supplies a Livewire script');
view = view.replace(script[0], '');
assert(!/@js|\{\{|@include/.test(view + script[1]), 'Every Blade expression is rendered');

const snapshot = JSON.stringify({ data: {}, memo: { id: 'setup', name: 'client.payment-methods', children: [], errors: [], locale: 'en' }, checksum: 'offline' });
const escape = (text) => text.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
const render = (open) => `<div wire:id="setup" wire:snapshot="${escape(snapshot)}" wire:effects="{}">
    <button wire:click="createBillingAgreement">Add payment method</button>
    ${open ? `<div x-data="{open: true}"><template x-teleport="body"><section x-show="open" role="dialog">
        <button wire:click="cancelSetup">Close</button>${view}</section></template></div>` : ''}</div>`;

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    const errors = [];
    let releaseStripe;
    let holdStripe = false;
    let rejectStripe = false;
    let omitStripe = false;
    let holdConsent = false;
    const pendingConsent = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.addInitScript(() => {
        window.probe = { created: 0, destroyed: 0, active: 0, confirms: 0, consent: 0, scripts: 0, failConsent: false, themeListeners: 0 };
        const add = MediaQueryList.prototype.addEventListener;
        const remove = MediaQueryList.prototype.removeEventListener;
        MediaQueryList.prototype.addEventListener = function (type, ...args) { if (type === 'change') probe.themeListeners++; return add.call(this, type, ...args); };
        MediaQueryList.prototype.removeEventListener = function (type, ...args) { if (type === 'change') probe.themeListeners--; return remove.call(this, type, ...args); };
        window.installStripeStub = () => { window.Stripe = () => ({
            elements: () => {
                probe.created++;
                probe.active++;
                return { update() {}, create() {
                    let target;
                    const listeners = {};
                    return {
                        on(name, callback) { listeners[name] = callback; },
                        mount(container) {
                            target = typeof container === 'string' ? document.querySelector(container) : container;
                            assertTarget(target);
                            target.innerHTML = '<input aria-label="Card number" value="4242" />';
                            listeners.ready?.();
                        },
                        destroy() { probe.active--; probe.destroyed++; target?.replaceChildren(); },
                    };
                } };
            },
            async confirmSetup() { probe.confirms++; return { error: { message: 'Test decline. Try again.' } }; },
        }); };
        function assertTarget(target) { if (!target?.isConnected) throw new Error('Stripe mounted into a detached form'); }
    });
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.hostname === 'js.stripe.com') {
            await page.evaluate(() => probe.scripts++);
            if (holdStripe) await new Promise((resolve) => { releaseStripe = resolve; });
            if (rejectStripe) return route.abort('failed');
            return route.fulfill({ contentType: 'text/javascript', body: omitStripe ? '' : 'window.installStripeStub();' });
        }
        assert.equal(url.hostname, 'setup.test', 'No external network calls');
        if (url.pathname === '/livewire.js') return route.fulfill({ contentType: 'text/javascript', body: livewire });
        if (url.pathname === '/livewire/update') {
            const body = route.request().postDataJSON();
            const components = body.components.map((component) => {
                const open = component.calls.some((call) => call.method === 'createBillingAgreement');
                return { snapshot, effects: { html: render(open), returns: [null], ...(open ? { scripts: { 'setup-script': script[1] } } : {}) } };
            });
            return route.fulfill({ json: { components, assets: [] } });
        }
        if (url.pathname.endsWith('setup-consent')) {
            assert.deepEqual(route.request().postDataJSON(), { intent: 'fixture-intent', consent: true });
            const ok = await page.evaluate(() => { probe.consent++; return !probe.failConsent; });
            if (holdConsent) await new Promise((resolve) => pendingConsent.push(resolve));
            return route.fulfill({ status: ok ? 200 : 503, json: { ok } });
        }
        assert.equal(url.pathname, '/', 'Unexpected request');
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html class="dark"><head><meta name="csrf-token" content="offline"></head><body>
            ${render(false)}<script src="/livewire.js" data-csrf="offline" data-update-uri="/livewire/update" defer></script></body></html>` });
    });
    await page.goto('http://setup.test/');
    for (let cycle = 1; cycle <= 6; cycle++) {
        await page.getByRole('button', { name: 'Add payment method' }).click();
        await page.getByRole('dialog').waitFor();
        try {
            await page.getByRole('textbox', { name: 'Card number' }).waitFor({ timeout: 2500 });
        } catch (error) {
            throw new Error(`cycle ${cycle}: missing Stripe form; browser errors: ${JSON.stringify(errors)}`, { cause: error });
        }
        assert.equal(await page.getByRole('textbox', { name: 'Card number' }).count(), 1, `cycle ${cycle}: exactly one form`);
        await page.locator('#setup-consent').check();
        if (cycle === 1) {
            await page.evaluate(() => { probe.failConsent = true; });
            await page.getByRole('button', { name: 'Authorize' }).click();
            await page.getByRole('alert').filter({ hasText: 'Could not record your agreement' }).waitFor();
            assert.equal(await page.evaluate(() => probe.confirms), 0, 'Failed consent cannot reach confirmation');
            await page.evaluate(() => { probe.failConsent = false; });
            holdConsent = true;
            await page.evaluate(() => {
                const form = document.getElementById('payment-form');
                form.requestSubmit();
                form.requestSubmit();
            });
            await page.waitForFunction(() => probe.consent >= 2);
            holdConsent = false;
            pendingConsent.splice(0).forEach((resolve) => resolve());
        } else {
            await page.getByRole('button', { name: 'Authorize' }).click();
        }
        await page.getByRole('alert').filter({ hasText: 'Test decline' }).waitFor();
        assert.equal(await page.evaluate(() => probe.confirms), cycle, `cycle ${cycle}: exactly one submit handler`);
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        await page.getByRole('dialog').waitFor({ state: 'detached' });
    }
    assert.deepEqual(errors, [], 'No browser errors');
    const counts = await page.evaluate(() => probe);
    assert.equal(counts.active, 0, 'Closing disposes every Element');
    assert.equal(counts.created, counts.destroyed, 'No leaked Elements');
    assert.equal(counts.themeListeners, 0, 'No leaked theme listeners');
    assert.equal(counts.scripts, 1, 'Stripe.js loads once across reopen cycles');
    assert.equal(counts.consent, 7, 'Repeated submits during a consent request are ignored');
    // A fresh request can take longer than the bounded wait for a reused tag.
    holdStripe = true;
    await page.goto('http://setup.test/');
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.waitForFunction(() => probe.scripts === 1);
    await page.waitForTimeout(5500);
    assert.equal(await page.getByRole('alert').filter({ hasText: 'Could not load the payment form' }).count(), 0, 'A fresh Stripe.js request is still loading after five seconds');
    assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 1, 'The fresh pending script is retained');
    assert.equal(await page.getByRole('button', { name: 'Authorize' }).isDisabled(), true);
    releaseStripe();
    holdStripe = false;
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    assert.equal(await page.getByRole('checkbox', { name: /I agree/ }).isEnabled(), true, 'The delayed fresh load enables consent');
    assert.equal(await page.evaluate(() => probe.scripts), 1, 'The delayed fresh load succeeds without another request');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active + probe.themeListeners), 0);
    // Close before Stripe.js finishes, then reopen while the original load is pending.
    holdStripe = true;
    await page.goto('http://setup.test/');
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.waitForFunction(() => probe.scripts === 1);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('dialog').waitFor();
    releaseStripe();
    holdStripe = false;
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    assert.equal(await page.evaluate(() => probe.created), 1, 'Late Stripe.js load skips the removed form');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active), 0);
    // A failed script load must not poison future modal opens.
    rejectStripe = true;
    await page.goto('http://setup.test/');
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('alert').filter({ hasText: 'Could not load the payment form' }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Authorize' }).isDisabled(), true);
    assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 0, 'A fresh script that errors is removed');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    rejectStripe = false;
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active + probe.themeListeners), 0);
    // A successful script response without Stripe must also allow a fresh retry.
    omitStripe = true;
    await page.goto('http://setup.test/');
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('alert').filter({ hasText: 'Could not load the payment form' }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Authorize' }).isDisabled(), true);
    assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 0, 'A script with no Stripe global is removed');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    omitStripe = false;
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    assert.equal(await page.evaluate(() => probe.scripts), 2, 'Missing global clears the cached promise for a new script load');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active + probe.themeListeners), 0);
    // Another caller may already have appended a still-loading Stripe.js tag.
    holdStripe = true;
    await page.goto('http://setup.test/');
    await page.evaluate(() => {
        const existing = document.createElement('script');
        existing.src = 'https://js.stripe.com/v3/';
        existing.onload = () => { probe.existingLoaded = true; };
        document.body.appendChild(existing);
    });
    await page.waitForFunction(() => probe.scripts === 1);
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('dialog').waitFor();
    assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 1, 'Pending Stripe.js tag is reused');
    releaseStripe();
    holdStripe = false;
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    assert.equal(await page.evaluate(() => probe.scripts), 1, 'Reusing a pending tag makes no second request');
    assert.equal(await page.evaluate(() => probe.existingLoaded), true, 'Reusing a tag preserves its load handler');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active + probe.themeListeners), 0);
    // A reused pending tag remains owned by its caller after timeout and on retry.
    holdStripe = true;
    await page.goto('http://setup.test/');
    await page.evaluate(() => {
        const existing = document.createElement('script');
        existing.src = 'https://js.stripe.com/v3/';
        existing.onload = () => { probe.existingLoaded = true; };
        document.body.appendChild(existing);
    });
    await page.waitForFunction(() => probe.scripts === 1);
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('alert').filter({ hasText: 'Could not load the payment form' }).waitFor({timeout: 10000});
    assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 1, 'Timeout preserves the pending script owned by another caller');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('dialog').waitFor();
    releaseStripe();
    holdStripe = false;
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    assert.equal(await page.evaluate(() => probe.scripts), 1, 'Retry reuses the pending foreign tag without a second Stripe.js request');
    assert.equal(await page.evaluate(() => probe.existingLoaded), true, 'Its owner can finish loading after our timeout and retry');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active + probe.themeListeners), 0);
    // Known limit: a foreign tag that already failed needs its owner to remove or repair it.
    omitStripe = true;
    await page.goto('http://setup.test/');
    await page.evaluate(() => new Promise((resolve, reject) => {
        const existing = document.createElement('script');
        existing.src = 'https://js.stripe.com/v3/';
        existing.onload = resolve;
        existing.onerror = reject;
        document.body.appendChild(existing);
    }));
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('alert').filter({ hasText: 'Could not load the payment form' }).waitFor({timeout: 10000});
    assert.equal(await page.getByRole('button', { name: 'Authorize' }).isDisabled(), true);
    assert.equal(await page.locator('script[src^="https://js.stripe.com/v3"]').count(), 1, 'An already-finished foreign script is preserved after a bounded wait');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    // The caller that created the unusable tag removes it before setup retries.
    await page.evaluate(() => document.querySelector('script[src^="https://js.stripe.com/v3"]').remove());
    omitStripe = false;
    await page.getByRole('button', { name: 'Add payment method' }).click();
    await page.getByRole('textbox', { name: 'Card number' }).waitFor();
    assert.equal(await page.evaluate(() => probe.scripts), 2, 'Owner removal of the unusable foreign tag allows a fresh successful retry');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('dialog').waitFor({ state: 'detached' });
    assert.equal(await page.evaluate(() => probe.active + probe.themeListeners), 0);
    assert.deepEqual(errors, [], 'No browser errors across delayed and failed loads');
    console.log(`PASS: 6 open/decline/close cycles; ${counts.confirms} confirmations; ${counts.destroyed} Elements disposed`);
    console.log('PASS: failed consent retry; fresh script loads after five seconds; delayed script close/reopen; failed script removal and retry; missing-global retry; existing script reuse; foreign pending tag timeout/retry; owner repairs already-finished foreign tag; no leaked theme listeners');
} finally {
    await browser.close();
}
