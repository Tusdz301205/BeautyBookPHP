import { branchWallTimeToInstant } from './branchSlotTime.js';

export function emptyActualTimeCorrection() {
  return { timing: 'KNOWN', actualStartedAt: '', actualCompletedAt: '', reason: '', confirmed: false };
}

export function actualTimeCorrectionPayload(form, { expectedRevision, timezone, now }) {
  if (!form.reason.trim()) throw new Error('Nhập lý do bổ sung / hiệu chỉnh.');
  if (form.reason.trim().length > 2000) throw new Error('Lý do bổ sung / hiệu chỉnh tối đa 2000 ký tự.');
  if (!form.confirmed) throw new Error('Xác nhận dịch vụ đã được thực hiện và ảnh hưởng của thao tác.');
  if (!Number.isSafeInteger(expectedRevision) || expectedRevision < 1) throw new Error('Tải lại chi tiết dịch vụ trước khi lưu.');
  const payload = { expectedRevision, actualStartedAt: null, actualCompletedAt: null, reason: form.reason.trim() };
  if (form.timing === 'UNKNOWN') return payload;
  if (form.timing !== 'KNOWN') throw new Error('Chọn cách ghi nhận thời gian hợp lệ.');
  if (!form.actualStartedAt || !form.actualCompletedAt) throw new Error('Nhập đủ giờ bắt đầu và kết thúc thực tế, hoặc chọn chưa xác định.');
  let start, end;
  try {
    if (!timezone) throw new Error('Missing branch timezone');
    start = branchWallTimeToInstant(form.actualStartedAt, timezone);
    end = branchWallTimeToInstant(form.actualCompletedAt, timezone);
  } catch { throw new Error('Chưa có múi giờ chi nhánh hợp lệ. Tải lại chi tiết trước khi lưu.'); }
  if (!start || !end) throw new Error('Giờ thực tế không hợp lệ hoặc không xác định được trong múi giờ chi nhánh.');
  if (start >= end) throw new Error('Giờ bắt đầu thực tế phải trước giờ kết thúc thực tế.');
  if (!Number.isFinite(now)) throw new Error('Chưa có thời gian máy chủ. Tải lại chi tiết trước khi lưu.');
  if (start.getTime() > now || end.getTime() > now) throw new Error('Thời gian thực tế không được ở tương lai.');
  return { ...payload, actualStartedAt: start.toISOString(), actualCompletedAt: end.toISOString() };
}

// A write acknowledgement is not an optimistic lifecycle update. Re-read facts.
export async function submitActualTimeCorrection({ send, refresh, payload }) {
  try {
    await send(payload);
  } catch (error) {
    if (error.status === 409) {
      await refresh();
      throw new Error('Dữ liệu dịch vụ đã thay đổi. Đã yêu cầu tải lại; kiểm tra phiên bản mới và xác nhận lại trước khi lưu.', { cause: error });
    }
    throw error;
  }
  try { await refresh(); }
  catch (error) {
    throw new Error('Máy chủ đã ghi nhận hiệu chỉnh nhưng chưa tải được chi tiết mới. Tải lại chi tiết để kiểm tra trước khi thực hiện thao tác khác.', { cause: error });
  }
}
