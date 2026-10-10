import { normalizeBooking } from './bookingCalendar.adapter.js';
import { serverTimeAnchor } from './operationalTiming.js';

// The legacy list projection omits item schedule/status. Enrich only this page,
// using the authorized detail read; never scan history or infer missing fields.
export async function operationalListRows(rows, readDetail, isCurrent = () => true) {
  const result = rows.map((row) => ({ ...row, timingAnchor: serverTimeAnchor(row.serverNow) }));
  let cursor = 0;
  async function worker() {
    while (cursor < result.length && isCurrent()) {
      const index = cursor++;
      const row = result[index];
      if (['COMPLETED', 'CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'].includes(row.statusEnum)
        || (row.services?.length > 0 && row.services.every((item) => item.status && item.itemStartAt && item.itemEndAt))) continue;
      try {
        const payload = await readDetail(row.bookingId || row.id);
        if (!isCurrent()) return;
        const detail = normalizeBooking(payload);
        result[index] = { ...row, services: detail.services.map((item, itemIndex) => ({ ...row.services?.[itemIndex], ...item, staff: item.staffName, duration: item.durationMinutes })), statusEnum: detail.status,
          timingAnchor: detail.timingAnchor };
      } catch { /* A missing authorized read supplies no evidence of lateness. */ }
    }
  }
  await Promise.all(Array.from({ length: Math.min(4, result.length) }, worker));
  return result;
}
