import fs from 'node:fs';
import { scryptSync, timingSafeEqual } from 'node:crypto';

const source = JSON.parse(fs.readFileSync(new URL('../storage/app/private/glowme-import/source.json', import.meta.url), 'utf8'));
const rows = table => source.tables['public.' + table]?.rows || [];
const roleById = new Map(rows('roles').map(row => [row.id, row.code]));
const users = new Map(rows('users').map(row => [row.id, row]));
const results = [];
const base = 'http://localhost:8000';
for (const role of ['CUSTOMER', 'BUSINESS_OWNER', 'RECEPTIONIST', 'PLATFORM_ADMIN']) {
  let account;
  let password;
  for (const assignment of rows('user_roles').filter(row => roleById.get(row.role_id) === role && (!row.expires_at || Date.parse(row.expires_at) > Date.now()))) {
    const user = users.get(assignment.user_id);
    if (!user?.is_active || user.deleted_at) continue;
    const parts = user.password_hash.split('$');
    if (parts.length !== 6 || parts.slice(0, 4).join('$') !== 'scrypt$32768$8$1') continue;
    // Known repository demo fixtures only; never reset or infer a user's password.
    for (const candidate of ['Password123!', 'BeautyBook123!']) {
      const expected = Buffer.from(parts[5], 'hex');
      const actual = scryptSync(candidate, parts[4], 64, { N: 32768, r: 8, p: 1, maxmem: 67108864 });
      if (expected.length === actual.length && timingSafeEqual(expected, actual)) { account = user; password = candidate; break; }
    }
    if (account) break;
  }
  if (!account) { results.push({ role, status: 'NO_KNOWN_DEMO_CREDENTIAL' }); continue; }
  const cookies = new Map(); let csrf;
  async function request(url, body, token) {
    const response = await fetch(base + url, { method: body === undefined ? 'GET' : 'POST', headers: { Accept: 'application/json', Cookie: [...cookies].map(([key, value]) => key + '=' + value).join('; '), ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }), ...(token ? { Authorization: 'Bearer ' + token } : {}) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }), signal: AbortSignal.timeout(60000) });
    for (const cookie of response.headers.getSetCookie()) { const pair = cookie.split(';')[0]; const i = pair.indexOf('='); cookies.set(pair.slice(0, i), pair.slice(i + 1)); }
    csrf = response.headers.get('X-CSRF-TOKEN') || csrf;
    return response;
  }
  const shell = await request('/beautybook');
  csrf = (await shell.text()).match(/name="csrf-token" content="([^"]+)"/)?.[1];
  const response = await request('/api/v1/auth/login', { email: account.email, password, ...(role === 'CUSTOMER' ? { workspace: 'CUSTOMER' } : {}) });
  const result = { role, login: response.status };
  if (response.ok) {
    const body = await response.json();
    const token = body.accessToken;
    const bookings = await request('/api/v1/bookings', undefined, token);
    result.bookings = bookings.status;
    const logout = await request('/api/v1/auth/logout', {}, token);
    result.logout = logout.status;
  }
  results.push(result);
}
fs.writeFileSync(new URL('../storage/app/private/glowme-import/smoke.json', import.meta.url), JSON.stringify(results, null, 2));
console.log(JSON.stringify(results));
if (results.some(row => row.login && (row.login !== 200 || row.bookings !== 200 || row.logout !== 200))) process.exitCode = 1;
else if (results.some(row => row.status === 'NO_KNOWN_DEMO_CREDENTIAL')) process.exitCode = 2;
