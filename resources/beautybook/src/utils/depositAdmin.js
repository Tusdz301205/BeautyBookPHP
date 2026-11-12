// Labels and paging for the platform admin's direct-deposit lists. The server pages and filters; this only names and steps.

export const RECEIPT_PROCESSING = {
  UNMATCHED: { label: 'Không khớp lịch nào', tone: 'warning' },
  REFUND_REQUIRED: { label: 'Cần hoàn lại khách', tone: 'danger' },
  APPLIED: { label: 'Đã tính vào cọc', tone: 'success' },
  VERIFIED: { label: 'Giao dịch xác minh kết nối', tone: 'neutral' },
};

export const REFUND_STATUS_LABEL = { PENDING: 'Chờ khai báo / chuyển trả', REPORTED: 'Cơ sở đã báo chuyển trả', CONFIRMED: 'Khách đã xác nhận' };

/** Page numbers for a list answered as { page, pageSize, total }: where we are, whether there is a previous or next page, and the range shown. */
export function pageInfo(result) {
  const page = Math.max(1, Number(result?.page) || 1);
  const size = Math.max(1, Number(result?.pageSize) || 20);
  const total = Math.max(0, Number(result?.total) || 0);
  const from = total === 0 ? 0 : (page - 1) * size + 1;
  const to = Math.min(total, page * size);
  return { page, pageCount: Math.max(1, Math.ceil(total / size)), total, from, to, hasPrev: page > 1, hasNext: Boolean(result?.hasMore) || to < total };
}
