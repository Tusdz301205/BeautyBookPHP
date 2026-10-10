import test from 'node:test';
import assert from 'node:assert/strict';
import { activeContextFor, activeScopes, bookingCapabilities, bookingItemActions, canAt, isCustomerAccount, roleAt, sessionState } from './authScope.js';
import { counterServicePayload, rowsInBranches, noShowAvailability } from './bookingAffordances.js';
import { normalizeBooking } from './bookingCalendar.adapter.js';

const scope = (code, businessId = 'business-a', branchId = null, extra = {}) => ({ code, businessId, branchId, ...extra });
const actor = (scopes, permissions = []) => ({ id: 'user-a', sessionType: 'salon', roles: scopes.map((s) => s.code), scopes, permissions });
const branchPermissions = ['booking:read:branch', 'booking:update:branch', 'booking:complete:branch', 'booking:cancel:branch', 'booking:assign:branch', 'booking:reschedule:branch', 'booking:check_in:branch', 'change_request:approve:branch'];
const ownerPermissions = ['booking:read:tenant', 'booking:update:tenant', 'booking:cancel:tenant', 'booking:assign:tenant', 'booking:check_in:tenant', 'user:role_assign:tenant'];
const booking = { branchId: 'branch-a', businessId: 'business-a', services: [{ staffId: 'staff-a', staffUserId: 'user-a' }, { staffId: 'staff-b', staffUserId: 'user-b' }] };

test('legacy GUEST is not an active account scope or a customer session', () => {
  const user = { ...actor([scope('GUEST', null)]), sessionType: 'customer' };
  assert.deepEqual(activeScopes(user), []);
  assert.equal(sessionState({ user, accessToken: 'legacy' }).user, null);
});

test('no-show UI uses strict grace boundary and resource-bound counter authority', () => {
  const startAt = new Date('2026-09-20T03:00:00Z');
  const row = { ...booking, status: 'CONFIRMED', startAt };
  const afterGrace = startAt.getTime() + 15 * 60_000 + 1;
  const receptionist = bookingCapabilities(actor([scope('RECEPTIONIST', 'business-a', 'branch-a')], branchPermissions), row);
  const owner = bookingCapabilities(actor([scope('BUSINESS_OWNER')], ownerPermissions), row);
  const staff = bookingCapabilities(actor([scope('STAFF', 'business-a', 'branch-a')], branchPermissions), row);
  assert.equal(noShowAvailability(row, receptionist, afterGrace - 1), false);
  assert.equal(noShowAvailability(row, receptionist, afterGrace), true);
  assert.equal(noShowAvailability(row, owner, afterGrace), true);
  assert.equal(noShowAvailability(row, staff, afterGrace), false);
  assert.equal(noShowAvailability(row, bookingCapabilities(actor([scope('RECEPTIONIST', 'business-a', 'other')], branchPermissions), row), afterGrace), false);
  for (const status of ['CHECKED_IN', 'CANCELLED', 'COMPLETED', 'NO_SHOW']) {
    assert.equal(noShowAvailability({ ...row, status }, receptionist, afterGrace), false);
  }
  for (const status of ['PENDING', 'REJECTED', 'EXPIRED', 'APPROVED']) {
    assert.equal(noShowAvailability({ ...row, raw: { changeRequests: [{ requestType: 'CANCEL', status }] } }, receptionist, afterGrace), false);
  }
  assert.equal(noShowAvailability({ ...row, raw: { statusHistory: [{ status: 'CHECKED_IN' }] } }, receptionist, afterGrace), false);
  assert.equal(noShowAvailability({ ...row, services: [{ status: 'COMPLETED' }] }, receptionist, afterGrace), false);
  assert.equal(noShowAvailability({ ...row, startAt: 'invalid' }, receptionist, afterGrace), false);
});

test('retired-only session fails closed even with stale permissions', () => {
  const user = actor([scope('BRANCH_MANAGER', 'business-a', 'branch-a')], ['booking:read:branch', 'branch:update:branch']);
  assert.equal(canAt(user, 'booking:read:branch'), false);
  assert.equal(roleAt(user, 'BRANCH_MANAGER'), false);
  assert.deepEqual(sessionState({ user, accessToken: 'old' }), { user: null, accessToken: null, initialized: true });
});

test('refresh replaces stale metadata and keeps only retained active grants', () => {
  const freshUser = actor([scope('BRANCH_MANAGER', 'business-a', 'branch-a'), scope('RECEPTIONIST', 'business-a', 'branch-a')], branchPermissions);
  freshUser.roles = ['BRANCH_MANAGER', 'RECEPTIONIST'];
  const state = sessionState({ user: freshUser, accessToken: 'new-token' });
  assert.equal(state.accessToken, 'new-token');
  assert.deepEqual(state.user.roles, ['RECEPTIONIST']);
  assert.equal(state.user.scopes.length, 1);
  assert.equal(canAt(state.user, 'booking:complete:branch'), false);
  assert.equal(canAt(state.user, 'booking:assign:branch'), true);
});

test('expired, invalid-expiry and incomplete branch grants never authorize no-context controls', () => {
  const user = actor([
    scope('RECEPTIONIST', 'business-a', 'branch-a', { expiresAt: '2000-01-01' }),
    scope('STAFF', 'business-a', 'branch-a', { expiresAt: 'invalid' }),
    scope('RECEPTIONIST', 'business-a'),
    scope('STAFF', null, 'branch-a'),
  ], branchPermissions);
  assert.deepEqual(activeScopes(user), []);
  assert.equal(canAt(user, 'booking:read:branch'), false);
  assert.deepEqual(activeContextFor(user), { tenantId: null, branchId: null });
});

test('Owner scope is tenant-bound and cannot borrow another branch-role business', () => {
  const user = actor([scope('BUSINESS_OWNER'), scope('RECEPTIONIST', 'business-b', 'branch-b')], [...ownerPermissions, ...branchPermissions]);
  assert.equal(canAt(user, 'user:role_assign:tenant', { tenantId: 'business-a' }), true);
  assert.equal(canAt(user, 'user:role_assign:tenant', { tenantId: 'business-b' }), false);
  assert.equal(canAt(user, 'booking:update:tenant', { branchId: 'branch-b' }), false);
  assert.equal(bookingCapabilities(user, { businessId: 'business-b', branchId: 'branch-b' }).owner, false);
  assert.equal(bookingCapabilities(user, { businessId: 'business-b', branchId: 'branch-b' }).canAssign, true);
});

test('Receptionist retains all branch booking actions, never foreign branch or tenant admin', () => {
  const user = actor([scope('RECEPTIONIST', 'business-a', 'branch-a')], [...branchPermissions, 'user:role_assign:tenant', 'branch:update:branch']);
  for (const code of ['booking:cancel:branch', 'booking:assign:branch', 'booking:reschedule:branch', 'booking:check_in:branch', 'change_request:approve:branch']) {
    assert.equal(canAt(user, code, { tenantId: 'business-a', branchId: 'branch-a' }), true, code);
    assert.equal(canAt(user, code, { tenantId: 'business-a', branchId: 'branch-b' }), false, code);
    assert.equal(canAt(user, code, { tenantId: 'business-b', branchId: 'branch-a' }), false, code);
  }
  assert.equal(canAt(user, 'user:role_assign:tenant'), false);
  assert.equal(canAt(user, 'branch:update:branch'), false);
});

test('Receptionist item actions exclude provider and commercial overrides', () => {
  const user = actor([scope('RECEPTIONIST', 'business-a', 'branch-a')], branchPermissions);
  assert.deepEqual(bookingItemActions(user, booking, booking.services[0]), ['REMOVE', 'SKIP', 'REASSIGN']);
  assert.equal(bookingCapabilities(user, booking).canProvide, false);
  assert.deepEqual(bookingItemActions(user, { ...booking, branchId: 'branch-b' }, booking.services[0]), []);
});

test('Staff may start or complete only the exact assigned item', () => {
  const user = actor([scope('STAFF', 'business-a', 'branch-a')], branchPermissions);
  assert.deepEqual(bookingItemActions(user, booking, booking.services[0]), ['START', 'COMPLETE']);
  assert.deepEqual(bookingItemActions(user, booking, booking.services[1]), []);
  assert.deepEqual(bookingItemActions(user, { ...booking, branchId: 'branch-b' }, booking.services[0]), []);
  assert.equal(bookingCapabilities(user, { ...booking, services: [booking.services[1]] }).canProvide, false);
});

test('Staff role without current permission cannot operate an assigned item', () => {
  const user = actor([scope('STAFF', 'business-a', 'branch-a')]);
  assert.deepEqual(bookingItemActions(user, booking, booking.services[0]), []);
});

test('Owner item overrides stay within owned business', () => {
  const user = actor([scope('BUSINESS_OWNER')], ownerPermissions);
  assert.deepEqual(bookingItemActions(user, booking, booking.services[0]), ['START', 'COMPLETE', 'REMOVE', 'SKIP', 'REASSIGN', 'RESIZE', 'REPRICE']);
  assert.deepEqual(bookingItemActions(user, { ...booking, businessId: 'business-b' }, booking.services[0]), []);
});

test('mixed Owner and Staff cannot carry owner actions to Staff-only branch', () => {
  const user = actor([scope('BUSINESS_OWNER', 'business-b'), scope('STAFF', 'business-a', 'branch-a')], [...ownerPermissions, ...branchPermissions]);
  assert.deepEqual(bookingItemActions(user, booking, booking.services[0]), ['START', 'COMPLETE']);
});

test('active context ignores retired/Customer scopes and prefers a valid Owner tenant', () => {
  const user = actor([scope('CUSTOMER', null), scope('BRANCH_MANAGER', 'business-old', 'branch-old'), scope('BUSINESS_OWNER'), scope('RECEPTIONIST', 'business-b', 'branch-b')]);
  assert.deepEqual(activeContextFor(user), { tenantId: 'business-a', branchId: null });
});

test('Customer, Platform and pending Owner sessions keep their valid workspaces', () => {
  for (const [code, type] of [['CUSTOMER', 'customer'], ['PLATFORM_ADMIN', 'admin'], ['BUSINESS_OWNER', 'salon']]) {
    const user = { ...actor([scope(code, null)]), sessionType: type };
    assert.equal(sessionState({ user, accessToken: 'valid' }).user.sessionType, type);
  }
  const pendingOwner = actor([scope('BUSINESS_OWNER', null)], ['business:create:self', 'business:update:tenant']);
  assert.equal(canAt(pendingOwner, 'business:create:self'), true);
  assert.equal(canAt(pendingOwner, 'business:update:tenant'), false);
});

test('self permissions do not authorize another account', () => {
  const user = actor([scope('CUSTOMER', null)], ['user:read:self']);
  assert.equal(canAt(user, 'user:read:self', { ownerUserId: user.id }), true);
  assert.equal(canAt(user, 'user:read:self', { ownerUserId: 'other' }), false);
});

test('operational and legacy mixed accounts cannot act as Customer', () => {
  for (const role of ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF', 'PLATFORM_ADMIN']) {
    const user = { ...actor([scope('CUSTOMER'), scope(role, 'business-a', 'branch-a')], ['booking:create:self']), sessionType: 'customer' };
    assert.equal(isCustomerAccount(user), false);
    assert.equal(canAt(user, 'booking:create:self'), false);
    assert.equal(sessionState({ user, accessToken: 'stale' }).user, null);
  }
});

test('counter-service payload omits price and duration, even if input contains overrides', () => {
  const payload = counterServicePayload({ serviceId: 'service-a', reason: '  Add at counter  ', price: 0, durationMinutes: 5 });
  assert.deepEqual(payload, { serviceId: 'service-a', reason: 'Add at counter' });
  assert.equal(Object.hasOwn(payload, 'price'), false);
  assert.equal(Object.hasOwn(payload, 'durationMinutes'), false);
});

test('queue rows are strictly branch-bound, including nested change requests', () => {
  const rows = [{ id: 'a', branchId: 'branch-a' }, { id: 'b', branch_id: 'branch-b' }, { id: 'c', booking: { branchId: 'branch-a' } }, { id: 'd' }];
  assert.deepEqual(rowsInBranches(rows, ['branch-a']).map((row) => row.id), ['a', 'c']);
  assert.deepEqual(rowsInBranches(rows, []), []);
  assert.deepEqual(rowsInBranches(rows, ['branch-b']).map((row) => row.id), ['b']);
});

test('booking normalization retains actual business and staff-user scope', () => {
  const normalized = normalizeBooking({ id: 'booking-a', branch: { id: 'branch-a', businessId: 'business-a' }, bookingServices: [{ id: 'item-a', staff: { id: 'staff-a', user: { id: 'user-a' } }, service: { id: 'service-a' } }] });
  assert.equal(normalized.businessId, 'business-a');
  assert.equal(normalized.services[0].staffUserId, 'user-a');
});
