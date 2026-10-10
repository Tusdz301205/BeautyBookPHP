import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'file:///C:/Users/Admin/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
const browser=await chromium.launch({channel:'chrome',headless:true});
const page=await browser.newPage();
const base=process.env.BEAUTYBOOK_URL || 'http://localhost:8000';
try {
  await page.goto(`${base}/beautybook/login`);
  await page.locator('#login-email').fill('owner@beautybook.local');
  await page.locator('#login-password').fill('BeautyBook123!');
  await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();
  await page.waitForURL('**/beautybook/salon');
  await page.goto(`${base}/beautybook/explore`);
  await page.locator('h1').first().waitFor();
  const link=page.locator('.bb-public-shell a[href^="/beautybook/book"]').first();
  await link.waitFor({timeout:60000});
  const before=page.url();
  await link.click();
  await page.getByText('Tài khoản vận hành chỉ xem nội dung công khai.',{exact:false}).waitFor({timeout:10000});
  assert.equal(page.url(),before,'Owner remains on marketplace after customer link guard');
  console.log(JSON.stringify({basenameCustomerGuard:true,ownerNavigationPrevented:true}));
} finally { await browser.close(); }
