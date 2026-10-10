import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeBooking } from './bookingCalendar.adapter.js';
import { anchoredServerNow, bookingOperationalLabels, calendarServerNow, itemOperationalLabel, serverTimeAnchor, SERVICE_STATUS_LABELS } from './operationalTiming.js';
import { branchWallTimeToInstant, formatBranchSlotTime } from './branchSlotTime.js';

const item = { status: 'SCHEDULED', itemStartAt: '2026-10-06T22:00:00+07:00', itemEndAt: '2026-10-06T22:30:00+07:00', actualStartedAt: null };
const start = Date.parse(item.itemStartAt), end = Date.parse(item.itemEndAt);
test('start is inclusive, end is strictly exceeded; 22:52 requires verification', () => {
  const label = (now) => itemOperationalLabel('CONFIRMED', item, now);
  assert.equal(label(start - 1), null);
  assert.equal(label(start), 'Đã đến giờ — chưa bắt đầu');
  assert.equal(label(end), 'Đã đến giờ — chưa bắt đầu');
  assert.equal(label(end + 1), 'Quá giờ dự kiến — cần kiểm tra');
  assert.equal(label(Date.parse('2026-10-06T22:52:00+07:00')), 'Quá giờ dự kiến — cần kiểm tra');
  assert.equal(item.actualStartedAt, null);
  assert.equal(item.status, 'SCHEDULED');
});
test('running overrun and real start evidence never become unstarted or complete', () => {
  assert.equal(itemOperationalLabel('IN_PROGRESS', { ...item, status: 'IN_PROGRESS' }, end), null);
  assert.equal(itemOperationalLabel('IN_PROGRESS', { ...item, status: 'IN_PROGRESS' }, end + 1), 'Đang phục vụ quá giờ dự kiến');
  assert.equal(itemOperationalLabel('CONFIRMED', { ...item, actualStartedAt: '2026-10-06T15:02:00Z' }, end + 1), null);
});
test('terminal parent or item suppresses every warning; incomplete/ambiguous time suppresses inference', () => {
  for (const status of ['COMPLETED', 'CANCELLED', 'NO_SHOW', 'SKIPPED', 'REJECTED', 'EXPIRED']) {
    assert.equal(itemOperationalLabel(status, item, end + 1), null);
    assert.equal(itemOperationalLabel('CHECKED_IN', { ...item, status }, end + 1), null);
  }
  for (const change of [{ itemStartAt: null }, { itemEndAt: 'invalid' }, { itemStartAt: '2026-10-06T22:00:00' }, { itemEndAt: item.itemStartAt, itemStartAt: item.itemEndAt }]) {
    assert.equal(itemOperationalLabel('CONFIRMED', { ...item, ...change }, end + 1), null);
    assert.equal(itemOperationalLabel('IN_PROGRESS', { ...item, ...change, status: 'IN_PROGRESS' }, end + 1), null);
  }
  assert.equal(itemOperationalLabel('CONFIRMED', item, null), null);
});

test('rejected and expired parents suppress stale scheduled and running child labels after normalization', () => {
  for (const status of ['REJECTED', 'EXPIRED']) {
    const booking = normalizeBooking({ statusEnum: status, serverNow: '2026-10-06T22:52:00+07:00',
      services: [item, { ...item, status: 'IN_PROGRESS' }] });
    assert.deepEqual(bookingOperationalLabels(booking, booking.timingAnchor.elapsed), []);
  }
});
test('fake monotonic clock crosses boundaries even with wildly wrong device wall time', (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: 1 });
  let elapsed = 500;
  const anchor = serverTimeAnchor('2026-10-06T21:59:59+07:00', elapsed);
  const label = () => itemOperationalLabel('CONFIRMED', item, anchoredServerNow(anchor, elapsed));
  assert.equal(label(), null);
  elapsed += 1000;
  assert.equal(label(), 'Đã đến giờ — chưa bắt đầu');
  t.mock.timers.setTime(Date.parse('2099-01-01T00:00:00Z'));
  assert.equal(label(), 'Đã đến giờ — chưa bắt đầu');
  elapsed += 30 * 60000 + 1;
  assert.equal(label(), 'Quá giờ dự kiến — cần kiểm tra');
  assert.equal(anchoredServerNow(anchor, 0), anchor.server);
  assert.equal(serverTimeAnchor('invalid', elapsed), null);
  assert.equal(anchoredServerNow(null, elapsed), null);
  const refreshed = serverTimeAnchor('2026-10-07T00:01:00+07:00', elapsed);
  assert.equal(anchoredServerNow(refreshed, elapsed), Date.parse('2026-10-06T17:01:00Z'));
});
test('normalization retains server instants/IDs; mixed services and check-in have neutral labels', () => {
  for (const status of ['CONFIRMED', 'CHECKED_IN']) {
    const booking = normalizeBooking({ bookingId: 'uuid', id: 'BB-code', statusEnum: status,
      serverNow: '2026-10-06T22:52:00+07:00', services: [
        { ...item, bookingServiceId: 'first' }, { ...item, status: 'COMPLETED' },
        { ...item, status: 'IN_PROGRESS', actualStartedAt: '2026-10-06T15:10:00Z' },
        { ...item, itemStartAt: '2026-10-06T23:00:00+07:00', itemEndAt: '2026-10-06T23:30:00+07:00' },
      ] });
    assert.equal(booking.id, 'uuid');
    assert.equal(booking.services[0].bookingServiceId, 'first');
    assert.equal(booking.services[0].itemStartAt, item.itemStartAt);
    assert.deepEqual(bookingOperationalLabels(booking, booking.timingAnchor.elapsed), ['Quá giờ dự kiến — cần kiểm tra', 'Đang phục vụ quá giờ dự kiến']);
    assert.deepEqual(bookingOperationalLabels({ ...booking, timingAnchor: null }, 100), []);
  }
  assert.equal(SERVICE_STATUS_LABELS.SCHEDULED, 'Chưa bắt đầu');
  assert.equal(SERVICE_STATUS_LABELS.IN_PROGRESS, 'Đang thực hiện');
});
test('prior-day items stay overdue across midnight; explicit timezone offsets are equivalent', () => {
  const now = Date.parse('2026-10-07T00:01:00+07:00');
  assert.equal(itemOperationalLabel('CHECKED_IN', item, now), 'Quá giờ dự kiến — cần kiểm tra');
  assert.equal(itemOperationalLabel('CONFIRMED', { ...item, itemStartAt: '2026-10-06T15:00:00Z', itemEndAt: '2026-10-06T15:30:00Z' }, now), 'Quá giờ dự kiến — cần kiểm tra');
});
test('counter slot display and manual exception input use branch timezone across midnight/DST', () => {
  assert.equal(formatBranchSlotTime('2026-10-06T17:01:00Z', 'Asia/Ho_Chi_Minh'), '00:01');
  assert.equal(branchWallTimeToInstant('2026-10-07T00:01', 'Asia/Ho_Chi_Minh').toISOString(), '2026-10-06T17:01:00.000Z');
  assert.equal(branchWallTimeToInstant('2026-03-08T02:30', 'America/New_York'), null);
  assert.equal(branchWallTimeToInstant('2026-11-01T01:30', 'America/New_York'), null);
  assert.equal(branchWallTimeToInstant('2026-02-30T10:00', 'Asia/Ho_Chi_Minh'), null);
});

test('calendar now marker uses branch server time; unknown or mixed timezone clocks are hidden', () => {
  const booking = { timingAnchor: serverTimeAnchor('2026-10-06T17:01:00Z', 100), raw: { branch: { timezone: 'Asia/Ho_Chi_Minh' } } };
  const originalTZ = process.env.TZ;
  try {
    for (const timezone of ['UTC', 'America/Los_Angeles', 'Asia/Ho_Chi_Minh']) {
      process.env.TZ = timezone;
      const coordinate = calendarServerNow([booking], 100);
      assert.equal(coordinate.getDate(), 7); assert.equal(coordinate.getHours(), 0); assert.equal(coordinate.getMinutes(), 1);
    }
  } finally {
    if (originalTZ === undefined) delete process.env.TZ; else process.env.TZ = originalTZ;
  }
  assert.equal(calendarServerNow([], 100), null);
  assert.equal(calendarServerNow([{ raw: booking.raw }], 100), null);
  assert.equal(calendarServerNow([booking, { ...booking, raw: { branch: { timezone: 'UTC' } } }], 100), null);
});
