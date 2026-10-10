import fs from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
const browser=await chromium.launch({channel:'chrome',headless:true});
const context=await browser.newContext(); const page=await context.newPage();
const base=process.env.APP_URL || 'http://localhost:8000';
const role=process.env.DIAGNOSTIC_ROLE || 'staff';
const rows=[]; const objects=new Map(); let stage='login';
const report={requests:rows,stages:[],pageErrors:[],chromiumNetwork:[]};
const cdp=await context.newCDPSession(page);await cdp.send('Network.enable');
const network=new Map();
cdp.on('Network.requestWillBeSent',event=>{
  if(!event.request.url.includes('/api/v1/'))return;
  const row={requestId:event.requestId,url:event.request.url,documentURL:event.documentURL,started:Date.now()};
  network.set(event.requestId,row);report.chromiumNetwork.push(row);
});
cdp.on('Network.responseReceived',event=>{const row=network.get(event.requestId);if(row)row.status=event.response.status;});
cdp.on('Network.loadingFinished',event=>{const row=network.get(event.requestId);if(row){row.finished=Date.now();row.bytes=event.encodedDataLength;}});
cdp.on('Network.loadingFailed',event=>{const row=network.get(event.requestId);if(row){row.failed=Date.now();row.error=event.errorText;row.cancelled=event.canceled;}});
page.on('pageerror',error=>report.pageErrors.push({stage,message:error.message}));
page.on('request',request=>{
  if(!request.url().includes('/api/v1/')) return;
  const row={id:rows.length,stage,url:request.url(),method:request.method(),started:Date.now()};
  rows.push(row); objects.set(request,row);
});
page.on('response',response=>{
  const row=objects.get(response.request()); if(!row) return;
  row.status=response.status(); row.responseAt=Date.now();
  response.finished().then(error=>{row.finishedByResponseAt=Date.now();row.responseFinishError=error?.message || null;});
  response.body().then(body=>{row.bodyReadAt=Date.now();row.bodyBytes=body.length;}).catch(error=>{row.bodyError=error.message;});
});
page.on('requestfinished',request=>{const row=objects.get(request);if(row)row.finishedAt=Date.now();});
page.on('requestfailed',request=>{const row=objects.get(request);if(row){row.failedAt=Date.now();row.failure=request.failure()?.errorText;}});
async function settle(seconds) {
  const end=Date.now()+seconds*1000;let stable=0;
  while(Date.now()<end) {
    const pending=rows.filter(row=>!row.finishedAt&&!row.failedAt&&!row.url.includes('/realtime/'));
    if(!pending.length)stable++;else stable=0;
    if(stable>=3)break;await page.waitForTimeout(500);
  }
  for(const [request,row] of objects)row.timing=request.timing();
  const pending=rows.filter(row=>!row.finishedAt&&!row.failedAt&&!row.url.includes('/realtime/'));
  const state={stage,url:page.url(),pending:pending.map(row=>({id:row.id,url:row.url,age:Date.now()-row.started,status:row.status,responseFinishError:row.responseFinishError,bodyRead:Boolean(row.bodyReadAt),timing:row.timing})),text:(await page.locator('body').innerText()).slice(-1500)};
  report.stages.push(state);console.log(JSON.stringify(state));
}
try {
  await page.goto(base+'/beautybook/login',{waitUntil:'domcontentloaded'});
  await page.locator('#login-email').fill(role+'@beautybook.local');await page.locator('#login-password').fill('BeautyBook123!');
  const login=page.waitForResponse(r=>r.url().endsWith('/api/v1/auth/login'));
  await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();const response=await login;
  if(!response.ok())throw new Error('STAFF login '+response.status()+': '+await response.text());
  await page.waitForURL(url=>!url.pathname.endsWith('/login'));if(!process.env.DIAGNOSTIC_EARLY_NAV)await settle(30);
  for(const path of role==='customer'?['/customer/profile','/book','/customer/security']:['/salon/appointments','/salon/account','/salon/security']) {
    stage=path;await page.goto(base+'/beautybook'+path,{waitUntil:'domcontentloaded',timeout:60000});
    await page.locator('h1').first().waitFor();await settle(path.endsWith('appointments')?40:20);
  }
} finally {
  await context.close(); await page.waitForTimeout(100).catch(()=>{});
  await fs.mkdir('docs/course/evidence/2026-10-07/source-parity',{recursive:true});
  await fs.writeFile('docs/course/evidence/2026-10-07/source-parity/'+role+'-request-diagnostic'+(process.env.DIAGNOSTIC_EARLY_NAV?'-early-nav':'')+'.json',JSON.stringify(report,null,2));
  await browser.close();
}
