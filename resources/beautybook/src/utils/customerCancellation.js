import { combineDateTime } from './bookingCalendar.adapter.js';

export const CUSTOMER_CANCELLATION_HOURS = 4;

export function customerCancellationMode(booking, now = Date.now()) {
  if (!booking?.appointmentDate || !booking?.appointmentStartTime || !['PENDING', 'CONFIRMED'].includes(booking.status)) return 'unavailable';
  const start = combineDateTime(booking.appointmentDate, booking.appointmentStartTime);
  const pending = booking.changeRequests?.some((request) => request.status === 'PENDING' &&
    (!request.expiresAt || new Date(request.expiresAt).getTime() > now));
  if (pending) return 'pending';
  if (!start || !Number.isFinite(start.getTime()) || start.getTime() <= now) return 'unavailable';
  return start.getTime() - now >= CUSTOMER_CANCELLATION_HOURS * 3_600_000 ? 'direct' : 'request';
}

export async function submitCustomerCancellation(api, booking, reason, now = Date.now()) {
  const mode = customerCancellationMode(booking, now);
  if (!reason?.trim()) throw new Error('Vui lòng nhập lý do hủy.');
  if (mode === 'request') {
    await api.createChangeRequest(booking.id, { requestType: 'CANCEL', reason: reason.trim() });
    return 'Đã gửi yêu cầu hủy. Lịch chỉ được hủy khi cơ sở chấp nhận.';
  }
  if (mode === 'direct') {
    await api.updateStatus(booking.id, 'CANCELLED', undefined, reason.trim());
    return 'Đã hủy lịch';
  }
  throw new Error(mode === 'pending' ? 'Lịch đang có yêu cầu chờ cơ sở xử lý.' : 'Lịch không còn cho phép tự hủy. Vui lòng liên hệ cơ sở.');
}
