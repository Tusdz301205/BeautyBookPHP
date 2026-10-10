import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeBooking } from './bookingCalendar.adapter.js';
import { formatActualServiceTime } from './bookingActualTime.js';

test('booking duration uses the booked item snapshot after catalog duration changes', () => {
  const normalized = normalizeBooking({ bookingServices: [{ id: 'item', durationMinutes: 45, service: { id: 'service', durationMinutes: 90 } }] });
  assert.equal(normalized.services[0].durationMinutes, 45);
});

test('planned timestamps do not become actual execution times', () => {
  const normalized = normalizeBooking({ bookingServices: [{ itemStartAt: '2026-10-04T09:00:00Z', itemEndAt: '2026-10-04T10:00:00Z', updatedAt: '2026-10-04T11:00:00Z' }] });
  assert.equal(normalized.services[0].actualStartedAt, null);
  assert.equal(normalized.services[0].actualStoppedAt, null);
});

test('execution instants retain exact server values through normalization', () => {
  const item = { actualStartedAt: '2026-10-04T16:59:59Z', actualCompletedAt: '2026-10-04T17:01:00Z', actualStoppedAt: '2026-10-04T17:01:00Z', actualTimingSource: 'SERVICE_ADJUSTMENT' };
  const normalized = normalizeBooking({ bookingServices: [item] }).services[0];
  for (const key of Object.keys(item)) assert.equal(normalized[key], item[key]);
});

test('actual time respects branch timezone across midnight and rejects invalid timestamps', () => {
  const text = formatActualServiceTime('2026-10-04T17:01:00Z', 'Asia/Ho_Chi_Minh');
  assert.match(text, /05\/10\/2026/);
  assert.match(text, /00:01:00/);
  assert.equal(formatActualServiceTime(null), null);
  assert.equal(formatActualServiceTime('invalid'), null);
  assert.equal(formatActualServiceTime('2026-10-04T17:01:00Z', 'invalid'), '2026-10-04T17:01:00.000Z (UTC)');
});
