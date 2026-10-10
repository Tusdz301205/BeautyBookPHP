import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { actualTimeCorrectionPayload, emptyActualTimeCorrection, submitActualTimeCorrection } from './actualTimeCorrection.js';
import { normalizeBooking } from './bookingCalendar.adapter.js';

const options = { expectedRevision: 3, timezone: 'Asia/Ho_Chi_Minh', now: Date.parse('2026-10-06T16:00:00Z') };
const known = () => ({ ...emptyActualTimeCorrection(), actualStartedAt: '2026-10-06T22:00', actualCompletedAt: '2026-10-06T22:30', reason: '  Đã đối chiếu với ghi nhận phục vụ  ', confirmed: true });
test('correction starts with empty time/reason/confirmation; incomplete known timing never uses planned times', () => {
  assert.deepEqual(emptyActualTimeCorrection(), { timing: 'KNOWN', actualStartedAt: '', actualCompletedAt: '', reason: '', confirmed: false });
  assert.throws(() => actualTimeCorrectionPayload({ ...emptyActualTimeCorrection(), reason: 'Verified', confirmed: true }, options), /Nhập đủ/);
  assert.throws(() => actualTimeCorrectionPayload({ ...known(), actualCompletedAt: '' }, options), /Nhập đủ/);
});
test('known instants convert in branch timezone with strict order, no future and a server clock', () => {
  assert.deepEqual(actualTimeCorrectionPayload(known(), options), { expectedRevision: 3, actualStartedAt: '2026-10-06T15:00:00.000Z', actualCompletedAt: '2026-10-06T15:30:00.000Z', reason: 'Đã đối chiếu với ghi nhận phục vụ' });
  for (const actualCompletedAt of ['2026-10-06T22:00', '2026-10-06T21:59']) assert.throws(() => actualTimeCorrectionPayload({ ...known(), actualCompletedAt }, options), /phải trước/);
  assert.throws(() => actualTimeCorrectionPayload({ ...known(), actualCompletedAt: '2026-10-06T23:01' }, options), /tương lai/);
  assert.throws(() => actualTimeCorrectionPayload(known(), { ...options, now: null }), /máy chủ/);
  assert.throws(() => actualTimeCorrectionPayload(known(), { ...options, timezone: null }), /múi giờ/);
  assert.throws(() => actualTimeCorrectionPayload(known(), { ...options, timezone: 'not-a-timezone' }), /múi giờ/);
});
test('unknown deliberately sends both null, never a status override or stale entered/planned times', () => {
  const payload = actualTimeCorrectionPayload({ ...known(), timing: 'UNKNOWN' }, { ...options, now: null });
  assert.deepEqual(payload, { expectedRevision: 3, actualStartedAt: null, actualCompletedAt: null, reason: 'Đã đối chiếu với ghi nhận phục vụ' });
  assert.equal('status' in payload, false);
  assert.throws(() => actualTimeCorrectionPayload({ ...known(), confirmed: false }, options), /Xác nhận/);
  assert.throws(() => actualTimeCorrectionPayload({ ...known(), reason: ' ' }, options), /lý do/);
  assert.throws(() => actualTimeCorrectionPayload(known(), { ...options, expectedRevision: null }), /Tải lại/);
});
test('correction capability and KNOWN/UNKNOWN survive normalization without role or actual time fabrication', () => {
  for (const capability of [true, false, undefined, 'true']) {
    const normalized = normalizeBooking({ bookingServices: [{ canCorrectActualTime: capability, status: 'COMPLETED', actualTimingStatus: 'UNKNOWN', actualTimingSource: 'ACTUAL_TIME_CORRECTION' }] }).services[0];
    assert.equal(normalized.canCorrectActualTime, capability === true);
    assert.equal(normalized.actualTimingStatus, 'UNKNOWN');
    assert.equal(normalized.actualTimingSource, 'ACTUAL_TIME_CORRECTION');
    assert.equal(normalized.actualStartedAt, null);
    assert.equal(normalized.actualCompletedAt, null);
  }
});
test('PATCH helper sends only the audited correction body; history GET uses the same protected item endpoint', async () => {
  const source = readFileSync(new URL('../api/apiClient.js', import.meta.url), 'utf8');
  const method = source.split('\n').find((line) => line.trim().startsWith('correctActualTime:'));
  let call;
  const context = { request: (path, options) => { call = { path, options }; }, JSON };
  vm.runInNewContext(`this.api = { ${method} };`, context);
  const payload = actualTimeCorrectionPayload(known(), options);
  await context.api.correctActualTime('booking', 'item', payload);
  assert.equal(call.path, '/bookings/booking/items/item/actual-time');
  assert.equal(call.options.method, 'PATCH');
  assert.deepEqual(JSON.parse(call.options.body), payload);
  const historyMethod = source.split('\n').find((line) => line.trim().startsWith('actualTimeCorrectionHistory:'));
  vm.runInNewContext(`this.historyApi = { ${historyMethod} };`, context);
  await context.historyApi.actualTimeCorrectionHistory('booking', 'item');
  assert.equal(call.path, '/bookings/booking/items/item/actual-time');
  assert.equal(call.options, undefined);
});
test('conflict refreshes authoritative detail without replaying or optimistically marking completed', async () => {
  const facts = { status: 'SCHEDULED', actualStartedAt: null, revision: 3 }; let sends = 0, reads = 0;
  await assert.rejects(submitActualTimeCorrection({
    payload: actualTimeCorrectionPayload(known(), options),
    send: async () => { sends++; throw Object.assign(new Error('Conflict'), { status: 409 }); },
    refresh: async () => { reads++; },
  }), /xác nhận lại/);
  assert.equal(sends, 1); assert.equal(reads, 1);
  assert.deepEqual(facts, { status: 'SCHEDULED', actualStartedAt: null, revision: 3 });
  const events = [];
  await submitActualTimeCorrection({ payload: {}, send: async () => { events.push('write acknowledged'); }, refresh: async () => { events.push('read facts'); } });
  assert.deepEqual(events, ['write acknowledged', 'read facts']);
  await assert.rejects(submitActualTimeCorrection({ payload: {}, send: async () => {}, refresh: async () => { throw new Error('Offline'); } }), /Máy chủ đã ghi nhận/);
});
