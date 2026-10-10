export const SERVICE_STATUS_LABELS = {
  SCHEDULED: 'Chưa bắt đầu', IN_PROGRESS: 'Đang thực hiện',
  COMPLETED: 'Hoàn thành', SKIPPED: 'Đã bỏ qua', CANCELLED: 'Đã hủy', NO_SHOW: 'Không đến',
  REJECTED: 'Đã từ chối', EXPIRED: 'Đã hết hạn',
};
const terminal = new Set(['COMPLETED', 'CANCELLED', 'NO_SHOW', 'SKIPPED', 'REJECTED', 'EXPIRED']);
const instant = (value) => typeof value === 'string' && /(?:Z|[+-]\d{2}:\d{2})$/.test(value)
  ? Date.parse(value) : NaN;

// Never substitute a device wall clock or a planned DATE/TIME projection.
export function serverTimeAnchor(serverNow, elapsed = performance.now()) {
  const server = instant(serverNow);
  return Number.isFinite(server) ? { server, elapsed } : null;
}

export function anchoredServerNow(anchor, elapsed = performance.now()) {
  return anchor ? anchor.server + Math.max(0, elapsed - anchor.elapsed) : null;
}

export function itemOperationalLabel(parentStatus, item, now) {
  if (!Number.isFinite(now) || terminal.has(parentStatus) || terminal.has(item?.status)) return null;
  const start = instant(item?.itemStartAt);
  const end = instant(item?.itemEndAt);
  if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) return null;
  if (item?.status === 'IN_PROGRESS') {
    return now > end ? 'Đang phục vụ quá giờ dự kiến' : null;
  }
  if (item?.status !== 'SCHEDULED' || item.actualStartedAt) return null;
  if (now > end) return 'Quá giờ dự kiến — cần kiểm tra';
  return now >= start ? 'Đã đến giờ — chưa bắt đầu' : null;
}

export function bookingOperationalLabels(booking, elapsed = performance.now()) {
  const now = anchoredServerNow(booking?.timingAnchor, elapsed);
  return [...new Set((booking?.services || []).map((item) =>
    itemOperationalLabel(booking.statusEnum || booking.status, item, now)).filter(Boolean))];
}

// The calendar positions branch wall fields on a local Date coordinate grid.
// This Date is only a display coordinate, never an execution timestamp.
export function calendarServerNow(bookings, elapsed) {
  const zones = new Set(bookings.map((booking) => booking.raw?.branch?.timezone || 'Asia/Ho_Chi_Minh'));
  if (zones.size !== 1) return null;
  const now = anchoredServerNow(bookings.find((booking) => booking.timingAnchor)?.timingAnchor, elapsed);
  if (!Number.isFinite(now)) return null;
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: [...zones][0], year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  });
  const parts = Object.fromEntries(formatter.formatToParts(now).map((part) => [part.type, part.value]));
  return new Date(`${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`);
}
