import test from 'node:test';
import assert from 'node:assert/strict';
import { pageInfo } from './depositAdmin.js';

test('the paging line reports the range shown and whether there is a previous or next page', () => {
  assert.deepEqual(pageInfo({ page: 1, pageSize: 20, total: 45, hasMore: true }), { page: 1, pageCount: 3, total: 45, from: 1, to: 20, hasPrev: false, hasNext: true });
  assert.deepEqual(pageInfo({ page: 3, pageSize: 20, total: 45, hasMore: false }), { page: 3, pageCount: 3, total: 45, from: 41, to: 45, hasPrev: true, hasNext: false });
  assert.deepEqual(pageInfo({ page: 1, pageSize: 20, total: 0, hasMore: false }), { page: 1, pageCount: 1, total: 0, from: 0, to: 0, hasPrev: false, hasNext: false });
  assert.equal(pageInfo(null).hasPrev, false);
});
