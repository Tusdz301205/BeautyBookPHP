const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';

const base = 'http://127.0.0.1:8000';
const output = 'docs/course/evidence/2026-10-07';
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
const errors = [];
let appointmentDate = new Date(Date.now() + 86400000);
while (new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Ho_Chi_Minh',weekday:'long'}).format(appointmentDate) === 'Sunday') appointmentDate = new Date(appointmentDate.getTime()+86400000);
const dateParts = Object.fromEntries(new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Ho_Chi_Minh',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(appointmentDate).map(part=>[part.type,part.value]));
const appointmentDay = `${dateParts.year}-${dateParts.month}-${dateParts.day}`;
async function visit(page, role, url) {
    const response = await page.goto(base + url, {waitUntil:'domcontentloaded',timeout:60000});
    assert.equal(response.status(), 200, `${role} ${url}`);
    assert.ok(!(await page.locator('body').innerText()).includes('Internal Server Error'));
    results.push({role, url, status:response.status()});
}
try {
    const guest = await browser.newPage();
    await visit(guest, 'guest', '/explore');
    const branchPath = await guest.locator('a[href*="/explore/"]').first().getAttribute('href');
    assert.ok(branchPath, 'seeded branch');
    await guest.goto(branchPath);
    let bookingPath;
    await guest.close();
    for (const role of ['customer', 'owner', 'receptionist', 'staff', 'admin']) {
        const context = await browser.newContext({viewport:{width:1440,height:900}});
        const page = await context.newPage();
        page.setDefaultNavigationTimeout(60000);
        page.on('pageerror', error => errors.push(`${role}: ${error.message}`));
        await visit(page, role, '/login');
        await page.locator('[name=email]').fill(`${role}@beautybook.local`);
        await page.locator('[name=password]').fill('BeautyBook123!');
        await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.getByRole('button', {name:'Đăng nhập',exact:true}).click()]);
        if (role !== 'customer') {
            await visit(page, role, '/workspace');
            const form = page.locator('form.workspace-option').first();
            await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/workspace')), form.locator('button').click()]);
        }
        if (role === 'customer') {
            await page.goto(branchPath);
            bookingPath = new URL(await page.locator('a[href*="/booking/"]').first().getAttribute('href'),base).pathname;
        }
        const pages = ['/bookings','/notifications','/security'];
        if (role === 'customer') pages.push('/favorites','/reviews','/vouchers','/recurring','/privacy','/waitlist','/loyalty','/payments','/finance','/treatment-packages');
        if (role === 'owner') pages.push('/owner/businesses','/reviews','/payments','/finance','/ownership','/impacts','/dashboard','/reports/bookings','/audit-logs');
        if (role === 'receptionist') pages.push('/payments');
        if (role === 'admin') pages.push('/admin/users','/admin/trust','/dashboard','/admin/settings','/admin/business-directory','/admin/reviews','/admin/promotions','/payments','/finance','/ownership','/impacts');
        for (const url of pages) await visit(page, role, url);
        await visit(page, role, '/bookings');
        for (const width of [390,768,1440]) {
            await page.setViewportSize({width,height:900});
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            assert.equal(overflow, false, `${role} horizontal overflow ${width}`);
            await page.screenshot({path:`${output}/${role}-${width}.png`, fullPage:true});
        }
        if (role === 'receptionist') {
            await page.setViewportSize({width:1440,height:900});
            await visit(page, role, bookingPath);
            const offering = await page.locator('#booking-offering option').nth(1).getAttribute('value');
            await page.locator('#booking-offering').selectOption(offering);
            await page.waitForFunction(() => !document.querySelector('[data-quote-price]').textContent.includes('—'));
            const staff = await page.locator('#booking-staff option:not([hidden])').nth(1).getAttribute('value');
            await page.locator('#booking-staff').selectOption(staff);
            await page.locator('#booking-start').fill(`${appointmentDay}T11:00`);
            await page.locator('[data-check-availability]').click();
            assert.equal(await page.locator('#booking-contact-name').evaluate(el => el.validity.valueMissing), true);
            await page.locator('#booking-contact-name').fill('Browser acceptance customer');
            await page.locator('#booking-contact-phone').fill('0901234567');
            let delayed = false;
            await page.route('**/availability', async route => { delayed=true; await new Promise(resolve=>setTimeout(resolve,1200)); await route.continue(); });
            await page.locator('#booking-start').fill(`${appointmentDay}T03:00`);
            await page.locator('[data-check-availability]').click();
            await page.getByText('Đang kiểm tra khung giờ…',{exact:true}).waitFor();
            await page.screenshot({path:`${output}/operational-loading.png`,fullPage:true});
            await page.locator('[data-booking-feedback][data-state="error"]').waitFor();
            await page.screenshot({path:`${output}/operational-error.png`,fullPage:true});
            await page.unroute('**/availability');
            assert.equal(delayed,true);
            let available = false;
            for (const hour of ['11','13','15','17']) {
                await page.locator('#booking-start').fill(`${appointmentDay}T${hour}:00`);
                await page.locator('[data-check-availability]').click();
                await page.waitForFunction(() => !document.querySelector('[data-check-availability]').disabled);
                available = await page.locator('[data-confirm-booking]').isEnabled();
                if (available) break;
            }
            assert.equal(available,true,'real available operational slot');
            const persisted = page.waitForResponse(response => response.request().method()==='POST' && new URL(response.url()).pathname===bookingPath);
            await page.locator('[data-confirm-booking]').click();
            const response = await persisted;
            assert.equal(response.status(),201);
            const booking = await response.json();
            results.push({role,operationalBooking:booking});
            await page.screenshot({path:`${output}/operational-created.png`,fullPage:true});
        }
        await context.close();
    }
    assert.deepEqual(errors,[]);
} finally {
    await fs.writeFile(`${output}/browser-results.json`,JSON.stringify({results,errors},null,2));
    await browser.close();
}
console.log(`PASS: ${results.length} browser checks; ${output}`);
