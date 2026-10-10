import test from 'node:test';
import assert from 'node:assert/strict';
import { formatBookingWallDate, formatBookingWallTime } from './bookingWallTime.js';

test('booking DATE/TIME fields keep branch wall values across device timezones', () => {
  const original = process.env.TZ;
  try {
    for (const timezone of ['UTC', 'America/Los_Angeles', 'Asia/Ho_Chi_Minh']) {
      process.env.TZ = timezone;
      assert.equal(formatBookingWallDate('2026-10-09T00:00:00.000Z'), '9/10/2026');
      assert.equal(formatBookingWallTime('1970-01-01T08:30:00.000Z'), '08:30');
      assert.equal(formatBookingWallTime('1970-01-01T09:00:00.000Z'), '09:00');
      assert.equal(formatBookingWallTime('00:15:00'), '00:15');
    }
  } finally { if (original === undefined) delete process.env.TZ; else process.env.TZ = original; }
});

test('missing or invalid booking fields remain unknown', () => {
  for (const value of [null, undefined, '', 'invalid', '25:00:00']) assert.equal(formatBookingWallTime(value), '—');
  for (const value of [null, '', 'invalid', '2026-02-30T00:00:00Z']) assert.equal(formatBookingWallDate(value), '—');
});
