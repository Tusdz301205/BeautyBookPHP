import React, { useMemo } from 'react';
import { format, isSameMonth, isToday } from 'date-fns';
import { BOOKING_STATUSES } from '../../../constants/status';
import { groupBookingsByDay, monthGridDays, statusSummary } from '../../../utils/bookingCalendar.utils';

import { useOperationalElapsed } from './OperationalTiming';
import { bookingOperationalLabels } from '../../../utils/operationalTiming';

const STATUS_ORDER = ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'NO_SHOW'];

export default function SchedulerMonthView({ currentDate, bookings, onDateClick }) {
  const elapsed = useOperationalElapsed();
  const days = useMemo(() => monthGridDays(currentDate), [currentDate]);
  const grouped = useMemo(() => groupBookingsByDay(bookings), [bookings]);

  return (
    <div className="h-full overflow-auto bg-zinc-50/60 p-3 sm:p-4">
      <div className="min-w-[720px] overflow-hidden rounded-xl border border-zinc-200 bg-white">
        <div className="grid grid-cols-7 border-b border-zinc-200 bg-zinc-50">
          {['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'CN'].map((label) => (
            <div key={label} className="border-r border-zinc-200 px-2 py-3 text-center text-xs font-bold text-zinc-600 last:border-r-0">{label}</div>
          ))}
        </div>
        <div className="grid grid-cols-7">
          {days.map((day) => {
            const key = format(day, 'yyyy-MM-dd');
            const dayBookings = grouped[key] || [];
            const summary = statusSummary(dayBookings);
            const inMonth = isSameMonth(day, currentDate);
            return (
              <button
                key={key}
                type="button"
                onClick={() => onDateClick(day, dayBookings)}
                className={`min-h-[128px] border-b border-r border-zinc-200 p-2.5 text-left transition hover:bg-pink-50/50 focus-visible:z-10 ${inMonth ? 'bg-white' : 'bg-zinc-50 text-zinc-400'}`}
              >
                <span className={`grid h-7 w-7 place-items-center rounded-full text-sm font-bold ${isToday(day) ? 'bg-pink-700 text-white' : inMonth ? 'text-zinc-900' : 'text-zinc-400'}`}>{format(day, 'd')}</span>
                {dayBookings.length > 0 ? (
                  <span className="mt-2 block">
                    <span className="block text-sm font-bold text-zinc-950">{dayBookings.length} lịch</span>
                    {dayBookings.some((booking) => bookingOperationalLabels(booking, elapsed).length) && <span className="block whitespace-normal text-xs font-semibold text-amber-800">{dayBookings.filter((booking) => bookingOperationalLabels(booking, elapsed).length).length} lịch cần kiểm tra thời gian</span>}
                    <span className="mt-1.5 grid gap-1">
                      {STATUS_ORDER.filter((statusKey) => summary[statusKey]).slice(0, 3).map((statusKey) => {
                        const count = summary[statusKey];
                        if (!count) return null;
                        const status = BOOKING_STATUSES[statusKey];
                        return (
                          <span key={statusKey} className="flex items-center gap-1.5 text-[11px] font-medium text-zinc-600">
                            <span className={`h-1.5 w-1.5 rounded-full ${status.dot}`} />
                            <span className="truncate">{count} {status.label.toLowerCase()}</span>
                          </span>
                        );
                      })}
                      {STATUS_ORDER.filter((statusKey) => summary[statusKey]).length > 3 && (
                        <span className="text-[11px] font-semibold text-zinc-500">+ trạng thái khác</span>
                      )}
                    </span>
                  </span>
                ) : (
                  <span className="mt-3 block text-xs text-zinc-400">Không có lịch</span>
                )}
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}
