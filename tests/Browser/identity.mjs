/**
 * Authored only: DO NOT run while the backend suite owns the QA database.
 * After release, run against an isolated local QA server (no SMTP):
 *   $env:QA_BASE_URL='http://127.0.0.1:8013/beautybook'
 *   $env:QA_MAIL_MAILER='log'; node tests/Browser/identity.mjs
 * QA_MAIL_MAILER=log attests that the SERVER also uses MAIL_MAILER=log with
 * LOG_CHANNEL=single and its QA storage path resolving to full-qa/runtime (or
 * an equivalent runtime logging override targeting the file below). This repo
 * does not support LOG_SINGLE_PATH. Clear stale server config before starting
 * it. This script never configures Laravel.
 * Default log: storage/app/private/full-qa/runtime/logs/laravel.log.
 * Optional QA_IDENTITY_LOG must stay under storage/app/private/full-qa.
 * Requires writable local avatar storage, database session driver and a built FE.
 * All UI writes target one freshly registered qa-identity-...@example.test account.
 * No seeded account is used. All auth is through UI, no mocks/direct API writes.
 * A second recovery form requests the same challenge inside the server's 60s
 * resend window BEFORE successful reset, making reuse testable via real UI.
 * Codes/challenge IDs/passwords stay in memory; no HAR, trace, storageState or
 * response bodies are saved. Screenshots mask inputs. The input mail log itself
 * contains sensitive mail and is never copied into artifacts or printed.
 * Results: full-qa/identity-<run>/report.json and per-case masked PNGs.
 * Exit 1 failures/unexpected API errors/page errors; 2 prerequisite blocks; 0 pass.
 * A failed prerequisite blocks only dependent cases; unrelated cases continue.
 * To rerun recovery on an account created by an earlier run, set QA_RESUME_RUN
 * to that report's run and QA_IDENTITY_CASES to comma-separated case names. Only
 * previously passed prerequisites from that exact report are accepted as evidence.
 */
import { chromium } from 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs';
import assert from 'node:assert/strict';
import { mkdir, readFile, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

const root = fileURLToPath(new URL('../../', import.meta.url));
const base = (process.env.QA_BASE_URL || 'http://127.0.0.1:8013/beautybook').replace(/\/$/, '');
const qaRoot = path.join(root, 'storage/app/private/full-qa');
const logPath = path.resolve(process.env.QA_IDENTITY_LOG || path.join(qaRoot, 'runtime/logs/laravel.log'));
const run = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const fixtureRun = process.env.QA_RESUME_RUN || run;
assert(/^\d{13}-[a-z0-9]{6}$/.test(fixtureRun), 'Invalid QA identity fixture run');
const email = `qa-identity-${fixtureRun}@example.test`;
const out = path.join(qaRoot, `identity-${run}`);
const passwords = { initial: `QaFirst!${fixtureRun}`, changed: `QaChanged!${fixtureRun}`, reset: `QaRecovered!${fixtureRun}`, unused: `QaUnused!${fixtureRun}` };
const secrets = new Set(Object.values(passwords));
const report = { run, base, email, startedAt: new Date().toISOString(), checks: [], responses: [], apiErrors: [], pageErrors: [], consoleErrors: [], requestFailures: [], writes: [], artifacts: [], limitations: ['Operator must ensure server log mailer; browser process cannot prove server configuration.', 'Email verification links are not exercised.', 'Created QA account and avatar remain for inspection; no shared accounts are changed.'] };
const results = new Map(), events = new Map(), authenticated = new WeakSet(), passwordInvalidated = new WeakSet();
let browser, active = 'startup', currentPassword = passwords.initial, recoveryCode, requestId;
const contexts = [];
const selectedCases = new Set((process.env.QA_IDENTITY_CASES || '').split(',').filter(Boolean));
class Blocked extends Error {}
function requireFixture(condition, message) { if (!condition) throw new Blocked(message); }
function redact(value) {
  let text = String(value);
  for (const secret of secrets) if (secret) text = text.split(secret).join('[redacted]');
  return text.replace(/\b\d{6}\b/g, '[redacted-code]').replace(/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/gi, '[redacted-id]').replace(/Bearer\s+\S+/gi, 'Bearer [redacted]').replace(/\beyJ[A-Za-z0-9_.-]+/g, '[redacted-token]');
}
function safePath(url) { return new URL(url).pathname.replace(/(\/sessions\/)[^/]+$/, '$1[redacted-id]'); }
const btn = (scope, name) => scope.getByRole('button', { name, exact: true });
const show = locator => locator.waitFor({ state: 'visible' });
const field = (page, label) => page.locator('.bb-field').filter({ has: page.locator('label').filter({ hasText: new RegExp(`^${label}(?:\\s*\\*?\\s*\\(bắt buộc\\))?$`) }) }).locator('input, textarea');
async function snapshot(page, name) {
  if (!page || page.isClosed()) return;
  const filename = `${String(report.artifacts.length + 1).padStart(2, '0')}-${name}.png`;
  await page.screenshot({ path: path.join(out, filename), fullPage: true, mask: [page.locator('input:not([type=file]), textarea')] });
  report.artifacts.push(filename);
}
async function check(page, name, dependencies, action) {
  if (selectedCases.size && !selectedCases.has(name)) { report.checks.push({ name, status: 'not_run', reason: 'Excluded by QA_IDENTITY_CASES; prerequisites may reference explicitly linked prior evidence.' }); return; }
  active = name; const entry = { name, status: 'running', dependencies }; report.checks.push(entry); const started = Date.now();
  try {
    const missing = dependencies.filter(key => results.get(key) !== 'passed');
    requireFixture(!missing.length, `Dependent checks did not pass: ${missing.join(', ')}`);
    await action(); entry.status = 'passed';
  } catch (error) { entry.status = error instanceof Blocked ? 'blocked' : 'failed'; entry.error = redact(error.stack || error); }
  finally {
    entry.ms = Date.now() - started; results.set(name, entry.status);
    await snapshot(page, name).catch(error => { entry.screenshotError = redact(error.message); });
    await writeFile(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
    console.log(`${entry.status.toUpperCase()} ${name}`);
  }
}
function observe(page, label) {
  page.on('pageerror', error => report.pageErrors.push({ page: label, check: active, message: redact(error.message) }));
  page.on('console', msg => { if (msg.type() === 'error') report.consoleErrors.push({ page: label, check: active, message: redact(msg.text()) }); });
  page.on('requestfailed', req => report.requestFailures.push({ page: label, check: active, path: safePath(req.url()), message: redact(req.failure()?.errorText) }));
  page.on('response', response => {
    const req = response.request();
    if (!['fetch', 'xhr'].includes(req.resourceType()) || new URL(response.url()).origin !== new URL(base).origin) return;
    const pathname = new URL(response.url()).pathname;
    if (/\/auth\/change-password$/.test(pathname) && response.ok()) passwordInvalidated.add(page);
    if (/\/auth\/login$/.test(pathname) && response.ok()) passwordInvalidated.delete(page);
    const bootstrap = !authenticated.has(page) && /\/auth\/refresh$/.test(pathname) && response.status() === 401;
    const cleanup = passwordInvalidated.has(page) && /\/auth\/(?:logout|refresh)$/.test(pathname) && response.status() === 401;
    const entry = { sequence: report.responses.length + 1, at: new Date().toISOString(), page: label, check: active, method: req.method(), path: safePath(response.url()), status: response.status(), expected: bootstrap || cleanup, ...((bootstrap || cleanup) ? { reason: cleanup ? 'Password change already invalidated the session before client logout/refresh cleanup' : 'Unauthenticated app bootstrap refresh' } : {}) };
    report.responses.push(entry);
    if (response.status() === 200) for (const previous of report.apiErrors) {
      if (previous.status === 401 && !previous.recovered && previous.page === label && previous.method === entry.method && previous.path === entry.path && previous.sequence < entry.sequence) Object.assign(previous, { recovered: true, recoveredBy: { sequence: entry.sequence, status: 200, at: entry.at } });
    }
    events.set(response, entry);
    if (response.status() >= 400) report.apiErrors.push(entry);
    if (!['GET', 'HEAD', 'OPTIONS'].includes(req.method())) report.writes.push(entry);
  });
}
async function newPage(label) {
  const context = await browser.newContext({ viewport: { width: 1365, height: 950 }, userAgent: `Mozilla/5.0 Chrome/131.0.0.0 Safari/537.36 QAIdentity-${label}` });
  contexts.push(context); const page = await context.newPage(); page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(45000); observe(page, label); return page;
}
async function actionResponse(page, method, endpoint, action, status) {
  let response;
  for (let attempt = 0; attempt < 3; attempt++) {
    const waiting = page.waitForResponse(r => r.request().method() === method && endpoint.test(new URL(r.url()).pathname)); waiting.catch(() => {});
    await action(); response = await waiting;
    if (response.status() !== 429 || !new URL(response.url()).pathname.includes('/auth/')) break;
    const header = response.headers()['retry-after'];
    const seconds = header && /^\d+$/.test(header) ? Number(header) : header ? Math.max(0, Math.ceil((Date.parse(header) - Date.now()) / 1000)) : 60;
    requireFixture(Number.isFinite(seconds) && seconds <= 300, 'Auth rate limit Retry-After exceeds bounded retry window.');
    if (events.has(response)) Object.assign(events.get(response), { expected: true, reason: 'Auth throttle; respecting Retry-After' });
    console.log(`WAIT auth throttle ${seconds + 1}s before UI retry`);
    const until = Date.now() + (seconds + 1) * 1000;
    while (Date.now() < until) { await delay(Math.min(1000, until - Date.now())); }
  }
  if (status !== undefined) {
    // Check the exact rejection status; errors are expected only for this response.
    assert.equal(response.status(), status, `Unexpected ${method} response status`);
    if (status >= 400 && events.has(response)) events.get(response).expected = true;
  } else assert(response.ok(), `Unexpected ${method} response status ${response.status()}`);
  return response;
}
async function goto(page, route, heading) {
  await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' });
  if (heading) await show(page.getByRole('heading', { name: heading, exact: true }));
}
async function loginUI(page, password, expectedStatus) {
  authenticated.delete(page);
  await goto(page, '/login', 'Đăng nhập BeautyBook');
  await page.locator('#login-email').fill(email); await page.locator('input[autocomplete=current-password]').fill(password);
  await actionResponse(page, 'POST', /\/auth\/login$/, () => btn(page, 'Đăng nhập').click(), expectedStatus);
  if (expectedStatus) { await show(page.locator('.bb-auth-alert[role=alert]').filter({ hasText: 'Email hoặc mật khẩu không đúng.' })); assert(new URL(page.url()).pathname.endsWith('/login')); }
  else { await page.waitForURL(url => url.pathname.includes('/customer/')); authenticated.add(page); }
}
async function security(page) { await goto(page, '/customer/security', 'Bảo mật & phiên đăng nhập'); await show(page.getByRole('heading', { name: 'Thiết bị hiện tại', exact: true })); }
async function fillReset(page, code, password) {
  await page.locator('#recovery-code').fill(code); await page.locator('#recovery-new-password').fill(password); await page.locator('#recovery-confirm-password').fill(password);
}
async function requestCodeUI(page) {
  await goto(page, '/forgot-password');
  const heading = page.getByRole('heading', { level: 1 }); await show(heading);
  assert.equal(await heading.textContent(), 'Quên mật khẩu?', 'OTP recovery UI must be mounted: legacy link-recovery form does not expose the six-digit code workflow.');
  await page.locator('#recovery-email').fill(email);
  const response = await actionResponse(page, 'POST', /\/auth\/forgot-password\/code$/, () => btn(page, 'Gửi mã xác nhận').click());
  const data = await response.json(); const id = (data.data || data).requestId;
  assert.equal(typeof id, 'string', 'Recovery request must return a challenge'); secrets.add(id);
  await show(page.getByRole('heading', { name: 'Xác nhận email', exact: true })); return id;
}
async function readNewCode(offset) {
  const until = Date.now() + 20000;
  while (Date.now() < until) {
    const bytes = await readFile(logPath);
    requireFixture(bytes.length >= offset, 'QA mail log rotated/truncated during recovery; do not use an earlier code.');
    const appended = bytes.subarray(offset).toString('utf8');
    // Symfony log transport may render quoted-printable text. Decode locally.
    const decoded = Buffer.from(appended.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16))), 'latin1').toString('utf8');
    for (const text of [appended, decoded]) {
      // Limit to mail addressed to THIS new account, then the recovery marker.
      const escaped = email.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const recipients = [...text.matchAll(new RegExp(`^To:[^\\r\\n]*${escaped}[^\\r\\n]*`, 'gmi'))];
      for (const recipient of recipients.reverse()) {
        const start = recipient.index, next = text.indexOf('\nTo:', start + 1);
        const mail = text.slice(start, next < 0 ? undefined : next);
        const codes = [...mail.matchAll(/Mã xác nhận đặt lại mật khẩu BeautyBook của bạn là:\s*(\d{6})\b/g)];
        if (codes.length) { const code = codes.at(-1)[1]; secrets.add(code); return code; }
      }
    }
    await delay(250);
  }
  throw new Blocked('No recipient-matched recovery mail/code appeared in newly appended QA log within 20 seconds; check SERVER log mailer/path.');
}

await mkdir(out, { recursive: true });
try {
  if (process.env.QA_RESUME_RUN) {
    const priorPath = path.join(qaRoot, `identity-${fixtureRun}`, 'report.json');
    const prior = JSON.parse(await readFile(priorPath, 'utf8'));
    assert.equal(prior.email, email, 'Resume fixture must match previously registered QA account');
    requireFixture(prior.checks.some(c => c.name === 'registration-new-customer' && c.status === 'passed'), 'Resume requires a previously successful new-account registration.');
    report.priorEvidence = { report: path.relative(root, priorPath), passedPrerequisites: prior.checks.filter(c => c.status === 'passed').map(c => c.name) };
    for (const name of report.priorEvidence.passedPrerequisites) results.set(name, 'passed');
    if (results.get('password-change') === 'passed') currentPassword = passwords.changed;
    if (results.get('recovery-success') === 'passed') currentPassword = passwords.reset;
  }
  browser = await chromium.launch({ channel: process.env.QA_BROWSER_CHANNEL || 'chrome', headless: process.env.QA_HEADED !== '1' });
  const main = await newPage('Main'), invalid = await newPage('Invalid'), first = await newPage('Single'), bulk = await newPage('Bulk');
  const recovery = await newPage('Recovery'), reuse = await newPage('Reuse'), relogin = await newPage('Relogin');
  await check(main, 'qa-mail-prerequisite', [], async () => {
    requireFixture(process.env.QA_MAIL_MAILER === 'log', 'Set QA_MAIL_MAILER=log only when SERVER MAIL_MAILER=log (no SMTP); registration/recovery writes blocked.');
    const relative = path.relative(qaRoot, logPath); requireFixture(relative && !relative.startsWith('..') && !path.isAbsolute(relative), 'QA_IDENTITY_LOG must stay under storage/app/private/full-qa.');
    requireFixture(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname), 'This mail-recovery script requires a local QA server.');
  });
  await check(main, 'registration-invalid-input', ['qa-mail-prerequisite'], async () => {
    await goto(main, '/register', 'Tạo tài khoản khách hàng'); await btn(main, 'Tạo tài khoản').click();
    await show(main.getByText('Nhập họ và tên có ít nhất 2 ký tự.', { exact: true }));
    await main.locator('#register-fullName').fill(`QA Identity ${run}`); await main.locator('#register-email').fill(email);
    await main.locator('#register-password').fill(passwords.initial); await main.locator('#register-confirmPassword').fill(passwords.changed);
    await main.locator('label[for="register-terms"]').click(); assert(await main.locator('#register-terms').isChecked()); await btn(main, 'Tạo tài khoản').click();
    await show(main.getByText('Mật khẩu nhập lại chưa khớp.', { exact: true }));
  });
  await check(main, 'registration-new-customer', ['qa-mail-prerequisite'], async () => {
    await goto(main, '/register', 'Tạo tài khoản khách hàng');
    for (const [id, value] of [['fullName', `QA Identity ${run}`], ['email', email], ['password', passwords.initial], ['confirmPassword', passwords.initial]]) await main.locator(`#register-${id}`).fill(value);
    await main.locator('label[for="register-terms"]').click(); assert(await main.locator('#register-terms').isChecked());
    await actionResponse(main, 'POST', /\/auth\/register$/, () => btn(main, 'Tạo tài khoản').click());
    authenticated.add(main);
    await main.waitForURL(url => url.pathname.includes('/customer/appointments'));
  });
  await check(invalid, 'invalid-login', ['registration-new-customer'], () => loginUI(invalid, passwords.unused, 422));
  await check(main, 'profile-update-persistence', ['registration-new-customer'], async () => {
    await goto(main, '/customer/profile', 'Hồ sơ cá nhân'); const name = `QA Updated ${run}`;
    await field(main, 'Họ và tên').fill(name); await field(main, 'Địa chỉ').fill(`QA address ${run}`);
    await actionResponse(main, 'PATCH', /\/users\/me\/profile$/, () => btn(main, 'Lưu thay đổi').click());
    await show(main.getByText('Đã cập nhật hồ sơ', { exact: true })); await main.reload({ waitUntil: 'domcontentloaded' }); await show(field(main, 'Họ và tên'));
    assert.equal(await field(main, 'Họ và tên').inputValue(), name); assert.equal(await field(main, 'Địa chỉ').inputValue(), `QA address ${run}`);
  });
  await check(main, 'avatar-invalid-type', ['registration-new-customer'], async () => {
    await goto(main, '/customer/profile', 'Hồ sơ cá nhân'); await show(main.locator('input[type=file]'));
    await main.locator('input[type=file]').setInputFiles({ name: 'qa-avatar.txt', mimeType: 'text/plain', buffer: Buffer.from('QA invalid avatar') });
    await show(main.getByText('Chỉ nhận ảnh JPG, PNG, WebP hoặc AVIF.', { exact: true }));
  });
  await check(main, 'avatar-upload-persistence', ['registration-new-customer'], async () => {
    await goto(main, '/customer/profile', 'Hồ sơ cá nhân');
    // Valid 1x1 RGBA PNG with checked chunk CRCs; no downloaded image fixture.
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGM4YZTyHwAFggJe8/6JhAAAAABJRU5ErkJggg==', 'base64');
    await actionResponse(main, 'POST', /\/media\/upload$/, () => main.locator('input[type=file]').setInputFiles({ name: `qa-avatar-${run}.png`, mimeType: 'image/png', buffer: png }));
    await show(main.getByText('Đã tải ảnh lên', { exact: true })); await main.reload({ waitUntil: 'domcontentloaded' });
    const image = main.getByRole('img', { name: 'Ảnh đại diện', exact: true }); await show(image);
    await main.waitForFunction(() => { const img = document.querySelector('img[alt="Ảnh đại diện"]'); return img?.complete && img.naturalWidth > 0; });
    assert(!(await image.getAttribute('src')).startsWith('blob:'), 'Avatar must persist beyond local preview');
  });
  await check(first, 'second-session-login', ['registration-new-customer'], () => loginUI(first, currentPassword));
  await check(main, 'sessions-single-revoke', ['second-session-login'], async () => {
    await security(main); const row = main.getByRole('article').filter({ hasText: 'QAIdentity-Single' }); await show(row);
    const current = main.getByRole('article').filter({ has: main.getByRole('heading', { name: 'Thiết bị hiện tại', exact: true }) }); assert(await btn(current, 'Thu hồi').isDisabled());
    await actionResponse(main, 'DELETE', /\/auth\/sessions\/[^/]+$/, () => btn(row, 'Thu hồi').click()); await row.waitFor({ state: 'hidden' });
  });
  async function assertRevoked(page) {
    const responses = []; const listener = r => { if (/\/auth\/(?:me|refresh)$|\/users\/me\/profile$/.test(new URL(r.url()).pathname)) responses.push(r); };
    page.on('response', listener);
    try {
      await goto(page, '/customer/profile'); await page.waitForURL(url => url.pathname.endsWith('/login'));
      assert(responses.some(r => r.status() === 401), 'Revoked browser must receive an unauthorized response');
      for (const response of responses) if (response.status() === 401 && events.has(response)) events.get(response).expected = true;
    } finally { page.off('response', listener); }
  }
  await check(first, 'single-revoked-device-denied', ['sessions-single-revoke'], () => assertRevoked(first));
  await check(bulk, 'third-session-login', ['registration-new-customer'], () => loginUI(bulk, currentPassword));
  await check(main, 'sessions-revoke-others', ['third-session-login'], async () => {
    await security(main); await show(main.getByRole('article').filter({ hasText: 'QAIdentity-Bulk' })); await btn(main, 'Thu hồi phiên khác').click();
    const d = main.getByRole('dialog', { name: 'Thu hồi các phiên khác?', exact: true });
    await actionResponse(main, 'DELETE', /\/auth\/sessions$/, () => btn(d, 'Thu hồi tất cả phiên khác').click()); await d.waitFor({ state: 'hidden' });
    await main.getByRole('heading', { name: 'Thiết bị khác', exact: true }).waitFor({ state: 'hidden' }); await show(main.getByRole('heading', { name: 'Thiết bị hiện tại', exact: true }));
  });
  await check(bulk, 'bulk-revoked-device-denied', ['sessions-revoke-others'], () => assertRevoked(bulk));
  await check(main, 'password-mismatch-validation', ['registration-new-customer'], async () => {
    await security(main); await field(main, 'Mật khẩu hiện tại').fill(currentPassword); await field(main, 'Mật khẩu mới').fill(passwords.changed); await field(main, 'Xác nhận mật khẩu mới').fill(passwords.unused);
    await show(main.getByText('Mật khẩu xác nhận không khớp.', { exact: true })); assert(await btn(main, 'Cập nhật mật khẩu').isDisabled());
  });
  await check(main, 'password-change', ['registration-new-customer'], async () => {
    await security(main); await field(main, 'Mật khẩu hiện tại').fill(currentPassword); await field(main, 'Mật khẩu mới').fill(passwords.changed); await field(main, 'Xác nhận mật khẩu mới').fill(passwords.changed);
    await actionResponse(main, 'POST', /\/auth\/change-password$/, () => btn(main, 'Cập nhật mật khẩu').click()); currentPassword = passwords.changed;
    await main.waitForURL(url => url.pathname.endsWith('/login'));
  });
  await check(invalid, 'old-password-rejected', ['password-change'], () => loginUI(invalid, passwords.initial, 422));
  await check(main, 'changed-password-relogin', ['password-change'], () => loginUI(main, currentPassword));
  let logOffset;
  await check(recovery, 'recovery-email-code-request', ['registration-new-customer', 'qa-mail-prerequisite'], async () => {
    // A resumed process has fresh browser contexts. Create a REAL pre-reset
    // authenticated session so the later revocation check cannot pass merely
    // because this new browser was never signed in.
    if (process.env.QA_RESUME_RUN && !authenticated.has(main)) {
      await loginUI(main, currentPassword);
      report.resumedSessionEstablishedBeforeReset = true;
    }
    const info = await stat(logPath).catch(() => null); requireFixture(info?.isFile(), 'QA mail log must exist at configured local path before requesting recovery.'); logOffset = info.size;
    requestId = await requestCodeUI(recovery); recoveryCode = await readNewCode(logOffset);
  });
  await check(reuse, 'recovery-second-form-same-challenge', ['recovery-email-code-request'], async () => {
    const secondId = await requestCodeUI(reuse); requireFixture(secondId === requestId, 'Second recovery form received a different challenge (60s window elapsed); recreate both forms in a fresh run.');
  });
  await check(recovery, 'invalid-reset-code', ['recovery-email-code-request'], async () => {
    const wrong = recoveryCode === '000000' ? '000001' : '000000'; secrets.add(wrong); await fillReset(recovery, wrong, passwords.reset);
    await actionResponse(recovery, 'POST', /\/auth\/reset-password\/code$/, () => btn(recovery, 'Xác nhận và đổi mật khẩu').click(), 400);
    await show(recovery.getByRole('alert').filter({ hasText: 'Mã không hợp lệ hoặc đã hết hạn.' }));
  });
  await check(recovery, 'recovery-success', ['recovery-email-code-request'], async () => {
    await fillReset(recovery, recoveryCode, passwords.reset);
    await actionResponse(recovery, 'POST', /\/auth\/reset-password\/code$/, () => btn(recovery, 'Xác nhận và đổi mật khẩu').click()); currentPassword = passwords.reset;
    await show(recovery.getByRole('heading', { name: 'Đã đặt lại mật khẩu', exact: true }));
  });
  await check(reuse, 'reset-code-reuse-rejected', ['recovery-success', 'recovery-second-form-same-challenge'], async () => {
    await fillReset(reuse, recoveryCode, passwords.unused);
    await actionResponse(reuse, 'POST', /\/auth\/reset-password\/code$/, () => btn(reuse, 'Xác nhận và đổi mật khẩu').click(), 400);
    await show(reuse.getByRole('alert').filter({ hasText: 'Mã không hợp lệ hoặc đã hết hạn.' }));
  });
  await check(main, 'reset-revokes-existing-session', ['recovery-success', 'changed-password-relogin'], () => assertRevoked(main));
  await check(relogin, 'recovered-password-relogin', ['recovery-success'], () => loginUI(relogin, currentPassword));
  await check(invalid, 'reset-previous-password-rejected', ['recovery-success', 'password-change'], () => loginUI(invalid, passwords.changed, 422));
} catch (error) { report.checks.push({ name: 'fatal', status: 'failed', error: redact(error.stack || error) }); }
finally {
  await browser?.close(); report.browserClosed = browser ? !browser.isConnected() : true; report.finishedAt = new Date().toISOString();
  report.summary = { passed: report.checks.filter(c => c.status === 'passed').length, failed: report.checks.filter(c => c.status === 'failed').length, blocked: report.checks.filter(c => c.status === 'blocked').length, unexpectedApiErrors: report.apiErrors.filter(e => !e.expected).length, pageErrors: report.pageErrors.length };
  await writeFile(path.join(out, 'report.json'), redact(JSON.stringify(report, null, 2)));
  console.log(JSON.stringify({ artifacts: out, ...report.summary }, null, 2));
  process.exitCode = report.summary.failed || report.summary.unexpectedApiErrors || report.summary.pageErrors ? 1 : report.summary.blocked ? 2 : 0;
}
