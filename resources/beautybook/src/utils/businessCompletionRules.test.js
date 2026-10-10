import test from 'node:test';
import assert from 'node:assert/strict';
import { ownershipVersionsReady, savedServiceBookingPath } from './businessCompletionRules.js';
import { sanitizeApiErrorMessage, toUserFacingRequestError } from './requestError.js';

test('ownership approval is ready only after both versions are verified', () => {
  assert.equal(ownershipVersionsReady({ legalEntityVersion: { verificationStatus: 'VERIFIED' }, payoutAccountVersion: { verificationStatus: 'PENDING' } }), false);
  assert.equal(ownershipVersionsReady({ legalEntityVersion: { verificationStatus: 'VERIFIED' }, payoutAccountVersion: { verificationStatus: 'VERIFIED' } }), true);
});

test('saved service never creates a dead booking link when unavailable', () => {
  assert.equal(savedServiceBookingPath({ available: false, offering: { id: 'service-1', branchId: 'branch-1' } }), null);
  assert.equal(savedServiceBookingPath({ available: true, offering: { id: 'service 1', branchId: 'branch 1' } }), '/book?branchId=branch%201&serviceId=service%201');
});

test('server errors are never exposed as a raw English message', () => {
  assert.equal(
    sanitizeApiErrorMessage(500, 'Internal server error'),
    'Máy chủ đang gặp sự cố tạm thời. Vui lòng thử lại sau.',
  );
  const result = toUserFacingRequestError({ status: 500, message: 'Internal server error' }, 'Không thể tải lịch hẹn');
  assert.equal(result.title, 'Không thể tải lịch hẹn');
  assert.equal(result.kind, 'server');
  assert.equal(result.retryable, true);
  assert.doesNotMatch(result.message, /internal server error/i);
});

test('request error states distinguish permission and network failures', () => {
  assert.deepEqual(
    toUserFacingRequestError({ status: 403, message: 'Forbidden resource' }).kind,
    'authorization',
  );
  assert.deepEqual(
    toUserFacingRequestError(new TypeError('Failed to fetch')).kind,
    'network',
  );
});
