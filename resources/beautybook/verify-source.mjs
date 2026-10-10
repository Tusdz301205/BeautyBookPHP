import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const source = process.argv[2] || 'C:/Users/Admin/Downloads/beauty-booking-api-main/beauty-booking-web-main/beauty-booking-web-main';
const copy = path.resolve('resources/beautybook');
const seams = new Set(['src/App.jsx', 'src/api/apiClient.js', 'src/styles/theme.css', 'src/pages/Customer/BookingConfirm.jsx', 'src/components/layout/PublicShell.jsx']);
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
async function files(dir, prefix = '') {
  const result = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const rel = `${prefix}${entry.name}`;
    if (entry.isDirectory()) result.push(...await files(path.join(dir, entry.name), `${rel}/`));
    else result.push(rel);
  }
  return result;
}
const rows = [];
for (const rel of [...await files(path.join(source, 'src'), 'src/'), 'tokens.css']) {
  const original = sha(await readFile(path.join(source, rel)));
  const copied = sha(await readFile(path.join(copy, rel)));
  rows.push({ path: rel, original, copied, equal: original === copied, integrationSeam: seams.has(rel) });
}
const api = await readFile(path.join(source, 'src/api/apiClient.js'), 'utf8');
const calls = [...api.matchAll(/request\(\s*(['"`])([\s\S]*?)\1(?:\s*,\s*\{([\s\S]*?)\}\))?/g)].map(match => ({
  source: 'src/api/apiClient.js',
  line: api.slice(0, match.index).split('\n').length,
  endpoint: match[2],
  method: match[3]?.match(/method:\s*['"]([^'"]+)/)?.[1] || 'GET',
})).filter(call => call.endpoint.startsWith('/'));
calls.unshift({ source: 'src/api/apiClient.js', line: 15, endpoint: '/auth/refresh', method: 'POST' });
const assets = [];
for (const rel of await files(path.join(source, 'public/images'))) {
  const original = sha(await readFile(path.join(source, 'public/images', rel)));
  const copied = sha(await readFile(path.resolve('public/images', rel)));
  assets.push({ path: `public/images/${rel}`, original, copied, equal: original === copied });
}
await writeFile(path.join(copy, 'source-verification.json'), JSON.stringify({ rows, sourceFiles: rows.length, unchanged: rows.filter(row => row.equal).length, unexpectedChanges: rows.filter(row => !row.equal && !row.integrationSeam) }, null, 2));
await writeFile(path.join(copy, 'asset-verification.json'), JSON.stringify({ assets, sourceAssets: assets.length, unchanged: assets.filter(row => row.equal).length }, null, 2));
await writeFile(path.join(copy, 'api-call-inventory.json'), JSON.stringify(calls, null, 2));
console.log(JSON.stringify({ sourceFiles: rows.length, unchanged: rows.filter(row => row.equal).length, sourceAssets: assets.length, unchangedAssets: assets.filter(row => row.equal).length, integrationChanges: rows.filter(row => !row.equal).map(row => row.path), calls: calls.length, unexpectedChanges: rows.filter(row => !row.equal && !row.integrationSeam).length }));
if (rows.some(row => !row.equal && !row.integrationSeam) || assets.some(row => !row.equal)) process.exitCode = 1;
