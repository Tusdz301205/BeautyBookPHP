import { API_ERROR_TRANSLATIONS } from './apiErrorTranslations.js';

const FALLBACK_MESSAGE = 'Không thể hoàn tất yêu cầu. Vui lòng thử lại.';

const PAYMENT_ERRORS = {
  'Invalid payment field': 'Thông tin thanh toán không hợp lệ. Vui lòng kiểm tra lại.',
  'Invalid payment fields': 'Thông tin thanh toán không hợp lệ. Vui lòng kiểm tra lại.',
  'Invalid payment information': 'Thông tin thanh toán không hợp lệ.',
  'Invalid top-up fields': 'Thông tin nạp tiền không hợp lệ.',
  'Top-up not found': 'Không tìm thấy yêu cầu nạp tiền.',
  'Top-up limit exceeded': 'Số tiền vượt hạn mức nạp ví.',
  'Private Live test top-up limit exceeded': 'Số tiền nằm ngoài hạn mức nạp ví cho phép.',
  'Only SePay Live top-ups are supported': 'Vui lòng nạp tiền qua chuyển khoản SePay.',
  'Top-up key already used with different terms': 'Yêu cầu nạp tiền đã thay đổi. Vui lòng tải lại và tạo yêu cầu mới.',
  'Invalid bank account': 'Thông tin tài khoản ngân hàng không hợp lệ.',
  'Account not found': 'Không tìm thấy tài khoản ngân hàng.',
  'Bank account changed': 'Tài khoản ngân hàng đã thay đổi. Vui lòng tải lại.',
  'Account changed': 'Tài khoản đã thay đổi. Vui lòng tải lại.',
  'Current account version required': 'Vui lòng tải lại thông tin tài khoản trước khi tiếp tục.',
  'Bank account limit reached': 'Đã đạt giới hạn tài khoản ngân hàng có thể đăng ký.',
  'Insufficient wallet balance': 'Số dư ví không đủ để giữ cọc. Vui lòng nạp thêm tiền.',
  'Held wallet balance is inconsistent': 'Số tiền đang giữ đã thay đổi. Vui lòng tải lại.',
  'Review the current wallet deposit before booking': 'Thông tin tiền cọc đã thay đổi. Vui lòng kiểm tra lại trước khi đặt lịch.',
  'Financial operation identity conflict': 'Yêu cầu thanh toán đã thay đổi. Vui lòng tải lại trước khi thử lại.',
  'Wallet deposit is not held': 'Tiền cọc chưa được giữ trong ví. Vui lòng kiểm tra lại lịch hẹn.',
  'Resolve the recorded store payment before cancelling': 'Cần xử lý khoản đã thu tại cơ sở trước khi hủy lịch.',
  'A verified default demo bank account is required': 'Cần có tài khoản nhận tiền mặc định đã được xác minh.',
  'No eligible unsettled deposits in this period': 'Không có khoản cọc đủ điều kiện chi trả trong khoảng thời gian này.',
  'Settlement key identity conflict': 'Yêu cầu chi trả đã thay đổi. Vui lòng tải lại.',
  'Settlement not found': 'Không tìm thấy đợt chi trả.',
  'Invalid settlement period': 'Khoảng thời gian chi trả không hợp lệ.',
  'Invalid settlement fields': 'Thông tin chi trả không hợp lệ.',
  'Customer account required': 'Vui lòng dùng tài khoản khách hàng để thực hiện thao tác này.',
  'Invalid wallet scope': 'Nguồn số dư ví không hợp lệ.',
  'Review and accept the current deposit before booking': 'Vui lòng kiểm tra và xác nhận tiền cọc hiện tại trước khi đặt lịch.',
  'Unauthorized': 'Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.',
  'Forbidden': 'Bạn không có quyền thực hiện thao tác này.',
  'Not Found': 'Không tìm thấy thông tin được yêu cầu.',
  'Too Many Attempts.': 'Bạn thao tác quá nhanh. Vui lòng đợi một lát rồi thử lại.',
};

export function sanitizeApiErrorMessage(status, message) {
  const value = typeof message === 'string' ? message.trim() : '';
  if (status >= 500 || /^internal server error$/i.test(value)) {
    return 'Máy chủ đang gặp sự cố tạm thời. Vui lòng thử lại sau.';
  }
  return PAYMENT_ERRORS[value] || API_ERROR_TRANSLATIONS[value] || value || FALLBACK_MESSAGE;
}

export function toUserFacingRequestError(error, fallbackTitle = 'Không thể tải dữ liệu') {
  const status = Number(error?.status) || 0;
  const rawMessage = typeof error?.message === 'string' ? error.message.trim() : '';

  if (!status) {
    return {
      title: 'Không thể kết nối máy chủ',
      message: 'Kiểm tra kết nối mạng và trạng thái máy chủ rồi thử lại.',
      retryable: true,
      kind: 'network',
    };
  }
  if (status === 401) {
    return {
      title: 'Phiên đăng nhập đã hết hạn',
      message: 'Vui lòng đăng nhập lại để tiếp tục.',
      retryable: false,
      kind: 'authentication',
    };
  }
  if (status === 403) {
    return {
      title: 'Bạn không có quyền xem dữ liệu này',
      message: 'Hãy chọn đúng chi nhánh hoặc liên hệ quản trị viên để được cấp quyền.',
      retryable: false,
      kind: 'authorization',
    };
  }
  if (status === 404) {
    return {
      title: 'Không tìm thấy dữ liệu',
      message: 'Dữ liệu có thể đã bị xóa hoặc không còn thuộc phạm vi hiện tại.',
      retryable: false,
      kind: 'not-found',
    };
  }
  if (status === 429) {
    return {
      title: 'Bạn đang thao tác quá nhanh',
      message: 'Vui lòng đợi một lát rồi thử lại.',
      retryable: true,
      kind: 'rate-limit',
    };
  }
  if (status >= 500) {
    return {
      title: fallbackTitle,
      message: 'Máy chủ đang gặp sự cố tạm thời. Vui lòng thử lại sau.',
      retryable: true,
      kind: 'server',
    };
  }

  return {
    title: fallbackTitle,
    message: sanitizeApiErrorMessage(status, rawMessage),
    retryable: true,
    kind: 'request',
  };
}
