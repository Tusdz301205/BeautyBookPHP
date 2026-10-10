import test from 'node:test';
import assert from 'node:assert/strict';
import { operationalListRows } from './operationalListRows.js';
import { bookingOperationalLabels } from './operationalTiming.js';

const detail = (id) => ({ id, status: 'CONFIRMED', serverNow: '2026-10-06T15:52:00Z', bookingServices: [
  { id: 'item', staff: { fullName: 'Staff' }, status: 'SCHEDULED', itemStartAt: '2026-10-06T15:00:00Z', itemEndAt: '2026-10-06T15:30:00Z' },
] });
test('legacy page gets operational facts via bounded detail reads; terminal and complete projections skip reads', async () => {
  let active = 0, peak = 0; const calls = [];
  const rows = Array.from({ length: 20 }, (_, index) => ({ bookingId: String(index), statusEnum: 'CONFIRMED', services: [{ name: 'List service', staff: 'Staff' }] }));
  const result = await operationalListRows(rows, async (id) => {
    calls.push(id); active++; peak = Math.max(peak, active);
    await new Promise((resolve) => setImmediate(resolve)); active--;
    return detail(id);
  });
  assert.equal(calls.length, 20); assert.equal(peak, 4);
  assert.deepEqual(bookingOperationalLabels(result[0], result[0].timingAnchor.elapsed), ['Quá giờ dự kiến — cần kiểm tra']);
  assert.equal(result[0].services[0].staff, 'Staff');
  calls.length = 0;
  await operationalListRows([{ statusEnum: 'COMPLETED' }, { statusEnum: 'CONFIRMED', services: detail('b').bookingServices }], async (id) => calls.push(id));
  assert.equal(calls.length, 0);
});
test('scope invalidation stops enrichment; failed detail never manufactures schedule or status', async () => {
  let current = true, calls = 0;
  const rows = Array.from({ length: 20 }, (_, index) => ({ bookingId: String(index), services: [] }));
  const result = await operationalListRows(rows, async () => { calls++; current = false; return detail('old'); }, () => current);
  assert.equal(calls, 1);
  assert.deepEqual(result[0].services, []);
  const failed = await operationalListRows([{ bookingId: 'b' }], async () => { throw new Error('Forbidden'); });
  assert.deepEqual(bookingOperationalLabels(failed[0]), []);
});
