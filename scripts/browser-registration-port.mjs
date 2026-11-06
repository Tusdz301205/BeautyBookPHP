import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';

// Run only against an isolated, seeded local test application, after PHPUnit.
const base = process.env.APP_URL || 'http://127.0.0.1:8011';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Local test URL required');
assert.equal(process.env.REGISTRATION_TEST_DATABASE, 'beautybook_test', 'Explicit test database acknowledgement required');
const output = process.env.EVIDENCE_DIR;
assert.ok(output, 'EVIDENCE_DIR required');
await fs.mkdir(output, { recursive: true });
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
page.setDefaultTimeout(90000);
const errors = [];
const steps = [];
page.on('pageerror', (error) => errors.push(error.message));
async function login(email) {
  await page.goto(base + '/beautybook/login');
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill('BeautyBook123!');
  const response = page.waitForResponse((item) => item.url().endsWith('/api/v1/auth/login') && item.request().method() === 'POST');
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click();
  assert.equal((await response).status(), 200);
  await page.waitForURL((url) => !url.pathname.endsWith('/login'));
  await page.waitForLoadState('networkidle');
}
async function assertNoOverflow() {
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Page overflows viewport');
}
async function capture(currentPage, region, filename) {
  await region.evaluate((element) => window.scrollTo({ top: window.scrollY + element.getBoundingClientRect().top - 96, behavior: 'instant' }));
  // Finish the sidebar's resize transition and retain the sticky header context.
  await currentPage.screenshot({ path: path.join(output, filename), animations: 'disabled' });
}
try {
  await login('owner@beautybook.local');
  await page.goto(base + '/beautybook/salon/profile');
  let panel = page.getByRole('region', { name: 'Thay đổi địa chỉ và đăng ký', exact: true });
  await panel.getByRole('button', { name: 'Gửi yêu cầu xét duyệt', exact: true }).waitFor();
  await panel.getByLabel('Địa chỉ đề xuất').fill('123 Địa điểm thử nghiệm, TP Hồ Chí Minh');
  await panel.getByLabel('Quận / huyện').click();
  await page.getByRole('option').nth(1).click();
  await panel.getByLabel('Phường / xã').fill('Phường thử nghiệm');
  await panel.getByLabel('Lý do thay đổi').fill('Kiểm thử trình duyệt trên database tách biệt');
  const upload = page.waitForResponse((item) => item.url().endsWith('/api/v1/media/upload') && item.request().method() === 'POST');
  await panel.locator('input[type=file]').setInputFiles({ name: 'registration-test.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF') });
  assert.equal((await upload).status(), 200);
  await page.getByText('Đã tải 1 tài liệu an toàn', { exact: true }).waitFor();
  await assertNoOverflow();
  await capture(page, panel, 'owner-registration-desktop.png');
  const created = page.waitForResponse((item) => /\/change-requests$/.test(item.url()) && item.request().method() === 'POST');
  await panel.getByRole('button', { name: 'Gửi yêu cầu xét duyệt', exact: true }).click();
  const response = await created;
  assert.equal(response.status(), 200);
  const change = await response.json();
  steps.push('owner uploaded private PDF and submitted request');
  panel = page.getByRole('region', { name: 'Thay đổi địa chỉ và đăng ký', exact: true });
  await panel.getByText('Chờ duyệt', { exact: true }).waitFor();
  await page.setViewportSize({ width: 375, height: 812 });
  await assertNoOverflow();
  await capture(page, panel, 'owner-registration-mobile.png');
  const accessible = page.waitForResponse((item) => item.url().endsWith('/api/v1/branches/accessible'));
  await page.goto(base + '/beautybook/salon/branches/new');
  const branchRows = await (await accessible).json();
  await page.getByLabel('Loại đăng ký chi nhánh').waitFor();
  await page.getByLabel('Loại đăng ký chi nhánh').click();
  await page.getByRole('option', { name: 'Địa điểm kinh doanh', exact: true }).click();
  await assertNoOverflow();
  await capture(page, page.getByLabel('Loại đăng ký chi nhánh'), 'branch-registration-mobile.png');
  steps.push('branch registration type selector is available on mobile');
  const legacyBranch = branchRows.find((row) => row.reviewStatus === 'DRAFT' && !row.registrationType);
  assert.ok(legacyBranch, 'Seeded legacy draft branch required');
  await page.goto(base + `/beautybook/salon/branches/${legacyBranch.id}/setup`);
  const legacyType = page.getByLabel('Loại đăng ký chi nhánh');
  await legacyType.waitFor();
  assert.match(await legacyType.innerText(), /Chưa khai loại đăng ký/);
  const savedLegacy = page.waitForResponse((item) => item.url().endsWith(`/api/v1/branches/${legacyBranch.id}/onboarding`) && item.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Lưu nháp', exact: true }).click();
  const legacyResponse = await savedLegacy;
  assert.equal(legacyResponse.status(), 200);
  assert.equal(legacyResponse.request().postDataJSON().branch.registrationType, null);
  assert.equal((await legacyResponse.json()).registrationType, null);
  await page.waitForFunction(() => Array.from(document.querySelectorAll('button')).some((button) => button.textContent.trim() === 'Lưu nháp' && !button.disabled));
  await assertNoOverflow();
  await capture(page, legacyType, 'legacy-branch-registration-mobile.png');
  steps.push('legacy branch remains unclassified after a real onboarding save');
  await context.close();
  const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const adminPage = await adminContext.newPage();
  adminPage.setDefaultTimeout(90000);
  adminPage.on('pageerror', (error) => errors.push(error.message));
  await adminPage.goto(base + '/beautybook/login');
  await adminPage.locator('#login-email').fill('admin@beautybook.local');
  await adminPage.locator('#login-password').fill('BeautyBook123!');
  const auth = adminPage.waitForResponse((item) => item.url().endsWith('/api/v1/auth/login'));
  await adminPage.getByRole('button', { name: 'Đăng nhập', exact: true }).click();
  assert.equal((await auth).status(), 200);
  await adminPage.waitForURL((url) => !url.pathname.endsWith('/login'));
  await adminPage.waitForLoadState('networkidle');
  await adminPage.goto(base + '/beautybook/admin/salons?view=review');
  const queue = adminPage.getByRole('region', { name: 'Duyệt thay đổi thông tin chi nhánh', exact: true });
  await queue.getByText(change.branchName, { exact: true }).waitFor();
  const reviewNote = `Từ chối yêu cầu thử nghiệm #${change.id}; giữ nguyên địa chỉ hiện tại.`;
  await queue.getByRole('textbox').fill(reviewNote);
  await capture(adminPage, queue, 'admin-registration-desktop.png');
  const reviewed = adminPage.waitForResponse((item) => item.url().endsWith(`/branch-change-requests/${change.id}/review`));
  await queue.getByRole('button', { name: 'Từ chối', exact: true }).click();
  assert.equal((await reviewed).status(), 200);
  await queue.getByLabel('Trạng thái yêu cầu').click();
  await adminPage.getByRole('option', { name: 'Từ chối', exact: true }).click();
  await queue.getByText(reviewNote, { exact: false }).waitFor();
  await adminPage.setViewportSize({ width: 375, height: 812 });
  assert.ok(await adminPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Admin page overflows viewport');
  await capture(adminPage, queue, 'admin-registration-mobile.png');
  steps.push('admin reviewed request without changing public location');
  assert.deepEqual(errors, []);
  await fs.writeFile(path.join(output, 'browser-results.json'), JSON.stringify({ steps, errors }, null, 2));
  console.log(JSON.stringify({ passed: true, steps, errors }));
} finally {
  await browser.close();
}
