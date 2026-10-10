const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';

const base = process.env.APP_URL || 'http://127.0.0.1:8000';
const output = 'docs/course/evidence/2026-10-07/ui-parity';
await fs.mkdir(output,{recursive:true});
const browser = await chromium.launch({channel:'chrome',headless:true});
const results = [], errors = [];
const widths = [320,375,414,768,1440];
async function visit(page,role,path) {
    const response = await page.goto(new URL(path,base).href,{waitUntil:'domcontentloaded',timeout:60000});
    assert.equal(response.status(),200,`${role} ${path}`);
    results.push({role,path,status:response.status()});
}
async function responsive(page,role,label) {
    for (const width of widths) {
        await page.setViewportSize({width,height:900});
        await page.evaluate(()=>document.fonts.ready);
        const overflow = await page.evaluate(()=> {
            const main=document.querySelector('#main-content');
            return [...main.querySelectorAll('section,article,form,input,select,textarea,button,h1,h2,.panel,.card')].filter(element=> {
                if (!element.getClientRects().length || element.closest('[hidden],.table-scroll')) return false;
                const rect=element.getBoundingClientRect();
                return rect.right>innerWidth+2 || rect.left < -2;
            }).map(element=>({tag:element.tagName,class:element.className}));
        });
        assert.deepEqual(overflow,[],`${role} ${label} content bounds ${width}`);
        if (width<1024) {
            await page.getByRole('button',{name:'Mở menu',exact:true}).click();
            assert.equal(await page.locator('[data-nav-open]').getAttribute('aria-expanded'),'true');
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('[data-nav-open]').getAttribute('aria-expanded'),'false');
        }
        await page.screenshot({path:`${output}/${role}-${label}-${width}.png`,fullPage:true});
        results.push({role,page:label,width,bounds:'pass',drawer:width<1024?'pass':'desktop'});
    }
}
try {
    const guest=await browser.newPage({viewport:{width:1440,height:900}});
    guest.on('pageerror',error=>errors.push(`guest: ${error.message}`));
    await visit(guest,'guest','/');
    await responsive(guest,'guest','home');
    await visit(guest,'guest','/explore');
    const branchUrl=await guest.locator('a[href*="/explore/"]').first().getAttribute('href');
    await guest.close();
    for(const role of ['customer','owner','receptionist','staff','admin']) {
        const context=await browser.newContext({viewport:{width:1440,height:900}});
        const page=await context.newPage();
        page.on('pageerror',error=>errors.push(`${role}: ${error.message}`));
        await visit(page,role,'/login');
        await page.locator('[name=email]').fill(`${role}@beautybook.local`);
        await page.locator('[name=password]').fill('BeautyBook123!');
        await Promise.all([page.waitForURL(url=>url.pathname!=='/login'),page.getByRole('button',{name:'Đăng nhập',exact:true}).click()]);
        if(role!=='customer') {
            await visit(page,role,'/workspace');
            await Promise.all([page.waitForURL(url=>url.pathname!=='/workspace'),page.locator('form.workspace-option').first().getByRole('button').click()]);
        }
        await visit(page,role,'/app');
        const links=[...new Set(await page.locator('.bb-side-nav a').evaluateAll(elements=>elements.map(element=>element.href)))];
        for(const link of links) await visit(page,role,link);
        await visit(page,role,'/app');
        await responsive(page,role,'dashboard');
        await visit(page,role,'/bookings');
        await responsive(page,role,'bookings');
        await visit(page,role,'/bookings/calendar?view=week');
        await responsive(page,role,'calendar-week');
        await visit(page,role,'/bookings/calendar?view=day');
        assert.equal(await page.locator('.calendar-day').count(),1);
        results.push({role,calendar:'day/week navigation passed'});
        if(role==='customer') {
            await visit(page,role,'/finance');
            await responsive(page,role,'finance');
            await visit(page,role,branchUrl);
            const bookingUrl=await page.locator('a[href*="/booking/"]').first().getAttribute('href');
            await visit(page,role,bookingUrl);
            await responsive(page,role,'booking-service');
            await page.setViewportSize({width:1440,height:900});
            const firstService = page.locator('[data-select-offering]').first();
            if (await firstService.getAttribute('aria-pressed') !== 'true') await firstService.click();
            await page.locator('[data-booking-next]:enabled').waitFor({timeout:60000});
            await page.locator('[data-booking-next]').click();
            await page.locator('[data-select-staff]:visible').first().click();
            await page.locator('[data-booking-next]').click();
            const day=new Date(Date.now()+2*86400000);
            while(new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Ho_Chi_Minh',weekday:'long'}).format(day)==='Sunday') day.setUTCDate(day.getUTCDate()+1);
            const parts=Object.fromEntries(new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Ho_Chi_Minh',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(day).map(part=>[part.type,part.value]));
            await page.locator('[data-booking-day]').fill(`${parts.year}-${parts.month}-${parts.day}`);
            await page.locator('[data-slot]').first().waitFor({timeout:60000});
            await page.locator('[data-slot]').first().click();
            await page.locator('[data-booking-next]:enabled').waitFor({timeout:60000});
            await page.locator('[data-booking-next]').click();
            await page.locator('[data-booking-next]').click();
            await page.locator('[data-confirm-booking]:enabled').waitFor({timeout:60000});
            assert.equal(await page.locator('[data-confirm-booking]').isEnabled(),true);
            await responsive(page,role,'booking-confirm');
            results.push({role,wizard:'all five steps and authoritative slot to confirmation passed',submitted:false});
        }
        await context.close();
    }
    assert.deepEqual(errors,[]);
    await fs.writeFile(`${output}/results.json`,JSON.stringify({results,errors},null,2));
    console.log(`PASS: ${results.length} UI parity checks; no JS errors`);
} catch(error) {
    await fs.writeFile(`${output}/failure.json`,JSON.stringify({message:error.message,results,errors},null,2));
    throw error;
} finally { await browser.close(); }
