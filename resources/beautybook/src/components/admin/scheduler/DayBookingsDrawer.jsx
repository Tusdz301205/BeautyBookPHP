import React from 'react';
import OperationalTiming from './OperationalTiming';
import { ArrowRight, CalendarDays } from 'lucide-react';
import { format } from 'date-fns';
import { vi } from 'date-fns/locale';
import { BOOKING_STATUSES } from '../../../constants/status';
import { Button, Drawer, EmptyState } from '../../ui';

export default function DayBookingsDrawer({ date, bookings, onClose, onOpenBooking, onOpenDay }) {
  if (!date) return null;

  return (
    <Drawer open onClose={onClose} size="sm" title={format(date, 'EEEE, dd/MM/yyyy', { locale: vi })} description={`${bookings.length} lịch hẹn`} footer={<Button type="button" onClick={onOpenDay} className="w-full"><CalendarDays size={16} /> Xem theo ngày <ArrowRight size={15} /></Button>}>
        <div className="space-y-2">
          {bookings.length === 0 ? (
            <EmptyState title="Không có lịch hẹn" />
          ) : bookings.map((booking) => {
            const status = BOOKING_STATUSES[booking.status] ?? BOOKING_STATUSES.PENDING;
            return (
              <button key={booking.id} type="button" onClick={() => onOpenBooking(booking)} className="w-full rounded-xl border border-zinc-200 p-3 text-left transition hover:border-pink-200 hover:bg-pink-50/40">
                <div className="flex items-start gap-3">
                  <span className="w-12 shrink-0 text-sm font-bold tabular-nums text-zinc-950">{format(booking.startAt, 'HH:mm')}</span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-zinc-950">{booking.customerName}</span>
                    <span className="mt-0.5 block truncate text-xs text-zinc-500">{booking.serviceNames.join(', ')} · {booking.primaryStaffName || 'Chưa phân công'}</span>
                    <OperationalTiming booking={booking} />
                    <span className={`mt-2 inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${status.color}`}>{status.label}</span>
                  </span>
                </div>
              </button>
            );
          })}
        </div>
    </Drawer>
  );
}
