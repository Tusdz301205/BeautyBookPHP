const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage();
page.setDefaultNavigationTimeout(60000);
const results=[];
try {
    await page.goto('http://127.0.0.1:8000/login');
    await page.locator('[name=email]').fill('customer@beautybook.local');
    await page.locator('[name=password]').fill('BeautyBook123!');
    await Promise.all([page.waitForURL(url=>!url.pathname.endsWith('/login')),page.getByRole('button',{name:'Đăng nhập',exact:true}).click()]);
    const response=await page.goto('http://127.0.0.1:8000/profile');
    assert.equal(response.status(),200);
    await page.locator('[name=phone]').fill('0901234567');
    await page.locator('[name=date_of_birth]').fill('2000-01-01');
    await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Lưu hồ sơ',exact:true}).click()]);
    assert.equal(await page.locator('[name=phone]').inputValue(),'0901234567');
    assert.equal(await page.locator('[name=date_of_birth]').inputValue(),'2000-01-01');
    results.push('Customer profile form persisted phone and birth date');
    for(const width of [390,768,1440]) {
        await page.setViewportSize({width,height:900});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
        await page.screenshot({path:`docs/course/evidence/2026-10-07/profile-${width}.png`,fullPage:true});
        results.push(`Profile responsive ${width}`);
    }
    for(const url of ['/finance','/privacy','/waitlist','/recurring','/reviews','/favorites']) {
        const response=await page.goto(`http://127.0.0.1:8000${url}`,{waitUntil:'domcontentloaded'});
        assert.equal(response.status(),200);
        await page.setViewportSize({width:390,height:900});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${url} mobile overflow`);
        await page.screenshot({path:`docs/course/evidence/2026-10-07/customer${url.replaceAll('/','-')}-390.png`,fullPage:true});
        results.push(`${url} mobile responsive`);
    }
    await fs.writeFile('docs/course/evidence/2026-10-07/profile-browser-results.json',JSON.stringify(results,null,2));
    console.log('PASS: profile persistence, 3 profile viewports and 6 module mobile checks');
} finally {await browser.close();}
