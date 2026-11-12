import test from 'node:test';
import assert from 'node:assert/strict';
import { rescheduleWindow, visitWindow } from './bookingPolicy.js';

const policy = { rescheduleHours: 24, rescheduleUntil: '2026-11-20T03:00:00+00:00', checkInFrom: '2026-11-21T02:30:00+00:00', noShowAfter: '2026-11-21T03:15:00+00:00' };

test('a customer can ask to reschedule until the branch cutoff and not after it', () => {
  assert.deepEqual({ ...rescheduleWindow(policy, Date.parse('2026-11-20T03:00:00Z')), until: undefined }, { open: true, hours: 24, until: undefined });
  assert.equal(rescheduleWindow(policy, Date.parse('2026-11-20T03:00:01Z')).open, false);
  assert.equal(rescheduleWindow({ rescheduleHours: 0, rescheduleUntil: null }, Date.parse('2030-01-01T00:00:00Z')).open, true, 'no cutoff configured');
});

test('check-in opens before the start by the branch minutes and no-show only after the grace', () => {
  assert.equal(visitWindow(policy, Date.parse('2026-11-21T02:29:59Z')).checkInOpen, false);
  assert.equal(visitWindow(policy, Date.parse('2026-11-21T02:30:00Z')).checkInOpen, true);
  assert.equal(visitWindow(policy, Date.parse('2026-11-21T03:15:00Z')).noShowOpen, false, 'strictly after the grace');
  assert.equal(visitWindow(policy, Date.parse('2026-11-21T03:15:01Z')).noShowOpen, true);
  assert.deepEqual(visitWindow(null), { checkInOpen: false, checkInFrom: null, noShowOpen: false, noShowAfter: null });
});
