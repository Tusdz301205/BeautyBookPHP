import test from 'node:test';
import assert from 'node:assert/strict';
import { customerCancellationMode, submitCustomerCancellation } from './customerCancellation.js';

const booking = { id: 'booking', status: 'CONFIRMED', appointmentDate: '2026-09-20', appointmentStartTime: '1970-01-01T18:00:00.000Z' };
// SQL TIME is a wall-clock value, not a UTC instant. Match the app's adapter.
const at = (value) => new Date(`2026-09-20T${value}`).getTime();

for (const [time, expected] of [['13:59:59.999', 'direct'], ['14:00:00.000', 'direct'], ['14:00:00.001', 'request'], ['17:59:59.999', 'request'], ['18:00:00.001', 'unavailable']]) {
  test(`cancellation mode at ${time} is ${expected}`, () => assert.equal(customerCancellationMode(booking, at(time)), expected));
}
test('terminal and checked-in bookings cannot be cancelled as a customer', () => {
  for (const status of ['CANCELLED', 'NO_SHOW', 'COMPLETED', 'CHECKED_IN', 'IN_PROGRESS']) {
    assert.equal(customerCancellationMode({ ...booking, status }, at('13:00:00')), 'unavailable');
  }
});
test('pending request is shown and duplicate submission is blocked', async () => {
  const pending = { ...booking, changeRequests: [{ status: 'PENDING', expiresAt: new Date(at('19:00:00')).toISOString() }] };
  assert.equal(customerCancellationMode(pending, at('15:00:00')), 'pending');
  assert.equal(customerCancellationMode(pending, at('18:30:00')), 'pending');
  await assert.rejects(submitCustomerCancellation({}, pending, 'Reason', at('18:30:00')), /chờ cơ sở/);
  await assert.rejects(submitCustomerCancellation({}, pending, 'Reason', at('15:00:00')), /chờ cơ sở/);
});
test('expired pending metadata does not block a new request', () => {
  assert.equal(customerCancellationMode({ ...booking, changeRequests: [{ status: 'PENDING', expiresAt: new Date(at('14:00:00')).toISOString() }] }, at('15:00:00')), 'request');
});
test('late cancellation sends a change request, never a status update', async () => {
  const calls = [];
  const api = { createChangeRequest: async (...args) => calls.push(args), updateStatus: async () => assert.fail('direct cancellation must not run') };
  assert.match(await submitCustomerCancellation(api, booking, ' Reason ', at('15:00:00')), /chỉ được hủy khi/);
  assert.deepEqual(calls, [['booking', { requestType: 'CANCEL', reason: 'Reason' }]]);
});
test('on-time cancellation updates status without creating a request', async () => {
  const calls = [];
  const api = { updateStatus: async (...args) => calls.push(args), createChangeRequest: async () => assert.fail('request must not run') };
  assert.equal(await submitCustomerCancellation(api, booking, 'Reason', at('14:00:00')), 'Đã hủy lịch');
  assert.deepEqual(calls, [['booking', 'CANCELLED', undefined, 'Reason']]);
});
test('network errors are not presented as successful cancellation', async () => {
  await assert.rejects(submitCustomerCancellation({ createChangeRequest: async () => { throw new Error('API failed'); } }, booking, 'Reason', at('15:00:00')), /API failed/);
});
