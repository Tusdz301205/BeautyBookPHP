import React, { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Card, InlineNotice, SuccessState } from '../../components/ui';
import { PublicShell } from '../../components/layout/PublicShell';
import { useBookingStore } from '../../store/bookingStore';
import { validBookingResult } from '../../utils/bookingSubmission';
import { combineDateTime } from '../../utils/bookingCalendar.adapter';
import { format } from 'date-fns';

export default function BookingSuccess() {
  const locationState = useLocation().state;
  const resetBooking = useBookingStore((state) => state.reset);
  const booking = locationState?.booking;
  const verified = validBookingResult(booking);
  const appointment = booking?.appointmentStartTime ? combineDateTime(booking.appointmentDate, booking.appointmentStartTime) : null;
  const pending = booking?.status === 'PENDING';
  const holdMinutes = booking?.pendingExpiresAt
    ? Math.max(1, Math.ceil((new Date(booking.pendingExpiresAt).getTime() - Date.now()) / 60_000))
    : null;
  const title = pending ? 'Đã gửi yêu cầu đặt lịch' : 'Đặt lịch thành công';
  const message = pending
    ? 'Cơ sở sẽ xác nhận lịch của bạn. Lịch chưa được xem là đã xác nhận cho đến khi trạng thái thay đổi.'
    : 'Lịch hẹn của bạn đã được xác nhận.';

  useEffect(() => {
    if (verified) resetBooking();
  }, [resetBooking, verified]);

  if (!verified) return <PublicShell><Card className="mx-auto my-12 max-w-xl p-6"><InlineNotice tone="warning">Chưa có kết quả đặt lịch đã xác minh. Vui lòng kiểm tra danh sách lịch hẹn.</InlineNotice><Link to="/customer/appointments" className="mt-4 inline-flex min-h-11 items-center font-semibold">Xem lịch hẹn</Link></Card></PublicShell>;

  return <PublicShell><Card className="mx-auto my-12 max-w-xl p-6">
    <SuccessState title={title} message={message} action={<div className="flex flex-wrap justify-center gap-2"><Link to="/customer/appointments" className="inline-flex min-h-11 items-center rounded-lg bg-[var(--bb-brand)] px-4 text-sm font-semibold text-white">Xem lịch hẹn</Link><Link to="/book" className="inline-flex min-h-11 items-center rounded-lg border border-[var(--bb-border)] px-4 text-sm font-semibold">Đặt lịch khác</Link></div>} />
    {pending && <InlineNotice tone="warning"><span>Khung giờ đang được giữ tạm{holdMinutes ? ` trong khoảng ${holdMinutes} phút` : ''}. Nếu hết hạn trước khi cơ sở xác nhận, khung giờ sẽ được mở lại.</span></InlineNotice>}
    {booking?.bookingCode && <p className="mt-4 text-center text-sm text-[var(--bb-muted)]">Mã lịch <strong className="bb-mono text-[var(--bb-ink)]">{booking.bookingCode}</strong></p>}
    <dl className="mt-5 space-y-3 border-t border-[var(--bb-border)] pt-5 text-sm">
      {booking.branch?.name && <div><dt className="text-[var(--bb-muted)]">Cơ sở</dt><dd className="font-semibold">{booking.branch.name}</dd></div>}
      {booking.bookingServices?.length > 0 && <div><dt className="text-[var(--bb-muted)]">Dịch vụ</dt><dd className="font-semibold">{booking.bookingServices.map(item => item.service?.name).filter(Boolean).join(', ')}</dd></div>}
      {appointment && <div><dt className="text-[var(--bb-muted)]">Ngày giờ hẹn (giờ cơ sở)</dt><dd className="font-semibold">{format(appointment, 'dd/MM/yyyy · HH:mm')}</dd></div>}
    </dl>
  </Card></PublicShell>;
}
