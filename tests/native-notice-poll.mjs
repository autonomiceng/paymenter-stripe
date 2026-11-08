// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Real Livewire timers and native rendered responses; all browser HTTP is intercepted.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const browser = await chromium.launch({headless: true});
try {
    const context = await browser.newContext();
    const requests = [], errors = [];
    await context.addInitScript(() => {
        window.notices = [];
        window.addEventListener('notify', event => window.notices.push(event.detail));
    });
    await context.route('**/*', async route => {
        const url = new URL(route.request().url());
        assert.equal(url.origin, 'http://stripe-tests.invalid', 'No external browser HTTP');
        if (url.pathname === '/livewire/livewire.js') return route.fulfill({contentType: 'text/javascript', body: readFileSync(fixture.javascript, 'utf8')});
        if (url.pathname === '/livewire/update') {
            const body = route.request().postDataJSON();
            assert.equal(body.components.length, 1);
            const component = body.components[0];
            assert.deepEqual(component.calls.map(call => call.method), ['$refresh'], 'A real automatic notice poll triggers refresh');
            const snapshot = JSON.parse(component.snapshot);
            assert.equal(snapshot.memo.id, JSON.parse(fixture.responses[0].components[0].snapshot).memo.id, 'Only the original invoice component polls');
            requests.push(body);
            assert(requests.length <= 2, 'Polling stops after terminal delivery');
            return route.fulfill({contentType: 'application/json', body: JSON.stringify(fixture.responses[requests.length - 1])});
        }
        assert(['/', '/other'].includes(url.pathname), 'Only local fixture routes are allowed');
        return route.fulfill({contentType: 'text/html', body: '<!doctype html><html><body>' + (url.pathname === '/other' ? fixture.other : fixture.html)
            + '<script src="/livewire/livewire.js" data-csrf="fixture" data-update-uri="/livewire/update"></script></body></html>'});
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message + '\n' + error.stack));
    await page.clock.install();
    await page.goto('http://stripe-tests.invalid/');
    await page.waitForFunction(() => window.Livewire?.all().length === 1);
    assert(await page.locator('[data-ae-stripe-notice-poll]').evaluate(el => Boolean(el.closest('[wire\\:id]'))), 'The poll belongs to the real component despite comments and quoted root attributes');
    await page.clock.runFor(5500);
    await page.waitForFunction(() => window.notices.length === 1);
    await page.locator('[wire\\:poll\\.60s]').waitFor({state: 'attached'});
    assert.equal(requests.length, 1, 'The browser, without manual refresh, sends the first poll');
    assert.match(JSON.stringify(await page.evaluate(() => window.notices)), /delayed/);
    await page.clock.runFor(61000);
    await page.waitForFunction(() => window.notices.length === 2);
    const notices = await page.evaluate(() => window.notices);
    assert.match(JSON.stringify(notices[1]), /success/);
    assert.equal(await page.locator('[data-ae-stripe-notice-poll]').count(), 0, 'Terminal delivery removes the timer');
    await page.clock.runFor(61000);
    assert.equal(requests.length, 2);
    const other = await context.newPage();
    other.on('pageerror', error => errors.push(error.message + '\n' + error.stack));
    await other.clock.install(); await other.goto('http://stripe-tests.invalid/other');
    await other.waitForFunction(() => window.Livewire?.all().length === 1);
    await other.clock.runFor(61000);
    assert.equal(await other.locator('[data-ae-stripe-notice-poll]').count(), 0);
    assert.deepEqual(await other.evaluate(() => window.notices), [], 'Other tabs receive no confirmation');
    assert.equal(requests.length, 2); assert.deepEqual(errors, []);
    console.log('PASS: automatic native notice polling, delayed recovery, original tab, once-only delivery and shutdown');
} finally { await browser.close(); }
