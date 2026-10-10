import React, { useEffect, useMemo, useRef } from 'react';
import { format, isSameDay } from 'date-fns';
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
  layoutOverlaps,
  makeHours,
} from '../../../utils/bookingCalendar.utils';

const STAFF_WIDTH = 260;

export default function SchedulerDayView({
  currentDate,
  staffList,
  bookings,
  onBookingClick,
  onMoveBooking,
  onResizeBooking,
}) {
  const gridRef = useRef(null);
  const lastScrollKey = useRef('');
  const now = calendarServerNow(bookings, useOperationalElapsed());
  const hours = useMemo(makeHours, []);
  const visibleStaff = useMemo(() => {
    const known = [...staffList];
    bookings.forEach((booking) => {
      if (booking.primaryStaffId && !known.some((staff) => staff.id === booking.primaryStaffId)) {
        known.push({ id: booking.primaryStaffId, name: booking.primaryStaffName || 'Nhân viên', avatarUrl: null });
      }
    });
    if (bookings.some((booking) => !booking.primaryStaffId)) {
      known.unshift({ id: '__unassigned__', name: 'Chưa phân công', avatarUrl: null });
    }
    return known;
  }, [bookings, staffList]);

  const byStaff = useMemo(() => visibleStaff.reduce((result, staff) => {
    const matches = bookings.filter((booking) => {
      const target = booking.primaryStaffId || '__unassigned__';
      return target === staff.id && isSameDay(booking.startAt, currentDate);
    });
    result[staff.id] = layoutOverlaps(matches);
    return result;
  }, {}), [bookings, currentDate, visibleStaff]);

  const showNow = now && isSameDay(currentDate, now) && now.getHours() >= CALENDAR_START_HOUR && now.getHours() <= 23;

  useEffect(() => {
    const key = `${format(currentDate, 'yyyy-MM-dd')}:${bookings.map((item) => item.id).join(',')}`;
    if (!gridRef.current || lastScrollKey.current === key) return;
    lastScrollKey.current = key;
    const dayBookings = bookings.filter((booking) => isSameDay(booking.startAt, currentDate)).sort((a, b) => a.startAt - b.startAt);
    const target = now && isSameDay(currentDate, now)
      ? dayBookings.find((booking) => booking.endAt > now)?.startAt || now
      : dayBookings[0]?.startAt;
    if (!target) return;
    const top = Math.max(0, eventTop(target) - HOUR_HEIGHT - 64);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    requestAnimationFrame(() => gridRef.current?.scrollTo({ top, behavior: reduced ? 'auto' : 'smooth' }));
  }, [bookings, currentDate]);

  const handleDrop = (event, staffId) => {
    event.preventDefault();
    const bookingId = event.dataTransfer.getData('text/booking-id');
    const booking = bookings.find((item) => item.id === bookingId);
    if (!booking || staffId === '__unassigned__') return;
    const rect = event.currentTarget.getBoundingClientRect();
    const y = Math.max(0, event.clientY - rect.top);
    const rawMinutes = Math.round((y / HOUR_HEIGHT) * 60 / 15) * 15;
    const start = new Date(currentDate);
    start.setHours(CALENDAR_START_HOUR, 0, 0, 0);
    start.setMinutes(rawMinutes);
    const duration = booking.endAt - booking.startAt;
    const end = new Date(start.getTime() + duration);
    onMoveBooking?.(booking, start, end, staffId);
  };

  const handleResizeStart = (event, booking) => {
    event.preventDefault();
    event.stopPropagation();
    const startY = event.clientY;
    const originalEnd = booking.endAt;
    const onPointerUp = (upEvent) => {
      const deltaMinutes = Math.round(((upEvent.clientY - startY) / HOUR_HEIGHT) * 60 / 15) * 15;
      if (deltaMinutes) {
        const nextEnd = new Date(Math.max(booking.startAt.getTime() + 15 * 60000, originalEnd.getTime() + deltaMinutes * 60000));
        onResizeBooking?.(booking, nextEnd);
      }
      document.removeEventListener('pointerup', onPointerUp);
    };
    document.addEventListener('pointerup', onPointerUp);
  };

  return (
    <div className="h-full scroll-pt-20 overflow-auto bg-white" ref={gridRef}>
      <div className="min-w-max" style={{ width: 72 + Math.max(1, visibleStaff.length) * STAFF_WIDTH }}>
        <div className="sticky top-0 z-30 grid border-b border-zinc-200 bg-white/95 backdrop-blur" style={{ gridTemplateColumns: `72px repeat(${Math.max(1, visibleStaff.length)}, ${STAFF_WIDTH}px)` }}>
          <div className="sticky left-0 z-40 border-r border-zinc-200 bg-white" />
          {visibleStaff.map((staff) => (
            <div key={staff.id} className="flex h-20 items-center gap-2 border-r border-zinc-200 px-3">
              <span className="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-full bg-pink-50 text-xs font-bold text-pink-700">
                {staff.avatarUrl ? <img src={staff.avatarUrl} alt="" className="h-full w-full object-cover" /> : staff.name.slice(0, 2).toUpperCase()}
              </span>
              <span className="min-w-0 break-words text-sm font-semibold leading-snug text-zinc-900">{staff.name}</span>
            </div>
          ))}
        </div>

        <div className="grid" style={{ gridTemplateColumns: `72px repeat(${Math.max(1, visibleStaff.length)}, ${STAFF_WIDTH}px)` }}>
          <div className="sticky left-0 z-20 border-r border-zinc-200 bg-white" style={{ height: calendarBodyHeight() }}>
            {hours.map((hour) => (
              <div key={hour} className="absolute right-3 -translate-y-2 text-[11px] font-medium tabular-nums text-zinc-500" style={{ top: CALENDAR_TOP_PADDING + (hour - CALENDAR_START_HOUR) * HOUR_HEIGHT }}>
                {String(hour).padStart(2, '0')}:00
              </div>
            ))}
            {showNow && <div className="absolute inset-x-0 z-30 -translate-y-1/2 text-right" style={{ top: eventTop(now) }}><span className="rounded bg-[var(--bb-brand)] px-1 text-[9px] font-bold text-white">{format(now, 'HH:mm')}</span></div>}
          </div>

          {visibleStaff.map((staff) => (
            <div
              key={staff.id}
              className="relative border-r border-zinc-200"
              style={{ height: calendarBodyHeight() }}
              onDragOver={(event) => event.preventDefault()}
              onDrop={(event) => handleDrop(event, staff.id)}
            >
              {hours.map((hour) => (
                <React.Fragment key={hour}>
                  <span className="pointer-events-none absolute inset-x-0 border-t border-zinc-200" style={{ top: CALENDAR_TOP_PADDING + (hour - CALENDAR_START_HOUR) * HOUR_HEIGHT }} />
                  {hour < hours.at(-1) && <span className="pointer-events-none absolute inset-x-0 border-t border-dashed border-zinc-100" style={{ top: CALENDAR_TOP_PADDING + (hour - CALENDAR_START_HOUR) * HOUR_HEIGHT + HOUR_HEIGHT / 2 }} />}
                </React.Fragment>
              ))}
              {showNow && <span className="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-[var(--bb-brand)]" style={{ top: eventTop(now) }} aria-hidden="true"><span className="absolute -left-1 -top-1 h-2 w-2 rounded-full bg-[var(--bb-brand)]" /></span>}
              {(byStaff[staff.id] || []).map((booking) => {
                const width = 100 / booking.laneCount;
                return (
                  <BookingEventCard
                    key={booking.id}
                    booking={booking}
                    draggable={Boolean(onMoveBooking) && staff.id !== '__unassigned__'}
                    onDragStart={(event) => event.dataTransfer.setData('text/booking-id', booking.id)}
                    onClick={() => onBookingClick(booking)}
                    onResizeStart={onResizeBooking ? (event) => handleResizeStart(event, booking) : undefined}
                    style={{
                      top: eventTop(booking.startAt) + 2,
                      height: Math.max(26, eventHeight(booking.startAt, booking.endAt) - 4),
                      left: `calc(${booking.lane * width}% + 4px)`,
                      width: `calc(${width}% - 8px)`,
                      zIndex: 10 + booking.lane,
                    }}
                  />
                );
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
