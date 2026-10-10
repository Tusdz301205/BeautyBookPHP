export const STATUS_LABELS = {
  ACTIVE: 'Đang hoạt động',
  INACTIVE: 'Tạm ngưng',
  DRAFT: 'Bản nháp',
  PENDING: 'Chờ xử lý',
  PENDING_REVIEW: 'Chờ xét duyệt',
  SUBMITTED: 'Đã gửi xét duyệt',
  NEED_MORE_INFO: 'Cần bổ sung thông tin',
  APPROVED: 'Đã duyệt',
  REJECTED: 'Bị từ chối',
  SUSPENDED: 'Đang bị đình chỉ',
  READY_TO_PUBLISH: 'Sẵn sàng mở đặt lịch',
  PAUSED: 'Tạm dừng',
  CLOSED: 'Đã đóng cửa',
  ARCHIVED: 'Đã lưu trữ',
  DEPRECATED: 'Ngừng dùng cho lựa chọn mới',
  MERGED: 'Đã gộp',
  MAPPED: 'Đã phân loại',
  UNMAPPED: 'Chưa phân loại',
  SUGGESTED: 'Đang đề xuất phân loại',
  CONFIRMED: 'Đã xác nhận',
  CHECKED_IN: 'Đã đến',
  IN_PROGRESS: 'Đang thực hiện',
  COMPLETED: 'Hoàn thành',
  CANCELLED: 'Đã hủy',
  NO_SHOW: 'Không đến',
  EXPIRED: 'Đã hết hạn',
  PROFILE_ONLY: 'Chỉ có hồ sơ',
  INVITED: 'Đã gửi lời mời',
  LOCKED: 'Đã khóa',
  REVIEW: 'Chờ phê duyệt',
  EXPORTED: 'Đã hoàn tất',
  PAID: 'Đã thanh toán',
  PARTIALLY_PAID: 'Đã thanh toán một phần',
  UNPAID: 'Chưa thanh toán',
  PROCESSING: 'Đang xử lý',
  FAILED: 'Không thành công',
  REFUNDED: 'Đã hoàn tiền',
  PARTIALLY_REFUNDED: 'Đã hoàn một phần',
  VOID: 'Đã hủy hiệu lực',
  OVERDUE: 'Quá hạn',
  SCHEDULED: 'Đã lên lịch',
  PRESENT: 'Có mặt',
  LATE: 'Đi muộn',
  ABSENT: 'Vắng mặt',
  ACCEPTED: 'Đã chấp nhận',
  REVOKED: 'Đã thu hồi',
  VISIBLE: 'Đang hiển thị',
  HIDDEN: 'Đã ẩn',
  REPORTED: 'Đã báo cáo',
};

export const STATUS_TONES = {
  ACTIVE: 'success', APPROVED: 'success', COMPLETED: 'success', CONFIRMED: 'info',
  READY_TO_PUBLISH: 'info', CHECKED_IN: 'info', INVITED: 'info',
  PENDING: 'warning', PENDING_REVIEW: 'warning', SUBMITTED: 'warning',
  NEED_MORE_INFO: 'warning', SUGGESTED: 'warning', REVIEW: 'warning',
  REJECTED: 'danger', SUSPENDED: 'danger', CANCELLED: 'danger', LOCKED: 'danger',
  INACTIVE: 'neutral', DRAFT: 'neutral', PAUSED: 'neutral', CLOSED: 'neutral',
  ARCHIVED: 'neutral', DEPRECATED: 'neutral', MERGED: 'neutral', UNMAPPED: 'neutral',
  NO_SHOW: 'neutral', EXPIRED: 'neutral', PROFILE_ONLY: 'neutral', EXPORTED: 'neutral',
  PAID: 'success', PRESENT: 'success', ACCEPTED: 'success', VISIBLE: 'success',
  PARTIALLY_PAID: 'warning', UNPAID: 'warning', PROCESSING: 'warning', OVERDUE: 'danger',
  FAILED: 'danger', REFUNDED: 'info', PARTIALLY_REFUNDED: 'info', VOID: 'neutral',
  SCHEDULED: 'info', LATE: 'warning', ABSENT: 'danger', REVOKED: 'danger',
  HIDDEN: 'danger', REPORTED: 'warning',
};

export const DOCUMENT_LABELS = {
  BUSINESS_LICENSE: 'Giấy phép kinh doanh',
  OWNER_ID_CARD: 'CCCD người đại diện',
  TAX_DOCUMENT: 'Tài liệu thuế',
  BUSINESS_REGISTRATION: 'Giấy chứng nhận đăng ký doanh nghiệp',
  TAX_REGISTRATION: 'Hồ sơ đăng ký thuế',
  IDENTITY_DOCUMENT: 'Giấy tờ người đại diện',
  OPERATING_LICENSE: 'Giấy phép hoạt động',
  LOCATION_DOCUMENT: 'Giấy tờ địa điểm',
  SERVICE_LICENSE: 'Giấy phép chuyên môn',
  FIRE_SAFETY: 'Hồ sơ phòng cháy chữa cháy',
  OTHER: 'Tài liệu khác',
};

export function statusLabel(value, fallback = 'Chưa xác định') {
  return STATUS_LABELS[value] || fallback;
}

export function statusTone(value) {
  return STATUS_TONES[value] || 'neutral';
}

export function documentLabel(value) {
  return DOCUMENT_LABELS[value] || 'Tài liệu đăng ký';
}
