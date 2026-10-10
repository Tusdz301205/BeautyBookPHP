import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
const browser = await chromium.launch({channel:'chrome',headless:true});
try {
 const page = await browser.newPage();
 const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('response',r=> { if(r.url().includes('/api/v1/')) console.log(r.status(),r.url()); });
 await page.goto('http://127.0.0.1:8000/beautybook',{waitUntil:'networkidle',timeout:180000});
 console.log('HOME',await page.title(),(await page.locator('body').innerText()).slice(0,2000));
 await page.goto('http://127.0.0.1:8000/beautybook/login',{waitUntil:'networkidle'});
 await page.locator('#login-email').fill('customer@beautybook.local');
 await page.locator('#login-password').fill('BeautyBook123!');
 const responsePromise=page.waitForResponse(r=>r.url().endsWith('/api/v1/auth/login'),{timeout:180000});
 await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();
 const loginResponse=await responsePromise;
 console.log('LOGIN_RESPONSE',loginResponse.status(),(await loginResponse.text()).slice(0,2200));
 await page.waitForTimeout(1000);
 console.log('LOGIN',page.url(),(await page.locator('body').innerText()).slice(0,1500));
 console.log('ERRORS',JSON.stringify(errors));
} finally { await browser.close(); }
