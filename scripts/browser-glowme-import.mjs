import fs from 'node:fs/promises';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');

const base = 'http://localhost:8000';
const output = new URL('../storage/app/private/glowme-import/browser/', import.meta.url);
await fs.mkdir(output, { recursive: true });
const first = body => (Array.isArray(body) ? body : body.data)[0];
const branch = first(await (await fetch(base + '/api/v1/branches')).json());
const service = first(await (await fetch(base + '/api/v1/services/search?limit=1')).json());
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [], errors = [];
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  page.on('pageerror', error => errors.push({ type: 'javascript', message: error.message }));
  page.on('response', response => {
    if (response.url().includes('/api/v1/') && response.status() >= 400 && !response.url().includes('/auth/refresh')) errors.push({ type: 'api', status: response.status(), url: response.url() });
  });
  let index = 0;
  for (const route of ['/beautybook', '/beautybook/explore', '/beautybook/explore/branches/' + branch.id, '/beautybook/explore/services/' + service.id]) {
    const response = await page.goto(base + route, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.locator('h1').first().waitFor({ timeout: 60000 });
    await page.waitForTimeout(2000);
    await page.locator('img').evaluateAll(images => images.forEach(image => { image.loading = 'eager'; }));
    await page.waitForFunction(() => [...document.images].every(image => image.complete), {}, { timeout: 15000 }).catch(() => {});
    const broken = await page.locator('img').evaluateAll(images => images.filter(image => image.src && (!image.complete || image.naturalWidth === 0)).length);
    await page.screenshot({ path: new URL(`page-${++index}.png`, output).pathname.replace(/^\/([A-Za-z]:)/, '$1'), fullPage: false });
    results.push({ route, status: response.status(), brokenImages: broken });
  }
} finally { await browser.close(); }
const report = { pages: results, errors };
await fs.writeFile(new URL('results.json', output), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
if (errors.length || results.some(row => row.status !== 200 || row.brokenImages)) process.exitCode = 1;
