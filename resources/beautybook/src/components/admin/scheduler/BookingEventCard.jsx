import React from 'react';
import OperationalTiming, { useOperationalElapsed } from './OperationalTiming';
import { bookingOperationalLabels } from '../../../utils/operationalTiming';
import { format } from 'date-fns';
import { BOOKING_STATUSES } from '../../../constants/status';

export default function BookingEventCard({
  booking,
  compact = false,
  style,
  onClick,
  draggable = false,
  onDragStart,
  onResizeStart,
}) {
  const warnings = bookingOperationalLabels(booking, useOperationalElapsed()).join(' · ');
  const status = BOOKING_STATUSES[booking.status] ?? BOOKING_STATUSES.PENDING;
  const durationMinutes = Math.max(0, (booking.endAt - booking.startAt) / 60000);
  const tiny = durationMinutes <= 30;
  const short = durationMinutes <= 45;

  return (
    <button
      type="button"
      draggable={draggable}
      onDragStart={onDragStart}
      onClick={onClick}
      className={`group absolute overflow-hidden rounded-lg border-l-[3px] px-2 py-1.5 text-left shadow-sm transition-[box-shadow,filter] hover:shadow-md focus-visible:z-30 ${status.event}`}
      style={style}
      aria-label={`${format(booking.startAt, 'HH:mm')} đến ${format(booking.endAt, 'HH:mm')}, ${booking.customerName}, ${booking.serviceNames.join(', ')}, ${booking.primaryStaffName || 'chưa phân công'}, ${status.label}${warnings ? ` · ${warnings}` : ''}`}
      title={`${format(booking.startAt, 'HH:mm')}–${format(booking.endAt, 'HH:mm')} · ${booking.customerName} · ${booking.serviceNames.join(', ')} · ${booking.primaryStaffName || 'Chưa phân công'} · ${status.label}${warnings ? ` · ${warnings}` : ''}`}
    >
      <span className="flex flex-wrap items-center gap-x-1 gap-y-0 text-[10px] font-bold leading-tight tabular-nums">
        <span>{format(booking.startAt, 'HH:mm')}{!tiny && `–${format(booking.endAt, 'HH:mm')}`}</span>
        {warnings && <span title={warnings} aria-hidden="true">⚠</span>}
        {!tiny && <span className="min-w-0 opacity-75">{status.label}</span>}
      </span>
      <span className={`${compact && !short ? 'line-clamp-2' : 'line-clamp-1'} break-words text-xs font-semibold leading-tight`}>{booking.customerName}</span>
      {!short && <span className="line-clamp-1 break-words text-[10px] leading-tight opacity-80">{booking.serviceNames.join(', ') || 'Chưa có dịch vụ'}{compact && booking.primaryStaffName ? ` · ${booking.primaryStaffName}` : ''}</span>}
      {!compact && !short && <span className="mt-0.5 line-clamp-1 break-words text-[10px] leading-tight opacity-70">{booking.controlledOverbooking ? '⚠ Overbooking · chưa phân công' : booking.primaryStaffName || 'Chưa phân công'}</span>}
      <OperationalTiming booking={booking} />
      {onResizeStart && (
        <span
          role="separator"
          aria-label="Thay đổi thời lượng"
          onPointerDown={onResizeStart}
          onClick={(event) => event.stopPropagation()}
          className="absolute inset-x-0 bottom-0 h-2 cursor-ns-resize opacity-0 transition-opacity group-hover:opacity-100"
        />
      )}
    </button>
  );
}
