const FALLBACK_MESSAGE = 'Không thể hoàn tất yêu cầu. Vui lòng thử lại.';

export function sanitizeApiErrorMessage(status, message) {
  const value = typeof message === 'string' ? message.trim() : '';
  if (status >= 500 || /^internal server error$/i.test(value)) {
    return 'Máy chủ đang gặp sự cố tạm thời. Vui lòng thử lại sau.';
  }
  return value || FALLBACK_MESSAGE;
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
    message: rawMessage || FALLBACK_MESSAGE,
    retryable: true,
    kind: 'request',
  };
}
