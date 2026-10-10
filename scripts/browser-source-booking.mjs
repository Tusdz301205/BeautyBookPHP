import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
const base=process.env.APP_URL || 'http://localhost:8000';
const prefix=process.env.SOURCE_PREFIX ?? '/beautybook';
const browser=await chromium.launch({channel:'chrome',headless:true});
const context=await browser.newContext(); const page=await context.newPage();
page.setDefaultTimeout(60000);
const evidence={steps:[],quotes:[],availability:[],errors:[],authRecoveryResponses:[]};
const pendingRequests=new Set();
page.on('request',request=>{if(request.url().includes('/api/v1/')&&!request.url().includes('/realtime/'))pendingRequests.add(request);});
page.on('requestfinished',request=>pendingRequests.delete(request));
page.on('requestfailed',request=>pendingRequests.delete(request));
async function settle(){const end=Date.now()+90000;let stable=0;while(Date.now()<end){stable=pendingRequests.size?0:stable+1;if(stable===3)return;await page.waitForTimeout(500);}throw new Error('Same-document API timeout: '+[...pendingRequests].map(request=>request.url()).join(', '));}
page.on('pageerror',error=>evidence.errors.push(error.message));
page.on('response',async response=>{
  if(response.url().includes('/api/v1/')&&!response.url().includes('/auth/refresh')&&response.status()>=400){const row={url:response.url(),status:response.status(),body:(await response.text().catch(()=>'' )).slice(0,1000)};(response.status()===401?evidence.authRecoveryResponses:evidence.errors).push(row);}
  if(response.url().includes('/api/v1/bookings/available-slots') && response.ok()) evidence.availability.push(await response.json());
  if(/price-preview|\/pricing\/|preview-price/.test(response.url()) && response.ok()) evidence.quotes.push(await response.json());
});
let created,headers;
try {
  await page.goto(base+prefix+'/login');
  await page.locator('#login-email').fill('customer@beautybook.local');
  await page.locator('#login-password').fill('BeautyBook123!');
  await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();
  await page.waitForURL('**/customer/**');
  await settle();
  await page.goto(base+prefix+'/book');
  await page.getByRole('heading',{name:'Chọn chi nhánh và dịch vụ',exact:true}).waitFor();
  await settle();
  const branchSection=page.locator('section').filter({has:page.getByRole('heading',{name:'1. Chi nhánh',exact:true})});
  await branchSection.getByRole('button').first().waitFor();
  const preferred=branchSection.getByRole('button',{name:/Tân Tạo/});
  await (await preferred.count() ? preferred.first() : branchSection.getByRole('button').first()).click();
  const serviceSection=page.locator('section').filter({has:page.getByRole('heading',{name:/dịch vụ/i})}).last();
  await serviceSection.getByRole('button').first().click();
  for(const select of await page.locator('select').all()) {
    const value=await select.locator('option').evaluateAll(options=>options.find(option=>option.value)?.value);
    if(value) await select.selectOption(value);
  }
  evidence.steps.push('branch-and-service');
  await page.getByRole('button',{name:'Chọn chuyên viên',exact:true}).click();
  await page.getByRole('button',{name:/Bất kỳ chuyên viên phù hợp/}).click();
  await page.getByRole('button',{name:'Chọn thời gian',exact:true}).click();
  evidence.steps.push('automatic-staff');
  let selected=false;
  for(let day=1;day<7&&!selected;day++) {
    const response=page.waitForResponse(r=>r.url().includes('/bookings/available-slots'));
    await page.locator('.bb-booking-days button').nth(day).click(); const available=await response;
    if(available.status()===401){const recovered=await page.waitForResponse(r=>r.url()===available.url());assert.ok(recovered.ok(),(await recovered.text()).slice(0,1000));}else assert.ok(available.ok(),(await available.text()).slice(0,1000));
    const slots=page.getByRole('button',{name:/^\d{2}:\d{2}$/});
    await page.waitForTimeout(250);
    if(await slots.count()) { await slots.first().click(); selected=true; }
  }
  assert.ok(selected,'Actual available slot within next week');
  await page.getByRole('button',{name:'Nhập thông tin',exact:true}).click();
  evidence.steps.push('real-available-slot');
  const contactName='Kiểm thử Chrome BeautyBook '+Date.now();
  const form=page.locator('#bb-booking-contact-form');
  await form.locator('input').first().fill(contactName);
  await form.locator('input[type=tel]').fill('0901234567');
  await form.locator('textarea').fill('Kiểm thử giao diện mã nguồn mở; lịch được hủy sau kiểm tra.');
  await form.locator('input[type=checkbox]').check();
  await page.getByRole('button',{name:'Xem lại lịch hẹn',exact:true}).click();
  evidence.steps.push('contact-and-consent');
  const confirm=page.getByRole('button',{name:'Xác nhận đặt lịch',exact:true});
  await confirm.waitFor();
  const responsePromise=page.waitForResponse(r=>r.url().endsWith('/api/v1/bookings')&&r.request().method()==='POST');
  await confirm.click(); const response=await responsePromise;
  assert.ok(response.ok(),await response.text());
  created=await response.json();
  const originalHeaders=await response.request().allHeaders();
  headers=Object.fromEntries(['authorization','x-csrf-token','idempotency-key','accept'].map(key=>[key,originalHeaders[key]]));
  evidence.payload=response.request().postDataJSON();
  assert.equal(evidence.payload.customerName,contactName); assert.equal(evidence.payload.contactConsent,true);
  assert.ok(headers['idempotency-key']);
  await page.waitForURL('**/book/success');
  await page.getByText(created.bookingCode,{exact:false}).waitFor();
  evidence.steps.push('verified-success');
  const replay=await context.request.post(base+'/api/v1/bookings',{headers,data:evidence.payload});
  assert.ok(replay.ok(),await replay.text()); assert.equal((await replay.json()).id,created.id);
  const refreshedPromise=page.waitForResponse(r=>r.url().endsWith('/api/v1/auth/refresh')&&r.ok());
  await page.reload();
  const refreshedResponse=await refreshedPromise;
  headers.authorization='Bearer '+(await refreshedResponse.json()).accessToken;
  headers['x-csrf-token']=(await refreshedResponse.allHeaders())['x-csrf-token'] || headers['x-csrf-token'];
  const detail=await context.request.get(base+'/api/v1/bookings/'+created.id,{headers});
  assert.ok(detail.ok()); const stored=await detail.json();
  assert.equal(stored.customerName,contactName); assert.equal(stored.customerPhone,'0901234567');
  evidence.persistedContact=true; evidence.idempotencyReplay=true; evidence.reloadStableId=stored.id;
  assert.equal(evidence.errors.length,0,JSON.stringify(evidence.errors));
} catch(error) {
  evidence.failure=error.stack;evidence.failedUrl=page.url();evidence.failedPageText=await page.locator('body').innerText().catch(()=>null);
  await page.screenshot({path:'docs/course/evidence/2026-10-07/source-parity/booking-failed.png',fullPage:false}).catch(()=>{});
  throw error;
} finally {
  if(created?.id&&headers) {
    const cancel=await context.request.patch(base+'/api/v1/bookings/'+created.id+'/status',{headers,data:{status:'CANCELLED',note:'Hoàn tất kiểm thử Chrome mã nguồn mở.'}});
    evidence.cleanup={status:cancel.status(),bookingId:created.id};
  }
  await fs.mkdir('docs/course/evidence/2026-10-07/source-parity',{recursive:true});
  await fs.writeFile('docs/course/evidence/2026-10-07/source-parity/booking-wizard.json',JSON.stringify(evidence,null,2));
  await browser.close();
}
console.log(JSON.stringify({steps:evidence.steps,idempotencyReplay:evidence.idempotencyReplay,persistedContact:evidence.persistedContact,reloadStableId:evidence.reloadStableId,cleanup:evidence.cleanup}));
