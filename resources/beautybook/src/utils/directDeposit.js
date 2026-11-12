// Direct business deposits: what the customer pays and where, what each party may do next, and how the booking shows it.
// The server is the authority (it re-checks roles, versions and amounts); this only decides what to show and offer.

export const DEPOSIT_STATUS = {
  AWAITING_PAYMENT: { label: 'Chờ chuyển khoản', tone: 'warning' },
  PAID: { label: 'Đã nhận cọc', tone: 'success' },
  APPLIED: { label: 'Đã tính vào hóa đơn', tone: 'success' },
  FORFEITED: { label: 'Mất cọc', tone: 'danger' },
  EXPIRED: { label: 'Hết hạn', tone: 'neutral' },
  VOIDED: { label: 'Đã hủy (chưa nhận tiền)', tone: 'neutral' },
  REFUND_DUE: { label: 'Cần hoàn lại', tone: 'warning' },
  REFUNDED: { label: 'Đã hoàn lại', tone: 'success' },
};

export const REFUND_REASON = {
  BOOKING_CANCELLED: 'Lịch hẹn đã hủy',
  BOOKING_REJECTED: 'Cơ sở từ chối lịch hẹn',
  BOOKING_EXPIRED: 'Lịch hẹn hết hạn trước khi được xác nhận',
  AMOUNT_MISMATCH: 'Số tiền chuyển khác số tiền cọc',
  DUPLICATE_PAYMENT: 'Chuyển khoản trùng cho cùng một khoản cọc',
  LATE_PAYMENT: 'Chuyển khoản sau hạn giữ chỗ',
  BOOKING_UNAVAILABLE: 'Lịch hẹn không còn khả dụng',
  STORE_PAYMENT_RECORDED: 'Cơ sở đã ghi nhận thu tiền cho lịch hẹn này trước khi nhận cọc',
};

export const REFUND_STATUS = { PENDING: 'Chờ khai báo / chuyển trả', REPORTED: 'Cơ sở đã báo chuyển trả', CONFIRMED: 'Khách đã xác nhận nhận đủ' };

/** Minutes left to pay (never negative); null when there is nothing to pay or no deadline. */
export function minutesToPay(deposit, now = Date.now()) {
  if (!deposit || deposit.status !== 'AWAITING_PAYMENT' || !deposit.expiresAt) return null;
  return Math.max(0, Math.ceil((new Date(deposit.expiresAt).getTime() - now) / 60_000));
}

/** The payment instructions a customer needs, or null once the deposit can no longer be paid. */
export function paymentInstructions(deposit, now = Date.now()) {
  if (!deposit || deposit.fundingSource !== 'DIRECT_SEPAY' || deposit.status !== 'AWAITING_PAYMENT' || !deposit.canPay) return null;
  if (minutesToPay(deposit, now) === 0) return null;
  return {
    bank: deposit.bankCode, accountNumber: deposit.accountNumber, accountName: deposit.accountName,
    amountVnd: Number(deposit.amountVnd), content: deposit.paymentCode, qrUrl: deposit.qrUrl || null,
    testOnly: deposit.environment !== 'live', minutes: minutesToPay(deposit, now),
  };
}

/**
 * What the viewer can do with one refund: the customer declares a destination, the business owner reports the transfer
 * (only once a destination exists), the customer confirms receipt. Everything else is waiting on the other side.
 */
export function refundStep(refund, role) {
  if (!refund) return { action: null, waitingFor: null };
  if (refund.status === 'PENDING' && !refund.destination) return role === 'CUSTOMER' ? { action: 'destination', waitingFor: null } : { action: null, waitingFor: 'CUSTOMER' };
  if (refund.status === 'PENDING') return role === 'OWNER' ? { action: 'report', waitingFor: null } : { action: null, waitingFor: 'OWNER' };
  if (refund.status === 'REPORTED') return role === 'CUSTOMER' ? { action: 'confirm', waitingFor: null } : { action: null, waitingFor: 'CUSTOMER' };
  return { action: null, waitingFor: null };
}

export const maskAccount = (value) => { const text = String(value || ''); return text.length > 4 ? `${'•'.repeat(text.length - 4)}${text.slice(-4)}` : text; };

export const STORE_METHOD = { CASH: 'tiền mặt', BANK_TRANSFER: 'chuyển khoản' };

/**
 * The salon's payment book for one booking, ready to show: the three amounts that matter now (collected, waiting for verification,
 * still collectable), the remainder the deposit terms promised at booking time kept apart from them, the total per method, and one
 * line per payment with its own method, reference, who recorded it and when. A reversed payment stays listed, marked.
 */
export function storePaymentView(payment, money) {
  if (!payment) return null;
  const amount = (value) => money(Number(value));
  const entries = (payment.entries || []).map((entry) => ({
    key: entry.id ?? `legacy-${entry.recordedAt}`,
    method: STORE_METHOD[entry.method] || String(entry.method || '').toLowerCase(),
    amount: amount(entry.amountVnd),
    reference: entry.reference || null,
    by: entry.recordedBy?.name || null,
    at: entry.recordedAt || null,
    reversed: Boolean(entry.reversed),
    refunded: Number(entry.refundedVnd) > 0 ? amount(entry.refundedVnd) : null,
    pending: entry.status === 'PENDING' && !entry.reversed,
    legacy: Boolean(entry.legacy),
  }));
  return {
    collected: amount(payment.storeCollectedVnd),
    pending: Number(payment.pendingVnd) > 0 ? amount(payment.pendingVnd) : null,
    collectable: amount(payment.collectableVnd),
    due: amount(payment.dueVnd),
    initialRemaining: payment.initialRemainingVnd == null ? null : amount(payment.initialRemainingVnd),
    breakdown: (payment.breakdown || []).map((row) => `${STORE_METHOD[row.method] || row.method} ${amount(row.amountVnd)}`),
    entries,
    hasPayments: entries.length > 0,
  };
}
