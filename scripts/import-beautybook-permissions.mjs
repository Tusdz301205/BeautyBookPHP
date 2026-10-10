import fs from 'node:fs';
const source = fs.readFileSync('C:/Users/Admin/Downloads/beauty-booking-api-main/beauty-booking-api-main/src/common/permissions/permission-catalog.ts', 'utf8');
const matrix = source.slice(source.indexOf('export const ROLE_PERMISSIONS'), source.indexOf('export const ROLE_LEVELS'));
const roles = {};
for (const [, role, block] of matrix.matchAll(/(PLATFORM_ADMIN|BUSINESS_OWNER|RECEPTIONIST|STAFF|CUSTOMER):\s*\[([\s\S]*?)\]/g)) roles[role] = [...block.matchAll(/'([^']+)'/g)].map(m => m[1]);
fs.mkdirSync('app/Services/Beautybook', { recursive: true });
fs.writeFileSync('app/Services/Beautybook/permissions.json', JSON.stringify(roles, null, 2));
console.log(Object.fromEntries(Object.entries(roles).map(([k,v]) => [k,v.length])));
