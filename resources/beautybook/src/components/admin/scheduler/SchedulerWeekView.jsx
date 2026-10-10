import React, { useEffect, useMemo, useRef } from 'react';
import { addDays, format, isSameDay, startOfWeek } from 'date-fns';
import { vi } from 'date-fns/locale';
import BookingEventCard from './BookingEventCard';
import { useOperationalElapsed } from './OperationalTiming';
import { calendarServerNow } from '../../../utils/operationalTiming';
import {
  CALENDAR_START_HOUR,
  CALENDAR_TOP_PADDING,
  HOUR_HEIGHT,
  calendarBodyHeight,
  eventHeight,
  eventTop,
  groupBookingsByDay,
  layoutOverlaps,
  makeHours,
} from '../../../utils/bookingCalendar.utils';

const DAY_WIDTH = 240;

export default function SchedulerWeekView({ currentDate, bookings, onBookingClick }) {
  const gridRef = useRef(null);
  const lastScrollKey = useRef('');
  const now = calendarServerNow(bookings, useOperationalElapsed());
  const weekStart = startOfWeek(currentDate, { weekStartsOn: 1 });
  const days = useMemo(() => Array.from({ length: 7 }, (_, index) => addDays(weekStart, index)), [weekStart]);
  const grouped = useMemo(() => groupBookingsByDay(bookings), [bookings]);
  const hours = useMemo(makeHours, []);

  const showNow = now && now.getHours() >= CALENDAR_START_HOUR && now.getHours() <= 23;

  useEffect(() => {
    const key = `${format(weekStart, 'yyyy-MM-dd')}:${bookings.map((item) => item.id).join(',')}`;
    if (!gridRef.current || lastScrollKey.current === key) return;
    lastScrollKey.current = key;
    const sorted = [...bookings].sort((a, b) => a.startAt - b.startAt);
    const todayInWeek = now && days.some((day) => isSameDay(day, now));
    const target = todayInWeek
      ? sorted.find((booking) => isSameDay(booking.startAt, now) && booking.endAt > now)?.startAt || now
      : sorted[0]?.startAt;
    if (!target) return;
    const top = Math.max(0, eventTop(target) - HOUR_HEIGHT - 64);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    requestAnimationFrame(() => gridRef.current?.scrollTo({ top, behavior: reduced ? 'auto' : 'smooth' }));
  }, [bookings, days, weekStart]);

  return (
    <div className="h-full scroll-pt-16 overflow-auto bg-white" ref={gridRef}>
      <div className="min-w-max" style={{ width: 72 + 7 * DAY_WIDTH }}>
        <div className="sticky top-0 z-30 grid border-b border-zinc-200 bg-white/95 backdrop-blur" style={{ gridTemplateColumns: `72px repeat(7, ${DAY_WIDTH}px)` }}>
          <div className="sticky left-0 z-40 border-r border-zinc-200 bg-white" />
          {days.map((day) => (
            <div key={day.toISOString()} className={`h-16 border-r border-zinc-200 px-3 py-2 text-center ${(now && isSameDay(day, now)) ? 'bg-pink-50' : ''}`}>
              <p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{format(day, 'EEE', { locale: vi })}</p>
              <p className={`mt-1 text-lg font-bold ${(now && isSameDay(day, now)) ? 'text-pink-700' : 'text-zinc-950'}`}>{format(day, 'dd')}</p>
            </div>
          ))}
        </div>

        <div className="grid" style={{ gridTemplateColumns: `72px repeat(7, ${DAY_WIDTH}px)` }}>
          <div className="sticky left-0 z-20 border-r border-zinc-200 bg-white" style={{ height: calendarBodyHeight() }}>
            {hours.map((hour) => (
              <div key={hour} className="absolute right-3 -translate-y-2 text-[11px] font-medium tabular-nums text-zinc-500" style={{ top: CALENDAR_TOP_PADDING + (hour - CALENDAR_START_HOUR) * HOUR_HEIGHT }}>
                {String(hour).padStart(2, '0')}:00
              </div>
            ))}
            {showNow && days.some((day) => isSameDay(day, now)) && <div className="absolute inset-x-0 z-30 -translate-y-1/2 text-right" style={{ top: eventTop(now) }}><span className="rounded bg-[var(--bb-brand)] px-1 text-[9px] font-bold text-white">{format(now, 'HH:mm')}</span></div>}
          </div>
          {days.map((day) => {
            const key = format(day, 'yyyy-MM-dd');
            const dayBookings = layoutOverlaps(grouped[key] || []);
            return (
              <div key={key} className={`relative border-r border-zinc-200 ${(now && isSameDay(day, now)) ? 'bg-pink-50/20' : ''}`} style={{ height: calendarBodyHeight() }}>
                {hours.map((hour) => (
                  <React.Fragment key={hour}>
                    <span className="pointer-events-none absolute inset-x-0 border-t border-zinc-200" style={{ top: CALENDAR_TOP_PADDING + (hour - CALENDAR_START_HOUR) * HOUR_HEIGHT }} />
                    {hour < hours.at(-1) && <span className="pointer-events-none absolute inset-x-0 border-t border-dashed border-zinc-100" style={{ top: CALENDAR_TOP_PADDING + (hour - CALENDAR_START_HOUR) * HOUR_HEIGHT + HOUR_HEIGHT / 2 }} />}
                  </React.Fragment>
                ))}
                {showNow && isSameDay(day, now) && <span className="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-[var(--bb-brand)]" style={{ top: eventTop(now) }} aria-hidden="true"><span className="absolute -left-1 -top-1 h-2 w-2 rounded-full bg-[var(--bb-brand)]" /></span>}
                {dayBookings.map((booking) => {
                  const width = 100 / booking.laneCount;
                  return (
                    <BookingEventCard
                      key={booking.id}
                      booking={booking}
                      compact
                      onClick={() => onBookingClick(booking)}
                      style={{
                        top: eventTop(booking.startAt) + 2,
                        height: Math.max(26, eventHeight(booking.startAt, booking.endAt) - 4),
                        left: `calc(${booking.lane * width}% + 3px)`,
                        width: `calc(${width}% - 6px)`,
                        zIndex: 10 + booking.lane,
                      }}
                    />
                  );
                })}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
