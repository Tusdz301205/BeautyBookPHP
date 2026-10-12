import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const center = readFileSync(new URL('../resources/beautybook/src/components/notifications/NotificationCenter.jsx', import.meta.url), 'utf8');
// Exercise the component's actual URL policy without mounting React or importing transport code.
const policy = center.slice(center.indexOf('const notificationRoutes = {'), center.indexOf('const timeAgo ='));
assert.ok(policy.includes('function allowedNotificationUrl'));
const sandbox = vm.createContext({ URL });
vm.runInContext(`${policy}\nglobalThis.allow = allowedNotificationUrl;`, sandbox);
const allow = sandbox.allow;

test('removed destinations stay blocked with query strings, fragments, descendants and encoded names', () => {
  for (const [zone, path] of [['salon', '/salon/audit'], ['salon', '/salon/stats'], ['salon', '/salon/incoming-ownership'], ['platform', '/admin/ownership']]) {
    for (const suffix of ['', '?tab=details', '#details', '/details', '/']) {
      assert.equal(allow(path + suffix, zone, () => true, {}, true, true), '');
    }
    assert.equal(allow(path.replace(/.$/, character => `%${character.charCodeAt(0).toString(16)}`), zone, () => true, {}, true, true), '');
  }
});

test('non-owner staff retain ownership invitations and permitted reports', () => {
  assert.equal(allow('/salon/incoming-ownership?invitation=1', 'salon', () => false), '/salon/incoming-ownership?invitation=1');
  assert.equal(allow('/salon/stats', 'salon', code => code === 'report:revenue:branch'), '/salon/stats');
  assert.equal(allow('/salon/stats', 'salon', () => false), '');
  assert.equal(allow('/salon/incoming-ownership', 'salon', () => true, {}, false, true), '');
});

test('original allowlist rejects unsafe, unknown, cross-zone and retired privacy destinations', () => {
  for (const url of ['https://example.com', '//example.com', '/salon/unknown', '/salon/services/../staff', '/salon/%2e%2e/staff', '/salon/%252e%252e/staff', '/salon/%', '/salon/sta ff', '/salon/staff\\details', '/admin/users']) {
    assert.equal(allow(url, 'salon', () => true), '');
  }
  for (const url of ['/customer/privacy', '/customer/data-requests']) {
    assert.equal(allow(url, 'customer', () => true), '');
  }
});

test('retained routes enforce permissions and pass the notification scope', () => {
  const item = { relatedBooking: { businessId: 'tenant-1', branchId: 'branch-1', customerId: 'customer-1' } };
  const calls = [];
  assert.equal(allow('/salon/appointments?bookingId=booking-1', 'salon', (code, context) => {
    calls.push([code, { ...context }]);
    return code === 'booking:read:branch';
  }, item), '/salon/appointments?bookingId=booking-1');
  assert.deepEqual(calls, [
    ['booking:read:tenant', { tenantId: 'tenant-1', branchId: 'branch-1', ownerUserId: 'customer-1' }],
    ['booking:read:branch', { tenantId: 'tenant-1', branchId: 'branch-1', ownerUserId: 'customer-1' }],
  ]);
  assert.equal(allow('/salon/staff', 'salon', () => false), '');
  assert.equal(allow('/admin/audit', 'platform', () => true), '/admin/audit');
  assert.equal(allow('/admin/audit', 'platform', () => false), '');
  assert.equal(allow('/customer/appointments/booking-1', 'customer', () => false), '/customer/appointments/booking-1');
  assert.equal(allow('/book/staff', 'customer', () => false), '/book/staff');
  assert.equal(allow('/explore/branches/branch-1', 'customer', () => false), '/explore/branches/branch-1');
});

test('finance routes remain gated by feature availability', () => {
  for (const [zone, url] of [['salon', '/salon/payments'], ['platform', '/admin/payments']]) {
    assert.equal(allow(url, zone, () => true), '');
    assert.equal(allow(url, zone, () => true, {}, true), url);
    assert.equal(allow(url, zone, () => false, {}, true), '');
  }
});
