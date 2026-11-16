/**
 * Standalone OWNER / ADMIN UI acceptance checks. No runner or new dependencies.
 * Run ONLY after the backend/DB suite releases the isolated QA database:
 *   $env:QA_BASE_URL='http://127.0.0.1:8013/beautybook'; node tests/Browser/management.mjs
 * Seed IdentityDemoSeeder (verified owner/admin/customer, BeautyBook123!), with an
 * ACTIVE owner branch. This script creates two services in BeautyBook Tân Tạo.
 * Optional QA_BRANCH_NAME selects another ACTIVE owner branch.
 * Invitations require QA_MAIL_MAILER=array/log AND matching safe server sink (clear
 * cached config before starting server). The former is an operator attestation;
 * it cannot prove the server config. Never set it for an SMTP-backed server.
 * Optional QA_STATUS_EMAIL: disposable ACTIVE CUSTOMER @beautybook.local, with no
 * bookings, assignments, services or businesses; status is restored in finally.
 * Optional QA_REVIEW_BUSINESS_NAME: disposable pending business whose name starts
 * QA; request-info consumes that pending fixture. No arbitrary records are touched.
 * Settings save/restore requires configured.freeCancellationHours === 4 because
 * the UI always writes this fixed key. Use an isolated fixture with that config.
 * Artifacts: storage/app/private/full-qa/management-<unique run>/{report.json,*.png}.
 * Exit 1 = failures/runtime errors; exit 2 = prerequisite blocks; 0 = all covered
 * checks passed. Not a claim of application-wide coverage. No browser/DB commands
 * are run by authoring this file. QA records intentionally remain for evidence.
 * --consolidate reads completed management/identity reports without launching a
 * browser and writes management-identity-consolidated.{json,md} under full-qa.
 */
import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
import assert from 'node:assert/strict';
import { mkdir, writeFile, readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const base = (process.env.QA_BASE_URL || 'http://127.0.0.1:8013/beautybook').replace(/\/$/, '');
const run = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
const out = path.join(root, 'storage/app/private/full-qa', `management-${run}`);
const branch = process.env.QA_BRANCH_NAME || 'BeautyBook Tân Tạo';
const serviceFixtureRun = process.env.QA_SERVICE_FIXTURE_RUN || run;
assert(/^\d{13}-[a-z0-9]{5}$/.test(serviceFixtureRun), 'Invalid unique QA service fixture run');
const names = { a: `QA ${serviceFixtureRun} Service A`, b: `QA ${serviceFixtureRun} Service B`, staff: `QA ${run} Staff`, combo: `QA ${run} Combo` };
const report = { run, base, branch, startedAt: new Date().toISOString(), checks: [], responses: [], apiErrors: [], pageErrors: [], consoleErrors: [], requestFailures: [], writes: [], artifacts: [], coverageLimit: 'OWNER and ADMIN management only; no financial transactions or external mail.' };
let browser, currentCheck = 'startup';
const pending = new Set();
const selectedCases = new Set((process.env.QA_MANAGEMENT_CASES || '').split(',').filter(Boolean));
const authenticatedRoles = new Set();
const createdServices = new Set();
class Prerequisite extends Error {}
const need = (condition, message) => { if (!condition) throw new Prerequisite(message); };
const re = text => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?:\\s*\\*?\\s*\\(bắt buộc\\))?$`);
// Field uses .bb-field; scoping also handles nested search inputs with broken
// label forwarding. No positional page-wide input/button selectors.
const pageOf = scope => typeof scope.page === 'function' ? scope.page() : scope;
const field = (scope, label) => scope.locator('.bb-field').filter({ has: pageOf(scope).locator('label').filter({ hasText: re(label) }) });
const input = (scope, label) => field(scope, label).locator('input:not([type=hidden]), textarea');
const button = (scope, name) => scope.getByRole('button', { name, exact: true });
const dialog = (page, name) => page.getByRole('dialog', { name, exact: true });
async function visible(locator) { await locator.waitFor({ state: 'visible' }); }
async function choose(scope, label, option) {
  await field(scope, label).getByRole('combobox').click();
  await pageOf(scope).getByRole('option', { name: option, exact: true }).click();
}
async function screenshot(page, name) {
  const file = `${String(report.artifacts.length + 1).padStart(3, '0')}-${name.replace(/[^a-z0-9-]/gi, '-')}.png`;
  await page.screenshot({ path: path.join(out, file), fullPage: true }); report.artifacts.push(file);
}
function observe(page, role) {
  page.on('pageerror', error => report.pageErrors.push({ role, check: currentCheck, message: error.message }));
  page.on('console', msg => { if (msg.type() === 'error') report.consoleErrors.push({ role, check: currentCheck, message: msg.text() }); });
  page.on('requestfailed', req => report.requestFailures.push({ role, check: currentCheck, url: req.url(), error: req.failure()?.errorText }));
  page.on('response', response => {
    const req = response.request();
    if (!['xhr', 'fetch'].includes(req.resourceType()) || new URL(response.url()).origin !== new URL(base).origin) return;
    const check = currentCheck;
    const task = (async () => {
      const entry = { sequence: report.responses.length + 1, at: new Date().toISOString(), role, check, method: req.method(), url: response.url(), status: response.status() };
      report.responses.push(entry);
      if (response.status() === 200) for (const previous of report.apiErrors) {
        if (previous.status === 401 && !previous.recovered && previous.role === role && previous.method === entry.method && previous.url === entry.url && previous.sequence < entry.sequence) Object.assign(previous, { recovered: true, recoveredBy: { sequence: entry.sequence, status: 200, at: entry.at } });
      }
      if (response.status() >= 400) report.apiErrors.push({ ...entry, expected: response.status() === 401 && /\/auth\/refresh$/.test(new URL(response.url()).pathname) && !authenticatedRoles.has(role), body: (await response.text().catch(() => '')).slice(0, 2500) });
      if (!['GET', 'HEAD', 'OPTIONS'].includes(req.method())) report.writes.push(entry);
    })();
    pending.add(task); task.finally(() => pending.delete(task));
  });
}
async function step(page, name, fn) {
  if (selectedCases.size && !selectedCases.has(name)) { report.checks.push({ name, status: 'not_run', reason: 'Excluded by QA_MANAGEMENT_CASES; no pass claimed.' }); return false; }
  currentCheck = name; const started = Date.now();
  const entry = { name, status: 'running' }; report.checks.push(entry);
  try { await fn(); entry.status = 'passed'; }
  catch (error) { entry.status = error instanceof Prerequisite ? 'blocked' : 'failed'; entry.error = error.stack || String(error); }
  finally {
    entry.ms = Date.now() - started;
    await screenshot(page, name).catch(error => { entry.screenshotError = error.message; });
    await writeFile(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`${entry.status.toUpperCase()} ${name}${entry.error ? `: ${entry.error.split('\n')[0]}` : ''}`);
  }
  return entry.status === 'passed';
}
async function writeUI(page, method, suffix, click) {
  await page.evaluate(() => {
    window.__managementObserver?.disconnect(); window.__managementMessages = [];
    window.__managementObserver = new MutationObserver(() => {
      for (const element of document.querySelectorAll('[role="status"], [role="alert"]')) {
        const text = element.textContent?.trim();
        if (text && !window.__managementMessages.includes(text)) window.__managementMessages.push(text);
      }
    });
    window.__managementObserver.observe(document.body, { subtree: true, childList: true, characterData: true });
  });
  const responsePromise = page.waitForResponse(r => r.request().method() === method && suffix.test(new URL(r.url()).pathname));
  // Attach immediately so a click failure cannot leave an unhandled rejection.
  responsePromise.catch(() => {});
  await click(); let response;
  try { response = await responsePromise; }
  catch (error) {
    const feedback = await page.evaluate(() => ({ messages: window.__managementMessages || [], invalid: [...document.querySelectorAll('form :invalid')].map(element => ({ type: element.type, message: element.validationMessage })) }));
    (report.uiFeedback ||= []).push({ check: currentCheck, method, ...feedback });
    error.stack += `\nObserved UI feedback: ${JSON.stringify(feedback)}`; throw error;
  }
  assert(response.ok(), `${method} ${response.url()}: ${response.status()} ${(await response.text()).slice(0, 1200)}`);
  return response.json().catch(() => null);
}
async function go(page, route, heading) {
  await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' });
  await visible(page.getByRole('heading', { name: heading, exact: true }));
}
async function loginUI(page, role) {
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Địa chỉ email', { exact: false }).fill(`${role}@beautybook.local`);
  await page.locator('input[autocomplete="current-password"]').fill('BeautyBook123!');
  await writeUI(page, 'POST', /\/auth\/login$|\/login$/, () => button(page, 'Đăng nhập').click());
  await page.waitForURL(url => url.pathname.includes(role === 'admin' ? '/admin' : '/salon'));
  // Complete the landing page's auth/bootstrap requests before a hard navigation.
  await page.waitForLoadState('networkidle');
  await visible(page.getByRole('heading', { level: 1 }));
  authenticatedRoles.add(role === 'admin' ? 'ADMIN' : 'OWNER');
}
async function waitValue(locator, value) {
  const element = await locator.elementHandle();
  await pageOf(locator).waitForFunction(({ element, value }) => element.value === value, { element, value });
}
function card(page, heading) {
  return page.locator('.bb-card').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
}
async function close(page, title) { await button(dialog(page, title).locator('header'), 'Đóng').click(); }
async function searchServices(page, name) { await page.getByPlaceholder('Tên dịch vụ', { exact: true }).fill(name); }
async function createService(page, name) {
  await go(page, '/salon/services', 'Dịch vụ');
  await button(page, 'Thêm dịch vụ').click(); const d = dialog(page, 'Thêm dịch vụ');
  await choose(d, 'Chi nhánh áp dụng đầu tiên', branch);
  await input(d, 'Tên dịch vụ').fill(name); await input(d, 'Mô tả').fill(`Acceptance ${run}`);
  await input(d, 'Giá cơ sở (VNĐ)').fill('150000'); await input(d, 'Thời lượng cơ sở (phút)').fill('30');
  await writeUI(page, 'POST', /\/services\/catalog$/, () => button(d, 'Thêm dịch vụ').click());
  await d.waitFor({ state: 'hidden' }); await searchServices(page, name); await visible(card(page, name));
  createdServices.add(name);
}
async function openService(page, name) {
  await go(page, '/salon/services', 'Dịch vụ'); await searchServices(page, name);
  await button(card(page, name), 'Chi tiết').click(); return dialog(page, name);
}
async function date(scope, label, key) {
  const control = input(scope, label);
  if (await control.isVisible()) { await control.fill(key); return; }
  await field(scope, label).locator('button[aria-haspopup="dialog"]').click();
  const calendar = scope.page().getByRole('dialog', { name: 'Chọn ngày', exact: true });
  await calendar.getByLabel('Năm', { exact: true }).selectOption(key.slice(0, 4));
  await calendar.getByLabel('Tháng', { exact: true }).selectOption(String(Number(key.slice(5, 7)) - 1));
  await calendar.locator('button[data-current-month="true"]').filter({ hasText: new RegExp(`^${Number(key.slice(8))}$`) }).click();
}
async function campaign(page, voucher, platform = false) {
  const route = platform ? '/admin/promotions' : '/salon/promotions';
  await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' });
  if (voucher) await page.getByRole('tab', { name: /^Voucher \(/ }).click();
  await button(page, voucher ? 'Tạo voucher' : platform ? 'Tạo chiến dịch' : 'Tạo khuyến mãi').click();
  let d = dialog(page, voucher ? 'Tạo voucher' : 'Tạo khuyến mãi');
  const name = `QA ${run} ${platform ? 'Platform' : 'Owner'} ${voucher ? 'Voucher' : 'Promotion'}`;
  const code = `QA${Date.now()}${voucher ? 'V' : 'P'}${platform ? 'A' : 'O'}`;
  await button(d, 'Lưu').click(); await visible(d.getByText('Nhập tên.', { exact: true }));
  await input(d, 'Tên').fill(name); await input(d, voucher ? 'Mã voucher' : 'Mã ưu đãi (không bắt buộc)').fill(code);
  await input(d, 'Giá trị').fill('10');
  await date(d, 'Bắt đầu', new Date(Date.now() - 86400000).toISOString().slice(0, 10));
  await date(d, 'Kết thúc', new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10));
  // Scope owner discounts to this run's service; never change unrelated pricing.
  if (!platform) await d.getByRole('group', { name: 'Dịch vụ áp dụng', exact: true }).getByLabel(names.a, { exact: true }).check();
  if (!voucher) await d.getByLabel('Tự động chọn ưu đãi tốt nhất', { exact: true }).uncheck();
  const endpoint = voucher ? 'vouchers' : 'promotions';
  await writeUI(page, 'POST', new RegExp(`/${endpoint}$`), () => button(d, 'Lưu').click());
  await d.waitFor({ state: 'hidden' });
  await page.getByPlaceholder('Tên hoặc mã ưu đãi').fill(code); await visible(card(page, name));
  await button(card(page, name), 'Sửa').click(); d = dialog(page, voucher ? 'Chỉnh sửa voucher' : 'Chỉnh sửa khuyến mãi');
  if (voucher) assert(await input(d, 'Mã voucher').isDisabled(), 'Issued voucher code must be immutable');
  await input(d, 'Tên').fill(`${name} Edited`);
  await writeUI(page, 'PATCH', new RegExp(`/${endpoint}/[^/]+$`), () => button(d, 'Lưu').click());
  await d.waitFor({ state: 'hidden' }); await visible(card(page, `${name} Edited`));
  await button(card(page, `${name} Edited`), 'Chi tiết').click(); await visible(dialog(page, `${name} Edited`)); await close(page, `${name} Edited`);
  if (voucher) {
    await button(card(page, `${name} Edited`), 'Cấp cho khách').click(); d = dialog(page, 'Cấp voucher cho khách');
    await input(d, 'Khách hàng').fill('customer@beautybook.local');
    await writeUI(page, 'POST', /\/vouchers\/[^/]+\/grant$/, () => button(d, 'Cấp voucher').click());
    await d.waitFor({ state: 'hidden' }); await visible(page.getByText('Đã cấp voucher cho khách hàng', { exact: true }));
  }
  await button(card(page, `${name} Edited`), voucher ? 'Thu hồi' : 'Xóa').click();
  d = dialog(page, voucher ? 'Thu hồi voucher?' : 'Xóa khuyến mãi?');
  await writeUI(page, 'DELETE', new RegExp(`/${endpoint}/[^/]+$`), () => button(d, 'Xác nhận').click());
  await d.waitFor({ state: 'hidden' });
  await page.reload({ waitUntil: 'domcontentloaded' });
  if (voucher) await page.getByRole('tab', { name: /^Voucher \(/ }).click();
  await page.getByPlaceholder('Tên hoặc mã ưu đãi').fill(code);
  // DELETE soft-deletes vouchers; manager index excludes deleted records.
  await card(page, `${name} Edited`).waitFor({ state: 'hidden' });
}

async function consolidateReports() {
  const qaRoot = path.join(root, 'storage/app/private/full-qa');
  const directories = await readdir(qaRoot, { withFileTypes: true });
  const sources = [];
  for (const directory of directories) {
    if (!directory.isDirectory() || !/^(management|identity)-\d{13}-[a-z0-9]+$/.test(directory.name)) continue;
    try {
      const value = JSON.parse(await readFile(path.join(qaRoot, directory.name, 'report.json'), 'utf8'));
      if (value.finishedAt && value.base === base) sources.push({ folder: directory.name, suite: directory.name.startsWith('management-') ? 'management' : 'identity', ...value });
    } catch { /* A directory without a completed parseable report is not evidence. */ }
  }
  sources.sort((a, b) => a.startedAt.localeCompare(b.startedAt));
  const cases = new Map(), infrastructure = [], httpErrors = [];
  for (const source of sources) {
    for (const check of source.checks) {
      if (check.name === 'unimplemented-management-surfaces' || ['not_run', 'running'].includes(check.status)) continue;
      if (check.name === 'fatal') { infrastructure.push({ report: `${source.folder}/report.json`, ...check }); continue; }
      const name = check.name === 'variant-create-toggle-and-branch-availability' ? 'variant-create-status-roundtrip' : check.name;
      const key = `${source.suite}:${name}`, previous = cases.get(key);
      const attempt = { report: `${source.folder}/report.json`, originalName: check.name, status: check.status, ...(check.error ? { error: check.error } : {}) };
      const history = [...(previous?.attempts || []), attempt];
      // A blocked rerun performed no new acceptance action. Preserve a verified
      // pass with its exact source, but retain the blocked attempt in history.
      if (check.status === 'blocked' && previous?.status === 'passed') { previous.attempts = history; continue; }
      cases.set(key, { suite: source.suite, name, status: check.status, evidence: attempt.report, attempts: history });
    }
    for (const error of source.apiErrors || []) {
      const success = (source.responses || []).find(candidate => candidate.status === 200 && candidate.sequence > error.sequence && candidate.method === error.method && (candidate.role || candidate.page) === (error.role || error.page) && (candidate.url || candidate.path) === (error.url || error.path));
      // Older reports contain writes but not successful reads. Only use an
      // actual later matching 200 write as proof; never infer a recovered GET.
      const writes = source.writes || [];
      const index = writes.findIndex(candidate => candidate.status === 401 && candidate.method === error.method && candidate.check === error.check && (candidate.role || candidate.page) === (error.role || error.page) && (candidate.url || candidate.path) === (error.url || error.path));
      const historicalSuccessIndex = index < 0 ? -1 : writes.findIndex((candidate, candidateIndex) => candidateIndex > index && candidate.status === 200 && candidate.method === error.method && (candidate.role || candidate.page) === (error.role || error.page) && (candidate.url || candidate.path) === (error.url || error.path));
      const recovered = error.status === 401 && Boolean(success || historicalSuccessIndex >= 0);
      httpErrors.push({ report: `${source.folder}/report.json`, check: error.check, context: error.role || error.page, method: error.method, path: error.path || new URL(error.url).pathname, status: error.status, classification: recovered ? 'recovered-with-matching-200' : error.expected ? 'expected-rejection-not-recovered' : 'unclassified-http-error', ...(recovered ? { evidence: success ? { responseSequence: success.sequence, status: 200 } : { writeIndex: historicalSuccessIndex, status: 200 } } : {}), ...(error.reason ? { reason: error.reason } : {}) });
    }
  }
  const allCases = [...cases.values()];
  const suites = Object.fromEntries(['management', 'identity'].map(suite => {
    const rows = allCases.filter(row => row.suite === suite);
    return [suite, { total: rows.length, passed: rows.filter(row => row.status === 'passed').length, failed: rows.filter(row => row.status === 'failed').length, blocked: rows.filter(row => row.status === 'blocked').length }];
  }));
  const consolidated = { generatedAt: new Date().toISOString(), base, policy: 'Latest executed outcome per canonical case; blocked reruns preserve earlier verified passes. Every attempt retained. Coverage placeholder excluded. 401 recovery requires an actual later matching 200 in the same context and report.', suites, cases: allCases, infrastructureAttempts: infrastructure, httpErrors, excludedCoverage: ['Media uploads for management, dependencies/price rules, combo duplicate, staff calendar/offboarding, taxonomy/moderation/ownership and other compliance decisions were not exercised by these scripts.'], pageErrors: sources.flatMap(source => (source.pageErrors || []).map(error => ({ report: `${source.folder}/report.json`, ...error }))) };
  await writeFile(path.join(qaRoot, 'management-identity-consolidated.json'), JSON.stringify(consolidated, null, 2));
  const lines = ['# Management and identity acceptance evidence', '', `Generated: ${consolidated.generatedAt}`, '', consolidated.policy, '', '| Suite | Passed | Failed | Blocked | Total |', '| --- | ---: | ---: | ---: | ---: |'];
  for (const [suite, counts] of Object.entries(suites)) lines.push(`| ${suite} | ${counts.passed} | ${counts.failed} | ${counts.blocked} | ${counts.total} |`);
  lines.push('', '| Suite | Case | Result | Verified evidence |', '| --- | --- | --- | --- |');
  for (const row of allCases) lines.push(`| ${row.suite} | ${row.name} | ${row.status} | [report](${row.evidence}) |`);
  lines.push('', `Page exceptions across source reports: ${consolidated.pageErrors.length}.`, '', 'Raw HTTP errors and bootstrap/security rejections remain in the JSON. Historical GET 401s without a logged matching 200 remain unclassified; they are not silently labelled recovered.', '', 'Unexercised coverage is disclosed separately and is excluded from case totals. All old failed/blocked attempts remain in the JSON history.');
  await writeFile(path.join(qaRoot, 'management-identity-consolidated.md'), lines.join('\n'));
  console.log(JSON.stringify({ report: path.join(qaRoot, 'management-identity-consolidated.json'), suites, pageErrors: consolidated.pageErrors.length }, null, 2));
}
if (process.argv.includes('--consolidate')) { await consolidateReports(); process.exit(0); }

await mkdir(out, { recursive: true });
try {
  if (process.env.QA_SERVICE_FIXTURE_RUN) {
    const sourcePath = path.join(root, 'storage/app/private/full-qa', `management-${serviceFixtureRun}`, 'report.json');
    const source = JSON.parse(await readFile(sourcePath, 'utf8'));
    for (const key of ['a', 'b']) need(source.checks.some(check => check.name === `service-create-${key}` && check.status === 'passed'), 'Reused service fixture must have verified create evidence.');
    createdServices.add(names.a); createdServices.add(names.b); report.serviceFixtureEvidence = sourcePath;
  }
  browser = await chromium.launch({ channel: process.env.QA_BROWSER_CHANNEL || 'chrome', headless: process.env.QA_HEADED !== '1' });
  const ownerContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const owner = await ownerContext.newPage(); owner.setDefaultTimeout(15000); owner.setDefaultNavigationTimeout(45000); observe(owner, 'OWNER');
  const ownerOK = await step(owner, 'owner-loginUI', () => loginUI(owner, 'owner'));
  for (const key of ['a', 'b']) await step(owner, `service-create-${key}`, async () => { need(ownerOK, 'OWNER login failed'); await createService(owner, names[key]); });
  await step(owner, 'service-validation-edit-persistence', async () => {
    await go(owner, '/salon/services', 'Dịch vụ'); await searchServices(owner, names.a);
    await button(card(owner, names.a), 'Sửa').click(); const d = dialog(owner, 'Chỉnh sửa dịch vụ');
    await waitValue(input(d, 'Tên dịch vụ'), names.a);
    await input(d, 'Tên dịch vụ').fill(''); await input(d, 'Tên dịch vụ').press('Tab'); assert.equal(await input(d, 'Tên dịch vụ').inputValue(), ''); await button(d, 'Lưu thay đổi').click(); await visible(d.getByText('Nhập tên dịch vụ.', { exact: true }));
    await input(d, 'Tên dịch vụ').fill(names.a); await input(d, 'Giá cơ sở (VNĐ)').fill('175000');
    await writeUI(owner, 'PATCH', /\/services\/catalog\/[^/]+$/, () => button(d, 'Lưu thay đổi').click());
    await d.waitFor({ state: 'hidden' }); await owner.reload({ waitUntil: 'domcontentloaded' }); await searchServices(owner, names.a);
    await button(card(owner, names.a), 'Sửa').click(); await visible(d); assert.equal(await input(d, 'Giá cơ sở (VNĐ)').inputValue(), '175000'); await button(d, 'Hủy').click();
  });
  await step(owner, 'variant-create-status-roundtrip', async () => {
    need(createdServices.has(names.a), 'Service A creation did not pass; variant setup is unavailable.');
    const d = await openService(owner, names.a);
    const section = d.locator('.bb-card').filter({ has: owner.getByText(branch, { exact: true }) });
    const variant = `QA Variant ${run}`;
    await input(section, 'Mã').fill(`QA${Date.now()}`); await input(section, 'Tên biến thể').fill(variant);
    await input(section, 'Giá').fill('180000'); await input(section, 'Thời lượng').fill('45');
    await writeUI(owner, 'POST', /\/services\/[^/]+\/variants$/, () => button(section, 'Thêm biến thể').click());
    const row = section.locator('div.rounded-xl').filter({ has: owner.locator('strong').filter({ hasText: re(variant) }) });
    await writeUI(owner, 'PATCH', /\/services\/variants\/[^/]+$/, () => button(row, 'Tạm ngưng').click());
    await visible(button(row, 'Kích hoạt'));
    await writeUI(owner, 'PATCH', /\/services\/variants\/[^/]+$/, () => button(row, 'Kích hoạt').click()); await visible(button(row, 'Tạm ngưng'));
    await close(owner, names.a);
  });
  await step(owner, 'service-branch-availability-roundtrip', async () => {
    const d = await openService(owner, names.a);
    const section = d.locator('.bb-card').filter({ has: owner.getByText(branch, { exact: true }) });
    await writeUI(owner, 'POST', /\/branches\/[^/]+\/pause$/, () => section.locator(':scope > div').first().getByRole('button', { name: 'Tạm ngưng', exact: true }).click());
    await visible(button(section, 'Kích hoạt lại'));
    await writeUI(owner, 'POST', /\/branches\/[^/]+\/reactivate$/, () => button(section, 'Kích hoạt lại').click());
    await close(owner, names.a);
  });
  const comboAcceptance = async (description = '') => {
    need(createdServices.has(names.a) && createdServices.has(names.b), 'Both unique service creates must pass before Combo Builder acceptance.');
    const provider = process.env.QA_COMBO_STAFF_NAME || names.staff;
    need(/^QA\b/.test(provider), 'Combo setup must use a QA-named ACTIVE/bookable provider.');
    await go(owner, '/salon/staff', 'Nhân sự'); await owner.getByLabel('Tìm nhân viên', { exact: true }).fill(provider);
    await button(card(owner, provider), 'Dịch vụ').click();
    const assignment = dialog(owner, 'Dịch vụ phụ trách');
    // Preserve every existing selection. Append BOTH unique offerings to the
    // same provider so SINGLE_PROVIDER has a real capability intersection.
    for (const service of [names.a, names.b]) await assignment.getByLabel(service, { exact: false }).check();
    await writeUI(owner, 'PATCH', /\/staff\/[^/]+\/services$/, () => assignment.getByRole('button', { name: /^Lưu \(/ }).click());
    await assignment.waitFor({ state: 'hidden' }); await button(card(owner, provider), 'Dịch vụ').click();
    for (const service of [names.a, names.b]) assert(await assignment.getByLabel(service, { exact: false }).isChecked(), 'Same provider must retain both unique capabilities');
    await close(owner, 'Dịch vụ phụ trách');
    await go(owner, '/salon/combos', 'Combo dịch vụ'); await owner.getByRole('group', { name: 'Chi nhánh đang quản lý', exact: true }).getByRole('button', { name: branch, exact: true }).click();
    await owner.locator('.bb-page-header').getByRole('button', { name: 'Tạo combo', exact: true }).click(); let d = dialog(owner, 'Combo Builder');
    assert(await button(d, 'Lưu combo').isDisabled(), 'Empty combo cannot save');
    await input(d, 'Tên combo').fill(names.combo); await input(d, 'Giá combo').fill('250000');
    if (description) await input(d, 'Mô tả').fill(description);
    for (const name of [names.a, names.b]) {
      await d.getByPlaceholder('Tìm tên hoặc nhóm dịch vụ').fill(name);
      await button(d.getByRole('article').filter({ hasText: name }), 'Thêm').click();
    }
    await button(d, `Đưa ${names.b} lên`).click();
    await writeUI(owner, 'POST', /\/combos$/, () => button(d, 'Lưu combo').click()); await d.waitFor({ state: 'hidden' });
    await input(owner, 'Tìm combo').fill(names.combo); await button(card(owner, names.combo), 'Sửa').click(); d = dialog(owner, 'Tạo phiên bản combo mới');
    assert(await button(d, `Đưa ${names.b} lên`).isDisabled(), 'Reordered first service persisted');
    await input(d, 'Giá combo').fill('240000');
    await writeUI(owner, 'PATCH', /\/combos\/[^/]+$/, () => button(d, 'Lưu combo').click()); await d.waitFor({ state: 'hidden' });
    await button(card(owner, names.combo), 'Sửa').click(); assert.equal(await input(d, 'Giá combo').inputValue(), '240000'); await button(d, 'Hủy').click();
    await button(card(owner, names.combo), 'Lưu trữ').click();
    const confirm = owner.getByRole('dialog');
    await writeUI(owner, 'DELETE', /\/combos\/[^/]+$/, () => button(confirm, 'Lưu trữ').click()); await confirm.waitFor({ state: 'hidden' });
    await card(owner, names.combo).waitFor({ state: 'hidden' });
    await owner.reload({ waitUntil: 'domcontentloaded' }); await input(owner, 'Tìm combo').fill(names.combo);
    await card(owner, names.combo).waitFor({ state: 'hidden' });
  };
  await step(owner, 'staff-create-edit-assign-detail', async () => {
    await go(owner, '/salon/staff', 'Nhân sự'); await button(owner, 'Thêm nhân sự').click(); let d = dialog(owner, 'Thêm nhân sự');
    await button(d, 'Lưu').click(); await visible(d.getByText('Nhập họ tên.', { exact: true }));
    await choose(d, 'Chi nhánh', branch); await input(d, 'Họ tên').fill(names.staff); await input(d, 'Vị trí').fill('QA specialist');
    assert(await d.getByLabel('Hiện trên trang công khai', { exact: false }).isDisabled());
    await writeUI(owner, 'POST', /\/staff$/, () => button(d, 'Lưu').click()); await d.waitFor({ state: 'hidden' });
    await owner.getByLabel('Tìm nhân viên', { exact: true }).fill(names.staff);
    await button(card(owner, names.staff), 'Hồ sơ').click(); d = dialog(owner, 'Sửa hồ sơ nhân viên'); await input(d, 'Vị trí').fill(`QA edited ${run}`);
    await d.getByLabel('Cho phép nhận lịch', { exact: false }).check(); await choose(d, 'Trạng thái', 'Hoạt động');
    await writeUI(owner, 'PATCH', /\/staff\/[^/]+$/, () => button(d, 'Lưu').click()); await d.waitFor({ state: 'hidden' });
    await visible(card(owner, names.staff).getByText(`QA edited ${run}`, { exact: true }));
    await button(card(owner, names.staff), 'Dịch vụ').click(); d = dialog(owner, 'Dịch vụ phụ trách'); await d.getByLabel(names.a, { exact: false }).check();
    await writeUI(owner, 'PATCH', /\/staff\/[^/]+\/services$/, () => d.getByRole('button', { name: /^Lưu \(/ }).click()); await d.waitFor({ state: 'hidden' });
    await button(card(owner, names.staff), 'Dịch vụ').click(); assert(await d.getByLabel(names.a, { exact: false }).isChecked()); await close(owner, 'Dịch vụ phụ trách');
    await button(card(owner, names.staff), 'Chi tiết').click(); await owner.waitForURL(/\/salon\/staff\//); await visible(owner.getByRole('heading', { name: names.staff, exact: true }));
  });
  await step(owner, 'combo-build-order-edit-archive', () => comboAcceptance());
  if (selectedCases.has('combo-create-edit-archive-with-description')) await step(owner, 'combo-create-edit-archive-with-description', () => comboAcceptance(`QA combo description ${run}`));
  await step(owner, 'staff-invitation-safe-mailer', async () => {
    need(['array', 'log'].includes(process.env.QA_MAIL_MAILER), 'Requires server MAIL_MAILER=array/log and matching explicit QA_MAIL_MAILER; invitation not sent.');
    await go(owner, '/salon/staff', 'Nhân sự'); await button(owner, 'Thêm nhân sự').click(); const d = dialog(owner, 'Thêm nhân sự');
    await choose(d, 'Chi nhánh', branch); await input(d, 'Họ tên').fill(`QA ${run} Invited`); await choose(d, 'Cấp tài khoản', 'Gửi lời mời qua email ngay');
    await input(d, 'Email nhận lời mời').fill(`qa-${run}@beautybook.local`);
    await writeUI(owner, 'POST', /\/staff\/invitations$/, () => button(d, 'Lưu').click()); await d.waitFor({ state: 'hidden' });
    await visible(owner.getByText('Đã tạo hồ sơ và gửi lời mời', { exact: true }));
  });
  await step(owner, 'owner-promotion-create-edit-detail-delete', () => campaign(owner, false));
  await step(owner, 'owner-voucher-create-edit-grant-revoke', () => campaign(owner, true));

  const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const admin = await adminContext.newPage(); admin.setDefaultTimeout(15000); admin.setDefaultNavigationTimeout(45000); observe(admin, 'ADMIN');
  await step(admin, 'admin-loginUI', () => loginUI(admin, 'admin'));
  await step(admin, 'admin-user-detail-search-empty-state', async () => {
    await go(admin, '/admin/users', 'Người dùng'); await admin.getByPlaceholder('Tìm theo tên, email hoặc số điện thoại...').fill(`nonexistent-${run}`);
    await visible(admin.getByText('Không tìm thấy người dùng phù hợp', { exact: true }));
    await admin.getByPlaceholder('Tìm theo tên, email hoặc số điện thoại...').fill('customer@beautybook.local');
    await button(admin.getByRole('row').filter({ hasText: 'customer@beautybook.local' }), 'Chi tiết').click();
    await admin.waitForURL(/\/admin\/users\//); await visible(admin.getByRole('heading', { name: 'Vai trò và phạm vi', exact: true }));
    await visible(admin.getByText('customer@beautybook.local', { exact: true }));
  });
  await step(admin, 'admin-disposable-user-status-roundtrip', async () => {
    const email = process.env.QA_STATUS_EMAIL;
    need(email && /^qa[^@]*@beautybook\.local$/i.test(email), 'QA_STATUS_EMAIL must identify a disposable ACTIVE QA customer without assignments/bookings/businesses.');
    await go(admin, '/admin/users', 'Người dùng'); await admin.getByPlaceholder('Tìm theo tên, email hoặc số điện thoại...').fill(email);
    await button(admin.getByRole('row').filter({ hasText: email }), 'Chi tiết').click(); await admin.waitForURL(/\/admin\/users\//);
    let changed = false;
    async function toggle(label) {
      await button(admin, label).click(); const d = admin.getByRole('dialog'); await visible(d.getByText('Lịch khách hàng đang hoạt động/sắp tới: 0', { exact: true }));
      for (const text of ['Dịch vụ đang phân công: 0', 'Phân công chi nhánh: 0', 'Doanh nghiệp thuộc trách nhiệm: 0']) await visible(d.getByText(text, { exact: true }));
      assert(await button(d, 'Xác nhận').isDisabled(), 'Reason is mandatory'); await input(d, 'Lý do').fill(`QA status acceptance ${run}`);
      await writeUI(admin, 'POST', /\/users\/[^/]+\/suspend$/, () => button(d, 'Xác nhận').click()); changed = !changed; await d.waitFor({ state: 'hidden' });
    }
    try { await toggle('Ngừng hoạt động tài khoản'); await visible(button(admin, 'Mở lại tài khoản')); }
    finally { if (changed) { await toggle('Mở lại tài khoản'); await visible(button(admin, 'Ngừng hoạt động tài khoản')); } }
  });
  await step(admin, 'admin-reports-year-refresh', async () => {
    await go(admin, '/admin/reports', 'Báo cáo nền tảng'); await visible(admin.getByRole('heading', { name: /^Doanh thu theo tháng/ }));
    const year = String(new Date().getFullYear() - 1); await choose(admin, 'Năm', year);
    await visible(admin.getByRole('heading', { name: `Doanh thu theo tháng · ${year}`, exact: true }));
    await button(admin, 'Làm mới').click(); await visible(admin.getByRole('heading', { name: `Tăng trưởng người dùng · ${year}`, exact: true }));
  });
  await step(admin, 'admin-settings-boundary-save-restore', async () => {
    const loaded = admin.waitForResponse(r => /\/api\/v1\/admin\/settings$/.test(new URL(r.url()).pathname) && r.request().method() === 'GET');
    loaded.catch(() => {}); await go(admin, '/admin/settings', 'Cài đặt hệ thống'); const payload = await (await loaded).json(); const settings = payload.data || payload;
    const c = input(admin, 'Đặt trước tối đa'); await visible(c); const original = await c.inputValue();
    await c.fill('366'); await visible(admin.getByText('Nhập số nguyên từ 1 đến 365', { exact: true })); assert(await button(admin, 'Lưu thay đổi').isDisabled()); await c.fill(original);
    need(settings.configured?.freeCancellationHours === 4, 'Settings write requires preconfigured freeCancellationHours=4 to restore exact configured keys through UI. Boundary check passed.');
    let changed = false;
    try {
      await c.fill(original === '31' ? '32' : '31'); await writeUI(admin, 'PATCH', /\/admin\/settings$/, () => button(admin, 'Lưu thay đổi').click()); changed = true;
      await admin.reload({ waitUntil: 'domcontentloaded' }); await visible(c); assert.equal(await c.inputValue(), original === '31' ? '32' : '31');
    } finally {
      if (changed) { await c.fill(original); await writeUI(admin, 'PATCH', /\/admin\/settings$/, () => button(admin, 'Lưu thay đổi').click()); await admin.reload({ waitUntil: 'domcontentloaded' }); await visible(c); assert.equal(await c.inputValue(), original); }
    }
    await button(admin, 'Khôi phục mặc định').click(); await button(dialog(admin, 'Khôi phục cài đặt mặc định?'), 'Hủy').click();
  });
  await step(admin, 'admin-business-review-request-info', async () => {
    await admin.goto(`${base}/admin/salons?view=review`, { waitUntil: 'domcontentloaded' });
    await button(admin, 'Xét duyệt hồ sơ').click(); await visible(admin.getByRole('heading', { name: 'Xét duyệt hồ sơ', exact: true }));
    const target = process.env.QA_REVIEW_BUSINESS_NAME;
    need(target && /^QA\b/.test(target), 'QA_REVIEW_BUSINESS_NAME must name a disposable QA PENDING_REVIEW/SUBMITTED business. IdentityDemoSeeder has no guaranteed pending business.');
    await choose(admin, 'Loại hồ sơ', 'Doanh nghiệp'); await input(admin, 'Tìm hồ sơ').fill(target);
    await button(admin.getByRole('row').filter({ hasText: target }), 'Xem hồ sơ').click(); const d = dialog(admin, target);
    await visible(d);
    need(await button(d, 'Yêu cầu bổ sung').count() > 0, 'Disposable review fixture already consumed (not PENDING_REVIEW/SUBMITTED); first-pass evidence is retained.');
    await button(d, 'Yêu cầu bổ sung').click(); const confirm = dialog(admin, 'Yêu cầu bổ sung thông tin');
    await button(confirm, 'Xác nhận').click(); await visible(admin.getByText('Vui lòng nhập lý do hoặc nội dung cần bổ sung.', { exact: true }));
    await input(confirm, 'Lý do / nội dung cần bổ sung').fill(`QA review ${run}: bổ sung tài liệu kiểm thử`);
    await writeUI(admin, 'PATCH', /\/business\/[^/]+\/review$/, () => button(confirm, 'Xác nhận').click()); await confirm.waitFor({ state: 'hidden' });
    await button(admin.getByRole('row').filter({ hasText: target }), 'Xem hồ sơ').click(); await visible(dialog(admin, target).getByText(`QA review ${run}: bổ sung tài liệu kiểm thử`, { exact: true }));
  });
  for (const [page, role, routes] of [[owner, 'owner', ['/salon/services', '/salon/combos', '/salon/staff', '/salon/promotions']], [admin, 'admin', ['/admin/users', '/admin/reports', '/admin/settings']]]) {
    await step(page, `${role}-mobile-management-layout`, async () => {
      await page.setViewportSize({ width: 390, height: 844 });
      try { for (const route of routes) {
        await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' }); await visible(page.getByRole('heading', { level: 1 }));
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `Page overflow: ${route}`);
        await screenshot(page, `mobile-${route}`);
      } } finally { await page.setViewportSize({ width: 1440, height: 1000 }); }
    });
  }
  report.checks.push({ name: 'unimplemented-management-surfaces', status: selectedCases.size ? 'not_run' : 'blocked', error: 'Not covered by this bounded script: media uploads, service dependency/price rules, combo duplicate, staff calendar/offboarding, admin taxonomy/moderation/ownership/compliance decisions other than request-info. Requires additional dedicated scenarios/fixtures; not silently counted as passing.' });
} catch (error) { report.checks.push({ name: 'fatal', status: 'failed', error: error.stack || String(error) }); }
finally {
  await browser?.close(); report.browserClosed = browser ? !browser.isConnected() : true; await Promise.allSettled([...pending]); report.finishedAt = new Date().toISOString();
  report.summary = { passed: report.checks.filter(c => c.status === 'passed').length, failed: report.checks.filter(c => c.status === 'failed').length, blocked: report.checks.filter(c => c.status === 'blocked').length, apiErrors: report.apiErrors.length, unexpectedApiErrors: report.apiErrors.filter(e => !e.expected).length, pageErrors: report.pageErrors.length };
  await writeFile(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ artifacts: out, ...report.summary }, null, 2));
  process.exitCode = report.summary.failed || report.summary.unexpectedApiErrors || report.summary.pageErrors ? 1 : report.summary.blocked ? 2 : 0;
}
