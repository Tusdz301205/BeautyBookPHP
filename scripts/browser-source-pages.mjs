import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
import fs from 'node:fs/promises';

const base=process.env.APP_URL||'http://127.0.0.1:8000';
const prefix=process.env.SOURCE_PREFIX??'/beautybook';
const selectedRoles=process.env.BROWSER_ROLES?.split(',');
const detailsOnly=process.env.DETAILS_ONLY==='1';
const evidenceName=process.env.EVIDENCE_NAME||'browser-pages';
const out=process.env.EVIDENCE_DIRECTORY||'docs/course/evidence/2026-10-07/source-parity';
await fs.mkdir(out,{recursive:true});
const screens={
 guest:['/','/explore','/for-business','/login','/register','/register/business','/forgot-password'],
 customer:['/customer/appointments','/customer/notifications','/customer/vouchers','/customer/reviews','/customer/benefits','/customer/profile','/customer/security','/customer/privacy','/book'],
 owner:['/salon','/salon/services','/salon/combos','/salon/appointments','/salon/staff','/salon/branches/new','/salon/promotions','/salon/reviews','/salon/stats','/salon/profile','/salon/payments','/salon/operations','/salon/audit','/salon/account','/salon/security','/salon/incoming-ownership'],
 receptionist:['/salon/appointments','/salon/staff','/salon/payments','/salon/operations','/salon/account','/salon/security'],
 staff:['/salon/appointments','/salon/account','/salon/security'],
 admin:['/admin','/admin/salons','/admin/users','/admin/appointments','/admin/payments','/admin/reports','/admin/reviews','/admin/ownership','/admin/audit','/admin/notifications','/admin/settings','/admin/security','/admin/profile'],
};
const retiredRoutes = {
 owner: { '/salon/stats': '/salon', '/salon/audit': '/salon', '/salon/incoming-ownership': '/salon' },
 admin: { '/admin/ownership': '/admin' },
};
const browser=await chromium.launch({channel:'chrome',headless:true});
const results=[],errors=[],requests=[],navigationCancellations=[],semanticChecks=[];
const snapshot=()=>({results,errors,requests,navigationCancellations,semanticChecks});
const dynamic=[];
let inventoryBranchId;
for(const [endpoint,route] of [['branches','branches'],['services','services'],['staff/public','staff']]) {
 const parameters=new URLSearchParams({limit:'2'});
 if(endpoint==='staff/public'){if(!inventoryBranchId)throw new Error('No real branch for staff inventory');parameters.set('branchId',inventoryBranchId);}
 const response=await fetch(base+'/api/v1/'+endpoint+'?'+parameters.toString());
 if(!response.ok)throw new Error('Detail inventory '+endpoint+' '+response.status);
 const data=await response.json(); const rows=Array.isArray(data)?data:data.data || [];
 if(endpoint==='branches')inventoryBranchId=rows[0]?.id;
 for(const row of rows.slice(0,2))dynamic.push('/explore/'+route+'/'+row.id);
}
screens.guest.push(...dynamic);
try{
 for(const [role,paths] of Object.entries(screens)){
  if(selectedRoles&&!selectedRoles.includes(role))continue;
  if(detailsOnly)paths.splice(0,paths.length);
  const context=await browser.newContext({viewport:{width:1440,height:900}});
  const page=await context.newPage();
  const pending=new Map(); let currentPath='login',documentEpoch=0,accessToken;
  const cdp=await context.newCDPSession(page);await cdp.send('Page.enable');
  cdp.on('Page.frameNavigated',({frame})=>{
   if(frame.parentId)return;
   documentEpoch++;
   for(const [request,row] of pending)if(row.documentEpoch<documentEpoch){
    row.navigationAbandonedAt=Date.now();row.navigationTarget=frame.url;row.timing=request.timing();
    navigationCancellations.push({role,url:row.url,path:row.path,documentEpoch:row.documentEpoch,target:frame.url,at:row.navigationAbandonedAt,timing:row.timing});
   }
  });
  page.on('pageerror',e=>errors.push({role,path:currentPath,kind:'javascript',message:e.message}));
  page.on('request',r=>{if(r.url().includes('/api/v1/')&&!r.url().includes('/realtime/')){const row={role,path:currentPath,url:r.url(),documentEpoch,startedAt:Date.now()};requests.push(row);pending.set(r,row);}});
  page.on('requestfinished',r=>{const row=pending.get(r);if(row){row.finishedAt=Date.now();row.timing=r.timing();}pending.delete(r);});
  page.on('requestfailed',r=>{const row=pending.get(r);if(row){row.failedAt=Date.now();row.failure=r.failure()?.errorText;row.timing=r.timing();}pending.delete(r);});
  page.on('response',async r=>{if(r.url().includes('/api/v1/')&&!r.url().includes('/auth/refresh')&&r.status()>=400){errors.push({role,path:currentPath,kind:'api',status:r.status(),url:r.url(),message:(await r.text().catch(()=>'' )).slice(0,500)});}});
  page.on('response',async response=>{if(response.url().endsWith('/api/v1/auth/refresh')&&response.ok())accessToken=(await response.json()).accessToken;});
  async function settle(){const until=Date.now()+180000;let stable=0;const current=()=>[...pending.values()].filter(row=>row.documentEpoch===documentEpoch);while(Date.now()<until){if(!current().length)stable++;else stable=0;if(stable>=3)return;await page.waitForTimeout(500);}const failure={role,path:currentPath,kind:'timeout',pending:current().map(row=>row.url)};errors.push(failure);throw new Error(JSON.stringify(failure));}
  if(role!=='guest'){
   await page.goto(base+prefix+'/login',{waitUntil:'domcontentloaded',timeout:180000});
   await page.locator('#login-email').waitFor({timeout:180000});
   await page.locator('#login-email').fill(role+'@beautybook.local');
   await page.locator('#login-password').fill('BeautyBook123!');
   async function submitLogin(){const response=page.waitForResponse(r=>r.url().endsWith('/api/v1/auth/login'),{timeout:180000});await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();return response;}
   let result=await submitLogin();
   if(result.status()===429){await page.waitForTimeout((Number(result.headers()['retry-after']||60)+1)*1000);result=await submitLogin();}
   if(result.status()!==200)throw new Error(role+' login '+result.status());
   const login=await result.json();
   accessToken=login.accessToken;
   await settle();
   const directory=async(endpoint)=>{const response=await context.request.get(base+'/api/v1/'+endpoint,{headers:{Authorization:'Bearer '+accessToken,Accept:'application/json'}});if(!response.ok())throw new Error(role+' directory '+endpoint+' '+response.status());const body=await response.json();return Array.isArray(body)?body:body.data||[];};
   if(role==='admin')for(const [endpoint,route] of [['users?limit=2','users'],['admin/business-directory?limit=2','businesses'],['admin/branch-directory?limit=2','branches']]){const rows=await directory(endpoint);if(!rows.length)throw new Error('Missing real '+route+' inventory');for(const row of rows.slice(0,2))paths.push('/admin/'+route+'/'+row.id);}
   if(role==='owner'){const rows=await directory('staff');if(!rows.length)throw new Error('Missing real owner staff inventory');for(const row of rows.slice(0,2))paths.push('/salon/staff/'+row.id);}
  }
  for(const route of paths){
   currentPath=route;
   const response=await page.goto(base+prefix+route,{waitUntil:'domcontentloaded',timeout:180000});
   await page.locator('h1').first().waitFor({timeout:180000});
   await settle();
   const text=await page.locator('body').innerText();
   if(role==='admin'&&route.startsWith('/admin/businesses/')){
    const detail=await context.request.get(base+'/api/v1/business/'+route.split('/').pop()+'/detail',{headers:{Authorization:'Bearer '+accessToken,Accept:'application/json'}});
    if(!detail.ok())throw new Error('Business semantic inspection '+detail.status());
    const data=await detail.json();const owner=data.owner?.user||data.owner;
    const check={path:route,ownerName:owner?.fullName,summary:data.summary,members:data.members?.length,catalog:data.serviceCatalog?.length,ownerRendered:!!owner?.fullName&&text.includes(owner.fullName)};
    semanticChecks.push(check);
    if(!check.ownerRendered||!data.summary||!Array.isArray(data.members)||!Array.isArray(data.serviceCatalog))errors.push({role,path:route,kind:'source-contract',message:'Missing source business owner/summary/members/serviceCatalog contract',...check});
   }
   const actualPath=new URL(page.url()).pathname.replace(/\/$/,'');
   const expectedPath=(prefix+(retiredRoutes[role]?.[route] || route)).replace(/\/$/,'');
   if(actualPath!==expectedPath)errors.push({role,path:route,kind:'redirect',actual:actualPath,expected:expectedPath});
   if (role === 'owner') for (const name of ['Nhật ký thao tác', 'Báo cáo doanh nghiệp', 'Lời mời chuyển chủ']) {
    if (await page.getByRole('link', { name, exact: true }).count()) errors.push({role,path:route,kind:'retired-navigation',name});
   }
   if (role === 'admin' && await page.getByRole('link', {name: 'Chuyển quyền sở hữu',exact: true}).count()) errors.push({role,path:route,kind:'retired-navigation',name:'Chuyển quyền sở hữu'});
   await page.locator('img').evaluateAll(images=>images.forEach(image=>{image.loading='eager';}));
   await page.waitForFunction(()=>[...document.images].every(image=>image.complete),{},{timeout:15000}).catch(()=>{});
   const images=await page.locator('img').evaluateAll(rows=>rows.map(image=>({src:image.currentSrc||image.src,alt:image.alt,complete:image.complete,width:image.naturalWidth,height:image.naturalHeight})).filter(image=>image.src));
   for(const image of images.filter(image=>!image.complete||image.width===0))errors.push({role,path:route,kind:'image',...image});
   const record={role,path:route,status:response.status(),url:page.url(),heading:await page.locator('h1').first().innerText(),text:text.slice(0,2400),images};
   results.push(record);console.log(role,route,record.status,record.heading);
   if(route===paths[0]){
    await page.screenshot({path:`${out}/${role}-desktop.png`,fullPage:false});
    await page.setViewportSize({width:375,height:900});
    await page.screenshot({path:`${out}/${role}-mobile.png`,fullPage:false});
    await page.setViewportSize({width:1440,height:900});
   }
   if(route.startsWith('/explore/')&&role==='guest')await page.screenshot({path:`${out}/detail-${route.replaceAll('/','-')}.png`,fullPage:false});
   await fs.writeFile(out+'/'+evidenceName+'.json',JSON.stringify(snapshot(),null,2));
  }
  await context.close();
 }
 console.log('RESULT',JSON.stringify({screens:results.length,errors}));
}finally{await fs.writeFile(out+'/'+evidenceName+'.json',JSON.stringify(snapshot(),null,2));await browser.close();}
