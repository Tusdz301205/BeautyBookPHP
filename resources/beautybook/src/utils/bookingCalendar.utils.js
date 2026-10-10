import {
  addDays,
  endOfDay,
  endOfMonth,
  endOfWeek,
  format,
  startOfDay,
  startOfMonth,
  startOfWeek,
} from 'date-fns';
import { vi } from 'date-fns/locale';
import { BOOKING_STATUSES } from '../constants/status';

export const CALENDAR_START_HOUR = 8;
export const CALENDAR_END_HOUR = 23;
export const HOUR_HEIGHT = 72;
export const MIN_EVENT_HEIGHT = 28;
export const CALENDAR_TOP_PADDING = 36;

export function getCalendarRange(viewMode, selectedDate) {
  if (viewMode === 'day') {
    return { start: startOfDay(selectedDate), end: endOfDay(selectedDate) };
  }
  if (viewMode === 'week') {
    return {
      start: startOfWeek(selectedDate, { weekStartsOn: 1 }),
      end: endOfWeek(selectedDate, { weekStartsOn: 1 }),
    };
  }
  const monthStart = startOfMonth(selectedDate);
  const monthEnd = endOfMonth(selectedDate);
  return {
    start: startOfWeek(monthStart, { weekStartsOn: 1 }),
    end: endOfWeek(monthEnd, { weekStartsOn: 1 }),
  };
}

export function formatCalendarPeriod(viewMode, selectedDate) {
  if (viewMode === 'day') return format(selectedDate, 'dd/MM/yyyy', { locale: vi });
  if (viewMode === 'week') {
    const { start, end } = getCalendarRange('week', selectedDate);
    return `${format(start, 'dd/MM')} – ${format(end, 'dd/MM/yyyy')}`;
  }
  return `Tháng ${format(selectedDate, 'MM/yyyy')}`;
}

export function minutesSinceCalendarStart(date) {
  return (date.getHours() - CALENDAR_START_HOUR) * 60 + date.getMinutes();
}

export function eventTop(date) {
  return CALENDAR_TOP_PADDING + Math.max(0, (minutesSinceCalendarStart(date) / 60) * HOUR_HEIGHT);
}

export function eventHeight(startAt, endAt) {
  const duration = Math.max(15, (endAt.getTime() - startAt.getTime()) / 60000);
  return Math.max(MIN_EVENT_HEIGHT, (duration / 60) * HOUR_HEIGHT);
}

export function calendarBodyHeight() {
  return CALENDAR_TOP_PADDING + (CALENDAR_END_HOUR - CALENDAR_START_HOUR) * HOUR_HEIGHT;
}

export function makeHours() {
  return Array.from(
    { length: CALENDAR_END_HOUR - CALENDAR_START_HOUR + 1 },
    (_, index) => CALENDAR_START_HOUR + index,
  );
}

export function computeBookingStats(bookings) {
  const result = {
    total: bookings.length,
    pending: 0,
    confirmed: 0,
    checkedIn: 0,
    inProgress: 0,
    completed: 0,
    cancelled: 0,
    noShow: 0,
  };
  const target = {
    PENDING: 'pending',
    CONFIRMED: 'confirmed',
    CHECKED_IN: 'checkedIn',
    IN_PROGRESS: 'inProgress',
    COMPLETED: 'completed',
    CANCELLED: 'cancelled',
    NO_SHOW: 'noShow',
  };
  bookings.forEach((booking) => {
    const key = target[booking.status];
    if (key) result[key] += 1;
  });
  return result;
}

export function filterBookings(bookings, filters) {
  return bookings.filter((booking) => {
    if (filters.query) {
      const query = filters.query.trim().toLocaleLowerCase('vi');
      const searchable = [
        booking.bookingCode,
        booking.customerName,
        booking.customerPhone,
        booking.primaryStaffName,
        ...booking.serviceNames,
      ].filter(Boolean).join(' ').toLocaleLowerCase('vi');
      if (!searchable.includes(query)) return false;
    }
    if (filters.staffId && booking.primaryStaffId !== filters.staffId) return false;
    if (filters.serviceId && !booking.serviceIds.includes(filters.serviceId)) return false;
    if (filters.status && booking.status !== filters.status) return false;
    return true;
  });
}

export function groupBookingsByDay(bookings) {
  return bookings.reduce((groups, booking) => {
    const key = format(booking.startAt, 'yyyy-MM-dd');
    if (!groups[key]) groups[key] = [];
    groups[key].push(booking);
    groups[key].sort((a, b) => a.startAt - b.startAt);
    return groups;
  }, {});
}

export function layoutOverlaps(bookings) {
  const sorted = [...bookings].sort((a, b) => a.startAt - b.startAt || a.endAt - b.endAt);
  const active = [];
  const result = [];
  sorted.forEach((booking) => {
    for (let index = active.length - 1; index >= 0; index -= 1) {
      if (active[index].endAt <= booking.startAt) active.splice(index, 1);
    }
    const occupied = new Set(active.map((item) => item.lane));
    let lane = 0;
    while (occupied.has(lane)) lane += 1;
    const item = { ...booking, lane };
    active.push(item);
    const laneCount = Math.max(...active.map((entry) => entry.lane)) + 1;
    active.forEach((entry) => {
      const existing = result.find((candidate) => candidate.id === entry.id);
      if (existing) existing.laneCount = Math.max(existing.laneCount, laneCount);
    });
    result.push({ ...item, laneCount });
  });
  return result;
}

export function statusSummary(bookings) {
  return Object.keys(BOOKING_STATUSES).reduce((summary, status) => {
    summary[status] = bookings.filter((booking) => booking.status === status).length;
    return summary;
  }, {});
}

export function monthGridDays(selectedDate) {
  const { start, end } = getCalendarRange('month', selectedDate);
  const days = [];
  for (let day = start; day <= end; day = addDays(day, 1)) days.push(day);
  return days;
}
