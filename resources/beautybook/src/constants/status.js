/**
 * Booking status — nguồn dữ liệu duy nhất cho UI.
 *
 * - ENUM giá trị backend lưu trong DB (PascalCase).
 * - VI là nhãn hiển thị tiếng Việt trên UI.
 * - COLOR là class Tailwind cho badge/bar.
 *
 * Trước đây mapping bị phân tán ở 4 file (SchedulerListView, BookingBar,
 * SchedulerMonthView, SchedulerStats) — dễ sinh bug inconsistency.
 */

export const BOOKING_STATUSES = {
  PENDING: {
    enum: 'PENDING',
    label: 'Mới',
    color: 'bg-yellow-100 text-yellow-700 border-yellow-200',
    icon: 'Clock',
    event: 'bg-amber-50 text-amber-950 border-amber-300',
    dot: 'bg-amber-500',
  },
  CONFIRMED: {
    enum: 'CONFIRMED',
    label: 'Đã xác nhận',
    color: 'bg-blue-100 text-blue-700 border-blue-200',
    icon: 'CheckCircle',
    event: 'bg-blue-50 text-blue-950 border-blue-300',
    dot: 'bg-blue-500',
  },
  CHECKED_IN: {
    enum: 'CHECKED_IN',
    label: 'Đã đến',
    color: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    icon: 'UserCheck',
    event: 'bg-cyan-50 text-cyan-950 border-cyan-300',
    dot: 'bg-cyan-500',
  },
  IN_PROGRESS: {
    enum: 'IN_PROGRESS',
    label: 'Đang thực hiện',
    color: 'bg-purple-100 text-purple-700 border-purple-200',
    icon: 'Scissors',
    event: 'bg-violet-50 text-violet-950 border-violet-300',
    dot: 'bg-violet-500',
  },
  COMPLETED: {
    enum: 'COMPLETED',
    label: 'Hoàn thành',
    color: 'bg-green-100 text-green-700 border-green-200',
    icon: 'CheckCircle',
    event: 'bg-emerald-50 text-emerald-950 border-emerald-300',
    dot: 'bg-emerald-500',
  },
  CANCELLED: {
    enum: 'CANCELLED',
    label: 'Đã huỷ',
    color: 'bg-red-100 text-red-700 border-red-200',
    icon: 'XCircle',
    event: 'bg-red-50 text-red-950 border-red-300',
    dot: 'bg-red-500',
  },
  NO_SHOW: {
    enum: 'NO_SHOW',
    label: 'Không đến',
    color: 'bg-gray-100 text-gray-700 border-gray-200',
    icon: 'User',
    event: 'bg-zinc-100 text-zinc-800 border-zinc-300',
    dot: 'bg-zinc-500',
  },
  REJECTED: {
    enum: 'REJECTED',
    label: 'Đã từ chối',
    color: 'bg-red-50 text-red-800 border-red-200',
  },
  EXPIRED: {
    enum: 'EXPIRED',
    label: 'Đã hết hạn',
    color: 'bg-zinc-100 text-zinc-700 border-zinc-200',
  },
};

export const BOOKING_STATUS_LIST = Object.values(BOOKING_STATUSES);

/** Map enum <-> label */
export const enumToLabel = (enumVal) =>
  BOOKING_STATUSES[enumVal]?.label ?? enumVal;

export const labelToEnum = (label) =>
  BOOKING_STATUS_LIST.find((status) => status.label === label)?.enum ?? label;

/** Helper cho query string */
export const labelToQuery = (label) => enumToLabel(label);

/** Kiểm tra trạng thái kết thúc */
export const isTerminalStatus = (enumVal) =>
  ['COMPLETED', 'CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'].includes(enumVal);
