// Read-only UI regression: hold/reorder API responses; never submit a booking.
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
page.setDefaultTimeout(60000);
const held = new Map(), errors = [], quoteRequests = [], slotRequests = [];
let mockPricing = process.env.BOOKING_REAL_API !== '1', selectedSlot = null;
const base = 'http://127.0.0.1:8000';
let bookingPath = process.env.BOOKING_PATH;
const appointmentDay = new Date(Date.now() + 172800000).toISOString().slice(0, 10);
const source = (await fs.readFile('resources/js/app.js', 'utf8')).replace(/^import '\.\/modules\.js';\s*/u, '');
const quote = (amount, items = []) => ({ data: { items: items.map(item => ({ ...item, service_name: `Dịch vụ ${item.branch_service_offering_id}`, unit_price: 100000, duration_minutes: 60 })), currency: 'VND', final_amount: amount, duration_minutes: 60 * items.length } });
const fulfill = (route, payload, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(payload) });
page.on('pageerror', error => errors.push(error.message));
try {
    await page.route('**/build/assets/app-*.js', route => route.fulfill({ contentType: 'text/javascript', body: source }));
    await page.route('**/booking/*/cart/quote', async route => {
        const body = route.request().postDataJSON(); quoteRequests.push(body);
        if (!mockPricing) return route.continue();
        const voucher = body.voucher_code;
        if (voucher) held.set(voucher, route);
        else await fulfill(route, quote(200000, body.items));
    });
    await page.route('**/booking/*/cart/slots?*', route => {
        slotRequests.push(new URL(route.request().url()));
        if (!mockPricing) return route.continue();
        const value = selectedSlot || `${appointmentDay}T14:00`;
        return fulfill(route, { slots: [{ value, label: value.split('T')[1], end: '15:00', staff_assignments: [] }] });
    });
    await page.goto(`${base}/login`);
    await page.locator('[name=email]').fill('customer@beautybook.local');
    await page.locator('[name=password]').fill('BeautyBook123!');
    await Promise.all([page.waitForURL(url => url.pathname !== '/login'), page.getByRole('button', { name: 'Đăng nhập', exact: true }).click()]);
    if (!bookingPath) {
        await page.goto(`${base}/explore`);
        const branch = await page.locator('a[href*="/explore/"]').first().getAttribute('href');
        assert.ok(branch, 'A public branch exists');
        await page.goto(new URL(branch, base).href);
        const booking = await page.locator('a[href*="/booking/"]').first().getAttribute('href');
        assert.ok(booking, 'Branch exposes an authenticated booking link');
        bookingPath = new URL(booking, base).pathname;
    }
    await page.goto(base + bookingPath);
    const offering = await page.locator('[data-select-offering]').first().getAttribute('data-select-offering');
    assert.ok(offering, 'An eligible service exists');
    await page.goto(`${base}${bookingPath}?offering=${offering}`);
    await page.waitForFunction(() => !document.querySelector('[data-booking-next]').disabled);
    assert.equal(await page.locator(`[data-select-offering="${offering}"]`).getAttribute('aria-pressed'), 'true', 'URL preset selects an eligible service');
    const second = await page.locator('[data-select-offering]').nth(1).getAttribute('data-select-offering');
    assert.ok(second, 'A second eligible service exists');
    await page.locator(`[data-select-offering="${second}"]`).click();
    await page.waitForFunction(() => !document.querySelector('[data-booking-next]').disabled);
    assert.equal(await page.locator('[data-selected-items] > div').count(), 2, 'Two distinct services are selected');
    assert.deepEqual(quoteRequests.at(-1).items.map(item => String(item.branch_service_offering_id)), [offering, second], 'Quote preserves selection order');
    await page.locator(`[data-cart-move="${second}"][data-direction="-1"]`).click();
    await page.waitForFunction(() => !document.querySelector('[data-booking-next]').disabled);
    assert.deepEqual(quoteRequests.at(-1).items.map(item => String(item.branch_service_offering_id)), [second, offering], 'Reordering changes the authoritative quote payload');
    assert.equal(await page.evaluate(() => document.activeElement?.dataset.cartVariant || document.activeElement?.dataset.cartMove), second, 'Reordered cart control retains keyboard focus');
    await page.locator('[data-booking-next]').click();
    assert.equal(await page.locator('[data-select-staff=""]').getAttribute('aria-pressed'), 'true', 'AUTO is selected by default');
    await page.locator('[data-booking-next]').click();
    await page.locator('[data-booking-day]').fill(appointmentDay);
    selectedSlot = await page.locator('[data-slot]').first().getAttribute('data-slot');
    await page.locator('[data-slot]').first().click();
    await page.waitForFunction(() => !document.querySelector('[data-booking-next]').disabled);
    await page.locator('[data-booking-next]').click();
    assert.equal(slotRequests.at(-1).searchParams.has('staff_profile_id'), false, 'AUTO does not submit a fake provider ID');
    assert.equal(slotRequests.at(-1).searchParams.get('items[0][branch_service_offering_id]'), second, 'Time-slot query preserves cart order');
    mockPricing = true;
    const changeVoucher = async value => {
        await page.locator('[name=voucher_code]').evaluate((input, nextValue) => {
            input.value = nextValue;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
    };
    const waitHeld = async value => {
        for (let tries = 0; tries < 100 && !held.has(value); tries++) await new Promise(resolve => setTimeout(resolve, 10));
        assert.ok(held.has(value), `${value} quote request was held`);
    };
    await changeVoucher('A'); await waitHeld('A');
    assert.equal(await page.locator('[data-booking-next]').isDisabled(), true, 'Information Next stays disabled during quote');
    await changeVoucher('B'); await waitHeld('B');
    await fulfill(held.get('B'), quote(120000, quoteRequests.at(-1).items));
    await page.waitForFunction(() => !document.querySelector('[data-booking-next]').disabled);
    await page.locator('[data-booking-next]').click();
    assert.match(await page.locator('[data-confirm-details]').innerText(), /120[.\s]000/u, 'Confirmation uses the latest quote');
    await fulfill(held.get('A'), quote(75000, quoteRequests.at(-1).items));
    await page.waitForTimeout(100);
    assert.match(await page.locator('[data-confirm-details]').innerText(), /120[.\s]000/u, 'Late old response cannot replace the quote');
    await changeVoucher('C'); await waitHeld('C');
    assert.equal(await page.locator('[data-confirm-booking]').isDisabled(), true, 'Confirm remains disabled while its price is changing');
    await fulfill(held.get('C'), quote(110000, quoteRequests.at(-1).items));
    await page.waitForFunction(() => !document.querySelector('[data-confirm-booking]').disabled);
    assert.match(await page.locator('[data-confirm-details]').innerText(), /110[.\s]000/u, 'A new quote rebuilds the confirmation summary');
    await changeVoucher('INVALID'); await waitHeld('INVALID');
    await fulfill(held.get('INVALID'), { message: 'Mã ưu đãi không hợp lệ.' }, 422);
    await page.waitForFunction(() => document.querySelector('[data-booking-feedback]').textContent.includes('không hợp lệ'));
    assert.equal(await page.locator('[data-confirm-booking]').isDisabled(), true, 'Rejected quote cannot be submitted');
    assert.deepEqual(errors, [], 'No browser runtime errors');
    console.log(`PASS: eligible URL preset; ordered two-service cart/reordering; AUTO default/slot payload; pending quote gating; stale response rejection; confirmation price refresh; invalid quote blocked; no browser errors. Initial quote/slots: ${process.env.BOOKING_REAL_API === '1' ? 'actual demo API' : 'intercepted'}. Price-race responses intercepted; no booking written.`);
} finally {
    await browser.close();
}
