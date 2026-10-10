import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useBookingStore } from '../../store/bookingStore';
import { useAuthStore } from '../../store/authStore';
import { Button, Field, InlineNotice, Input, Textarea } from '../../components/ui';
import { BookingActions, BookingLayout } from '../../components/customer/BookingLayout';

const normalizePhone = (value) => value.trim().replace(/[\s.-]/g, '');

export default function BookingStep4() {
  const navigate = useNavigate();
  const { branchId, serviceIds, slot, customerInfo, voucherCode, setCustomerInfo, setVoucherCode } = useBookingStore();
  const user = useAuthStore((state) => state.user);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!branchId || !serviceIds.length || !slot) navigate('/book', { replace: true });
  }, [branchId, serviceIds, slot, navigate]);

  useEffect(() => {
    if (user) setCustomerInfo({ fullName: user.fullName || customerInfo.fullName, email: user.email || customerInfo.email });
  }, [user]);

  const validate = () => {
    const next = {};
    if (!customerInfo.fullName || customerInfo.fullName.trim().length < 2) next.fullName = 'Vui lòng nhập họ tên có ít nhất 2 ký tự.';
    if (!/^(?:0\d{9}|\+84\d{9})$/.test(normalizePhone(customerInfo.phone || ''))) next.phone = 'Dùng 10 chữ số bắt đầu bằng 0, hoặc mã quốc gia +84.';
    if (!customerInfo.consent) next.consent = 'Bạn cần đồng ý xử lý dữ liệu để gửi yêu cầu đặt lịch.';
    setErrors(next);
    return !Object.keys(next).length;
  };

  const submit = (event) => {
    event.preventDefault();
    if (!validate()) return;
    navigate('/book/confirm');
  };

  return (
    <BookingLayout
      step={4}
      title="Thông tin liên hệ"
      description="Cơ sở dùng thông tin trong tài khoản để xác nhận lịch với bạn."
    >
      <form id="bb-booking-contact-form" onSubmit={submit} className="grid gap-5 sm:grid-cols-2" noValidate>
        <Field label="Họ và tên" required error={errors.fullName} className="sm:col-span-2">
          <Input autoComplete="name" required value={customerInfo.fullName} onChange={(event) => setCustomerInfo({ fullName: event.target.value })} />
        </Field>
        <Field label="Số điện thoại" required error={errors.phone}>
          <Input type="tel" inputMode="tel" autoComplete="tel" required value={customerInfo.phone} onChange={(event) => setCustomerInfo({ phone: event.target.value })} />
        </Field>
        <Field label="Email"><Input type="email" inputMode="email" autoComplete="email" disabled value={customerInfo.email} /></Field>

        <Field label="Ghi chú vận hành" hint="Chỉ ghi chỉ dẫn liên hệ, vị trí hoặc hỗ trợ di chuyển. Không nhập dị ứng, thuốc, thai kỳ hay thông tin sức khỏe tại đây." className="sm:col-span-2">
          <Textarea value={customerInfo.note} onChange={(event) => setCustomerInfo({ note: event.target.value })} placeholder="Ví dụ: gọi trước khi đến, lối vào ở tầng 2" maxLength={1000} />
        </Field>
        <Field label="Mã voucher" hint="Hệ thống sẽ kiểm tra và tính lại ở bước xác nhận." className="sm:col-span-2"><Input value={voucherCode} onChange={(event) => setVoucherCode(event.target.value.toUpperCase())} placeholder="Ví dụ: WELCOME10" /></Field>
        <div className="sm:col-span-2">
          <label className="flex min-h-11 items-start gap-3 rounded-xl border border-[var(--bb-border)] p-4 text-sm">
            <input type="checkbox" className="mt-1 h-4 w-4 accent-[var(--bb-brand)]" checked={Boolean(customerInfo.consent)} onChange={(event) => setCustomerInfo({ consent: event.target.checked })} />
            <span>Tôi đồng ý để BeautyBook xử lý thông tin liên hệ nhằm tạo, xác nhận và hỗ trợ lịch hẹn này.</span>
          </label>
          {errors.consent && <p role="alert" className="mt-1 text-xs text-[var(--bb-danger)]">{errors.consent}</p>}
        </div>
        <div className="sm:col-span-2"><InlineNotice tone="success">Bạn đang đặt lịch bằng tài khoản {user?.email}.</InlineNotice></div>
        <div className="sm:col-span-2"><BookingActions back={<Button variant="secondary" onClick={() => navigate('/book/time')}>Quay lại</Button>} type="submit" form="bb-booking-contact-form" nextLabel="Xem lại lịch hẹn" /></div>
      </form>
    </BookingLayout>
  );
}
