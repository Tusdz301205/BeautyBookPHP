// Read existing evidence only; never runs tests or changes application data.
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const root='storage/app/private/full-qa';
const read=async path=>JSON.parse(await fs.readFile(path,'utf8'));
const backend=await read(root+'/backend-final.json');
const management=await read(root+'/management-identity-consolidated.json');
const customer=await read(root+'/customer-report.json');
const finance=await read(root+'/finance-report.json');
const discovery=await read(root+'/discovery-moderation.json');
const operations=await read(root+'/operations-report.json');
const ai=await read(root+'/ai-read.json');
const wallet=await read('storage/app/private/booking-qa/wallet-report.json');
const owner=await read(root+'/onboarding/browser-report.json');
const branch=await read(root+'/onboarding/branch-report.json');
const visual=await read('storage/app/private/fe-review/full-retest-stable.json');
const staff=await read('storage/app/private/fe-review/staff.json');
const summaries={customer:customer.summary,finance:{passed:finance.checks.length,failed:Number(!!finance.failure)},discovery:discovery.summary,operations:operations.summary,...management.suites,wallet:{passed:wallet.checks.length,failed:Number(!!wallet.failure)},ownerRegistration:{passed:owner.checks.length,failed:Number(!!owner.failure)},branchRegistration:{passed:branch.checks.length,failed:Number(!!branch.failure)},aiOwner:{passed:Number(ai.passed===true),failed:Number(ai.passed!==true)}};
assert.equal(backend.failed,0);
for(const [name,s]of Object.entries(summaries)){assert.equal(s.failed,0,name);assert.equal(s.blocked||0,0,name);}
for(const r of [visual,staff]){assert.ok(!r.failure,r.failure);assert.deepEqual(r.errors,[]);assert.deepEqual(r.apiErrors.filter(e=>!e.recovered),[]);}
const pages=[...visual.pages,...staff.pages],views=pages.flatMap(p=>p.views);
const issues=views.filter(v=>v.docWidth>v.width+2||v.unnamed.length||v.images.length||v.duplicateIds.length||v.overflow.some(o=>!o.cls.includes('gm-approach__halo')));
assert.equal(issues.length,0,'Visible layout/accessibility smoke issues');
assert.deepEqual(operations.unexpectedHttp,[]);assert.deepEqual(finance.errors,[]);assert.deepEqual(discovery.errors,[]);assert.deepEqual(wallet.badResponses,[]);
const report={generatedAt:new Date().toISOString(),backend:{total:backend.total,passed:backend.passed,failed:backend.failed,skipped:backend.skipped,assertions:backend.assertions},browserCheckpoints:summaries,browserCheckpointTotal:Object.values(summaries).reduce((n,s)=>n+s.passed,0),visual:{rolePageVisits:pages.length,viewportSamples:views.length,widths:[320,375,414,768,1440],unresolvedIssues:issues.length},boundaries:['Checkpoints include setup checks and signed fixture callbacks, not 107 independent end-to-end journeys.','Main application was only read; all business mutations used the isolated test database.','No live bank, SMTP, Google OAuth, Cloudflare R2 account or physical-device FCM delivery tested.','Rare UI workflows with API-only evidence are disclosed in docs/FULL_TEST_RESULTS_2026-11-14.md.']};
await fs.writeFile(root+'/summary.json',JSON.stringify(report,null,2));
console.log(JSON.stringify(report,null,2));
