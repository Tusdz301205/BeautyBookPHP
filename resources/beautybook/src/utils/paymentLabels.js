export const PAYMENT_LABELS = {
  CASH: 'Tiền mặt', BANK_TRANSFER: 'Chuyển khoản', SEPAY_LIVE: 'Chuyển khoản qua SePay', SEPAY: 'SePay',
  CARD: 'Thẻ ngân hàng', WALLET: 'Ví khách hàng', SIMULATED_WALLET: 'Cọc từ ví', DIRECT_SEPAY: 'Cọc chuyển khoản trực tiếp',
  NONE: 'Không yêu cầu cọc', FIXED: 'Số tiền cố định', PERCENTAGE: 'Theo phần trăm', FULL_PREPAYMENT: 'Thanh toán trước toàn bộ',
  PAYMENT_RECEIVED: 'Đã thu tiền', REVERSAL: 'Điều chỉnh giao dịch', REFUND: 'Hoàn tiền',
  PLATFORM_FEE: 'Phí nền tảng', PLATFORM_FEE_ADJUSTMENT: 'Điều chỉnh phí nền tảng',
  PACKAGE_PURCHASE: 'Mua gói liệu trình', PACKAGE_INSTALLMENT: 'Thanh toán gói theo đợt',
  ISSUED: 'Đã phát hành', VERIFIED: 'Đã xác minh', SUCCESS: 'Thành công',
  AWAITING_PAYMENT: 'Chờ thanh toán cọc', HELD: 'Đang giữ cọc', APPLIED: 'Đã tính vào tiền dịch vụ', FORFEITED: 'Mất cọc theo chính sách',
  PENDING_APPROVAL: 'Chờ duyệt', SIMULATED_COMPLETED: 'Đã chi trả (mô phỏng)', SIMULATED_PAID: 'Đã chi trả (mô phỏng)',
  VERIFIED_DEMO: 'Đã xác minh (mô phỏng)', SETUP: 'Chờ thiết lập', READY: 'Sẵn sàng', DISABLED: 'Đã tắt',
  UNMATCHED: 'Chưa khớp giao dịch', MANUAL_REVIEW: 'Cần kiểm tra', REFUND_REQUIRED: 'Cần hoàn tiền',
  TOPUP: 'Nạp tiền vào ví', HOLD: 'Giữ cọc', RELEASE: 'Cọc được ghi nhận cho cơ sở', SETTLEMENT: 'Chi trả cho cơ sở',
};
export const paymentLabel = (value, fallback = 'Khác') => PAYMENT_LABELS[value] || fallback;
