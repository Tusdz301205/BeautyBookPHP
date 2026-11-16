// Readonly checks on the running demo; no bank/withdrawal/collection commands.
import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const root='storage/app/private/owner-finance-qa',base='http://localhost:8000/beautybook',browser=await chromium.launch({channel:'chrome',headless:true}),report={pages:[],errors:[],responses:[]};
let currentPage; await fs.mkdir(root,{recursive:true});
try {
  for (const [role,email,path,title] of [['owner','owner.damoc@beautybook.vn','/salon/payments','Số dư và rút tiền'],['reception','letan.tandinh@beautybook.vn','/salon/payments','Thu tiền tại quầy'],['admin','admin@beautybook.vn','/admin/payments','Giao dịch & hoàn tiền']]) {
    const context=await browser.newContext({viewport:{width:1440,height:960},reducedMotion:'reduce'}),page=await context.newPage();
    currentPage=page; page.on('pageerror',e=>report.errors.push({role,error:e.message}));
    page.on('response',r=>{if(r.url().includes('/api/'))report.responses.push({role,url:r.url(),method:r.request().method(),status:r.status()});});
    await page.goto(base+'/login');await page.locator('#login-email').fill(email);await page.locator('#login-password').fill('BeautyBook123!');
    let auth;for(let attempt=0;attempt<3;attempt++){[auth]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/auth/login')&&r.request().method()==='POST'),page.getByRole('button',{name:'Đăng nhập',exact:true}).click()]);if(auth.status()!==429)break;console.log('Respecting login rate limit for '+role);await new Promise(resolve=>setTimeout(resolve,Math.min(60000,Math.max(1000,Number(auth.headers()['retry-after']||60)*1000+250))));}assert.equal(auth.status(),200);await page.waitForURL(u=>!u.pathname.endsWith('/login'));
    const navigation=await page.goto(base+path);assert.equal(navigation.status(),200);await page.getByRole('heading',{name:title,exact:true}).waitFor();await page.waitForLoadState('networkidle');
    if(role==='owner'){
      await page.getByRole('form',{name:'Yêu cầu rút tiền',exact:true}).waitFor();
      assert.equal(await page.getByRole('tablist').count(),0);
      assert.equal(await page.getByRole('heading',{name:'Vận hành tài chính',exact:true}).count(),0);
      assert.equal(await page.getByRole('textbox',{name:/^Mã lịch hẹn/}).count(),0);
      const collectionRequests=report.responses.filter(r=>r.role==='owner'&&/\/payments(?:\?|$)|\/financial-ledger/.test(r.url));assert.deepEqual(collectionRequests,[]);
      const tools=page.locator('.bb-finance-disclosure > summary').filter({hasText:'Chi tiết và công cụ'});
      await tools.focus();await page.keyboard.press('Enter');
      const extra=page.locator('.bb-finance-disclosure').filter({has:page.getByText('Thu tiền và giao dịch',{exact:true})}).last();
      await extra.locator('summary').focus();await page.keyboard.press('Enter');await extra.getByRole('textbox',{name:/^Mã lịch hẹn/}).waitFor();
      await extra.locator('summary').focus();await page.keyboard.press('Enter');await extra.getByRole('textbox',{name:/^Mã lịch hẹn/}).waitFor({state:'hidden'});
      await tools.focus();await page.keyboard.press('Enter');
    }
    for(const width of [1440,320,375,414,768]){
      await page.setViewportSize({width,height:960});await page.evaluate(()=>document.fonts.ready);
      const view=await page.evaluate(()=>({width:innerWidth,documentWidth:document.documentElement.scrollWidth,broken:[...document.images].filter(i=>i.getBoundingClientRect().width&&i.complete&&!i.naturalWidth).map(i=>i.src),unnamed:[...document.querySelectorAll('button')].filter(b=>b.getBoundingClientRect().width&&!b.textContent.trim()&&!b.getAttribute('aria-label')&&!b.title).length}));
      assert.ok(view.documentWidth<=width+2,role+' horizontal overflow at '+width);assert.deepEqual(view.broken,[]);assert.equal(view.unnamed,0);report.pages.push({role,path,...view});
      if(role==='owner'&&[1440,375].includes(width)){await page.locator('h1').click();await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:`${root}/owner-after-${width}.png`,fullPage:true});}
    }
    await context.close();
  }
  assert.deepEqual(report.errors,[]);
  report.passed=true;console.log('PASS owner/reception/admin: 15 viewport samples, lazy collection and keyboard disclosure.');
}catch(error){await currentPage?.screenshot({path:root+'/visual-failure.png',fullPage:true}).catch(()=>{});await fs.writeFile(root+'/visual-failure.txt',await currentPage.locator('body').innerText());report.failure=error.stack;console.error(error.stack);process.exitCode=1;}finally{await fs.writeFile(root+'/visual-report.json',JSON.stringify(report,null,2));await browser.close();}
