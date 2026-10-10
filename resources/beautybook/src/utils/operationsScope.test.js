import test, { mock } from 'node:test';
import assert from 'node:assert/strict';
import { loadOperationsData, operationRows, operationsContext, operationsRights, operationsTab } from './operationsScope.js';

const permissions = ['booking:read:tenant', 'booking:update:tenant', 'booking:read:branch', 'booking:update:branch', 'payment:read:tenant', 'payment:create:tenant', 'payment:read:branch', 'payment:create:branch', 'promotion:manage:tenant', 'report:revenue:tenant', 'business:update:tenant'];
const grant = (code, businessId, branchId = null) => ({ code, businessId, branchId });
const actor = (scopes) => ({ id: 'user', scopes, permissions });
const branches = [
  { id: 'owned-one', businessId: 'owned' }, { id: 'owned-two', business: { id: 'owned' } },
  { id: 'counter', businessId: 'other' }, { id: 'staff-only', businessId: 'other' },
  { id: 'no-business' },
];
const mixed = actor([grant('BUSINESS_OWNER', 'owned'), grant('RECEPTIONIST', 'other', 'counter'), grant('STAFF', 'other', 'staff-only')]);

test('operational branch selector excludes staff-only grants and derives the selected business', () => {
  const context = operationsContext(mixed, branches, 'counter');
  assert.deepEqual(context.branches.map((branch) => branch.id), ['owned-one', 'owned-two', 'counter']);
  assert.equal(context.branchId, 'counter');
  assert.equal(context.businessId, 'other');
  assert.equal(operationsContext(mixed, branches, 'staff-only').branchId, 'owned-one');
  assert.equal(operationsContext(mixed, branches, 'owned-two').businessId, 'owned');
});

test('receptionist only receives operator access and no owner-only operations tab', () => {
  const rights = operationsRights(mixed, operationsContext(mixed, branches, 'counter'));
  assert.deepEqual(rights, { owner: false, operator: true, impact: false, ownership: false });
  assert.equal(operationsTab('impact', rights), '');
  assert.equal(operationsTab('ownership', rights), '');
});

test('owner receives impact and ownership operations inside the owned business', () => {
  const rights = operationsRights(mixed, operationsContext(mixed, branches, 'owned-two'));
  assert.deepEqual(rights, { owner: true, operator: true, impact: true, ownership: true });
  assert.equal(operationsTab('impact', rights), 'impact');
  assert.equal(operationsTab('ownership', rights), 'ownership');
});

test('expired or retired scopes cannot render stale panels or select stale branches', () => {
  const user = actor([grant('BRANCH_MANAGER', 'owned', 'owned-one'), { ...grant('RECEPTIONIST', 'other', 'counter'), expiresAt: '2000-01-01' }]);
  const context = operationsContext(user, branches, 'counter');
  assert.equal(context.branchId, '');
  assert.deepEqual(context.branches, []);
  assert.equal(operationsTab('impact', operationsRights(user, context)), '');
});

test('a new owner without a branch keeps business-level operations without inventing a branch', () => {
  const user = actor([grant('BUSINESS_OWNER', 'owned')]);
  const context = operationsContext(user, []);
  const rights = operationsRights(user, context);
  assert.equal(context.businessId, 'owned');
  assert.equal(context.branchId, '');
  assert.equal(rights.impact, true);
  assert.equal(rights.ownership, true);
});

test('missing permissions hide owner actions even when the owner role exists', () => {
  const user = { ...mixed, permissions: ['booking:read:tenant', 'report:revenue:tenant'] };
  const rights = operationsRights(user, operationsContext(user, branches, 'owned-one'));
  assert.equal(rights.owner, true);
  assert.equal(rights.operator, true);
  assert.equal(rights.impact, false);
  assert.equal(rights.ownership, false);
});

function apiFixture() {
  const fn = () => mock.fn(async () => []);
  return { impactApi: { list: fn() }, ownershipApi: { list: fn(), versions: fn() } };
}

test('receptionist loader sends no owner-only operations requests', async () => {
  const api = apiFixture();
  const result = await loadOperationsData(mixed, operationsContext(mixed, branches, 'counter'), api);
  assert.deepEqual(result, { impacts: [], transfers: [], versions: null });
  for (const group of Object.values(api)) for (const fn of Object.values(group)) assert.equal(fn.mock.callCount(), 0);
});

test('staff-only loader sends no requests even if stale flattened permissions remain', async () => {
  const api = apiFixture();
  const user = actor([grant('STAFF', 'other', 'staff-only')]);
  await loadOperationsData(user, { businessId: 'other', branchId: 'staff-only' }, api);
  for (const group of Object.values(api)) for (const fn of Object.values(group)) assert.equal(fn.mock.callCount(), 0);
});

test('owner requests use the actual selected business and filter impact rows by business', async () => {
  const api = apiFixture();
  api.impactApi.list = mock.fn(async () => [{ id: 'a', businessId: 'owned' }, { id: 'b', businessId: 'other' }]);
  const result = await loadOperationsData(mixed, operationsContext(mixed, branches, 'owned-two'), api);
  assert.deepEqual(api.ownershipApi.list.mock.calls[0].arguments, ['owned']);
  assert.deepEqual(api.ownershipApi.versions.mock.calls[0].arguments, ['owned']);
  assert.deepEqual(result.impacts.map((row) => row.id), ['a']);
  assert.deepEqual(operationRows([{ id: 'a', businessId: 'owned' }, { id: 'b', businessId: 'other' }], { businessId: 'owned' }, 'business').map((row) => row.id), ['a']);
});
