import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
const browser=await chromium.launch({channel:'chrome',headless:true});
const base=process.env.APP_URL || 'http://localhost:8000';
const prefix=process.env.SOURCE_PREFIX ?? '/beautybook';
const out='docs/course/evidence/2026-10-07/source-parity/responsive';
await fs.mkdir(out,{recursive:true});
const results=process.env.RESPONSIVE_ROLES ? JSON.parse(await fs.readFile(`${out}/results.json`,'utf8')).filter(row=>!process.env.RESPONSIVE_ROLES.split(',').includes(row.role)) : [];
try {
  for(const [role,routes] of Object.entries({guest:['/explore'],customer:['/customer/profile','/book'],owner:['/salon'],receptionist:['/salon/appointments'],staff:['/salon/appointments'],admin:['/admin']})) {
    if(process.env.RESPONSIVE_ROLES && !process.env.RESPONSIVE_ROLES.split(',').includes(role)) continue;
    const context=await browser.newContext(); const page=await context.newPage(); page.setDefaultTimeout(60000);
    const errors=[]; page.on('pageerror',error=>errors.push(error.message));
    const pending=new Set();
    page.on('request',request=>{if(request.url().includes('/api/v1/')&&!request.url().includes('/realtime/'))pending.add(request);});
    page.on('requestfinished',request=>pending.delete(request));page.on('requestfailed',request=>pending.delete(request));
    async function settle() {
      const end=Date.now()+90000;let stable=0;
      while(Date.now()<end) {
        stable=pending.size ? 0 : stable+1;if(stable>=3)return;
        await page.waitForTimeout(500);
      }
      throw new Error(role+' API requests did not finish: '+[...pending].map(request=>request.url()).join(', '));
    }
    if(role!=='guest') {
      await page.goto(base+prefix+'/login'); await page.locator('#login-email').fill(role+'@beautybook.local');
      await page.locator('#login-password').fill('BeautyBook123!');
      async function submitLogin() {
        const response=page.waitForResponse(r=>r.url().endsWith('/api/v1/auth/login'));
        await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();return response;
      }
      let response=await submitLogin();
      if(response.status()===429) {
        const retryAfter=Number(response.headers()['retry-after'] || 60);
        await page.waitForTimeout((Math.max(1,retryAfter)+1)*1000);response=await submitLogin();
      }
      assert.equal(response.status(),200,role+' login: '+(response.ok()?'OK':await response.text()));
      await page.waitForURL(url=>!url.pathname.endsWith('/login'));
      await settle();
    }
    for(const route of routes) {
      await page.goto(base+prefix+route); await page.locator('h1').first().waitFor();
      await settle();
      for(const width of [320,375,414,768,1440]) {
        await page.setViewportSize({width,height:900}); await page.waitForTimeout(150);
        const bounds=await page.evaluate(()=>{
          const y=scrollY; scrollTo(9999,y); const horizontalScroll=scrollX; scrollTo(0,y);
          return {viewport:innerWidth,document:document.documentElement.scrollWidth,body:document.body.scrollWidth,horizontalScroll,fonts:document.fonts.status};
        });
        const record={role,route,width,...bounds,errors:[...errors]}; results.push(record);
        await page.screenshot({path:`${out}/${role}-${route.replaceAll('/','-')}-${width}.png`,fullPage:false});
        console.log(JSON.stringify(record));
      }
    }
    await context.close();
  }
} finally {
  await fs.writeFile(`${out}/results.json`,JSON.stringify(results,null,2)); await browser.close();
}
assert.equal(results.filter(row=>row.document>row.viewport+1||row.horizontalScroll!==0||row.errors.length).length,0,'Responsive document overflow, horizontal page scrolling or JavaScript error; see evidence results');
