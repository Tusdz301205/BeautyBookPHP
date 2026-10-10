import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { format } from 'date-fns';
import toast from 'react-hot-toast';
import { TicketPercent } from 'lucide-react';
import { bookingsApi, recurringApi, servicesApi } from '../../api/apiClient';
import { useBookingStore } from '../../store/bookingStore';
import { useAuthStore } from '../../store/authStore';
import { Button, Card, InlineNotice, Skeleton } from '../../components/ui';
import { BookingActions, BookingLayout } from '../../components/customer/BookingLayout';
import { useAsyncResource } from '../../hooks/useAsyncResource';
import { BookingPolicyNotice } from '../../components/customer/BookingPolicyNotice';
import { beginBookingSubmission, canReplayBookingSubmission, readBookingSubmission, submitBookingSubmission } from '../../utils/bookingSubmission';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;

export default function BookingConfirm() {
  const navigate = useNavigate();
  const state = useBookingStore();
  const user = useAuthStore((store) => store.user);
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);
  const [attempt, setAttempt] = useState(() => readBookingSubmission(user?.id));
  const [submissionError, setSubmissionError] = useState('');
  useEffect(() => { setAttempt(readBookingSubmission(user?.id)); }, [user?.id]);
  const [acknowledged, setAcknowledged] = useState(false);
  const policyResource = useAsyncResource(user && state.branchId ? JSON.stringify([user.id, state.branchId]) : null,
    () => bookingsApi.selfBookingPolicy(state.branchId));
  const policy = policyResource.data;
  useEffect(() => { setAcknowledged(false); }, [user?.id, state.branchId, policy]);

  useEffect(() => {
    if (!attempt && (!state.branchId || !state.serviceIds.length || !state.slot)) { navigate('/book', { replace: true }); return; }
  }, [attempt, state.branchId, state.serviceIds, state.slot, navigate]);

  const serviceKey = state.branchId && state.serviceIds.length ? JSON.stringify([state.branchId, state.serviceIds]) : null;
  const serviceResource = useAsyncResource(serviceKey, async () => {
    const result = await servicesApi.getAll(state.branchId);
    const selected = (result.data ?? result ?? []).filter((item) => state.serviceIds.includes(item.id));
    if (selected.length !== state.serviceIds.length) throw new Error('Một số dịch vụ không còn khả dụng. Vui lòng chọn lại dịch vụ.');
    return selected;
  });
  const services = serviceResource.data || [];
  const loading = serviceResource.loading;

  const variantByService = useMemo(() => new Map(services.map((service) => [
    service.id,
    service.variants?.find((variant) => variant.id === state.variantSelections[service.id]) || null,
  ])), [services, state.variantSelections]);
  const subtotal = state.combo ? Number(state.combo.comboPrice) : services.reduce((sum, service) => sum + Number(variantByService.get(service.id)?.price ?? service.price ?? 0), 0);
  const pricePayload = {
      branchId: state.branchId,
      serviceIds: state.serviceIds,
      comboId: state.comboId,
      voucherCode: !state.recurring.enabled && state.voucherCode ? state.voucherCode : undefined,
      variantSelections: state.variantSelections,
      appointmentDate: state.slot?.start,
  };
  const priceResource = useAsyncResource(services.length && state.slot ? JSON.stringify([user?.id, pricePayload]) : null, () => bookingsApi.previewPrice(pricePayload));
  const preview = priceResource.data;

  const recurringPayload = state.slot ? {
    branchId: state.branchId,
    serviceIds: state.serviceIds,
    comboId: state.comboId || undefined,
    staffId: state.staffId || undefined,
    staffMode: state.recurring.staffMode,
    frequency: state.recurring.frequency,
    startDate: state.date,
    preferredTime: format(new Date(state.slot.start), 'HH:mm'),
    occurrenceCount: state.recurring.occurrenceCount,
  } : null;
  const recurringResource = useAsyncResource(user && state.recurring.enabled && recurringPayload && !Object.keys(state.variantSelections).length
    ? JSON.stringify([user.id, recurringPayload]) : null, () => recurringApi.preview(recurringPayload));
  const recurringPreview = recurringResource.data;
  const previewingRecurring = recurringResource.loading;
  const recurringReady = !state.recurring.enabled || (recurringPreview?.availableCount > 0 && (state.recurring.skipConflicts || !recurringPreview.conflictCount));
  const canConfirm = Boolean(user && preview && !loading && !priceResource.loading && !previewingRecurring && recurringReady
    && policy && !policyResource.loading && !policyResource.error && policy.selfBookingAllowed
    && (!policy.acknowledgmentRequired || acknowledged));

  const confirm = async (replay = false) => {
    if (submittingRef.current || !user || useAuthStore.getState().user?.id !== user.id
      || (replay ? attempt?.userId !== user.id || !canReplayBookingSubmission(attempt) : !canConfirm || attempt)) return;
    submittingRef.current = true;
    setSubmitting(true);
    try {
      const current = replay ? attempt : beginBookingSubmission(user.id, state.recurring.enabled ? 'recurring' : 'single', state.recurring.enabled
        ? { ...recurringPayload, skipConflicts: state.recurring.skipConflicts, note: state.customerInfo.note, violationAcknowledged: acknowledged,
          customerName: state.customerInfo.fullName, customerPhone: state.customerInfo.phone, contactConsent: state.customerInfo.consent }
        : {
          branchId: state.branchId,
          serviceIds: state.serviceIds,
          variantSelections: state.variantSelections,
          comboId: state.comboId || undefined,
          appointmentDate: state.slot.start,
          note: state.customerInfo.note,
          customerName: state.customerInfo.fullName,
          customerPhone: state.customerInfo.phone,
          contactConsent: state.customerInfo.consent,
          staffId: state.staffId,
          voucherCode: state.voucherCode || undefined,
          source: 'ONLINE_WEB',
          violationAcknowledged: acknowledged,
        });
      setAttempt(current); setSubmissionError('');
      const result = await submitBookingSubmission(current, (payload, key) => current.kind === 'recurring'
        ? recurringApi.create(payload, key, { signal: AbortSignal.timeout(20_000) })
        : bookingsApi.create(payload, key, { signal: AbortSignal.timeout(20_000) }));
      if (useAuthStore.getState().user?.id !== current.userId) return;
      if (result.kind === 'busy') return;
      if (result.kind === 'unknown') { setAttempt(result.attempt); return; }
      setAttempt(null);
      if (result.kind === 'rejected') throw result.error;
      const { booking, plan } = result;
      toast.success(plan ? `Đã tạo chuỗi ${plan.bookings?.length || 0} lịch hẹn` : booking.status === 'PENDING' ? 'Đã gửi yêu cầu đặt lịch' : 'Đặt lịch thành công');
      navigate('/book/success', { state: { booking, plan } });
    } catch (error) {
      if (['SELF_BOOKING_RESTRICTED', 'BOOKING_WARNING_ACK_REQUIRED'].includes(error.details?.code)) {
        setAcknowledged(false);
        policyResource.reload();
        toast.error(error.message);
      } else if (/nhân viên (này không còn khả dụng|phù hợp trong khung giờ)|không còn nhân viên phù hợp trong khung giờ/i.test(error.message || '')) {
        toast.error('Một hoặc nhiều khung giờ vừa có người đặt. Vui lòng kiểm tra lại.');
        navigate('/book/time');
      } else if (error.status) toast.error(error.message || 'Yêu cầu đặt lịch bị từ chối');
      else setSubmissionError('Không thể lưu trạng thái yêu cầu trên thiết bị. Vui lòng kiểm tra trình duyệt trước khi gửi.');
    } finally { submittingRef.current = false; setSubmitting(false); }
  };

  const total = preview?.finalAmount ?? subtotal;
  const summary = <Card className="sticky top-24 p-5"><h2 className="text-sm font-bold">Tóm tắt giá dịch vụ</h2><div className="mt-4 space-y-2 text-sm"><div className="flex justify-between"><span className="text-[var(--bb-muted)]">{state.recurring.enabled ? 'Tạm tính mỗi kỳ' : 'Tạm tính'}</span><span>{money(preview?.subtotal ?? subtotal)}</span></div>{preview?.promotionDiscount > 0 && <div className="flex justify-between text-[var(--bb-success)]"><span>Khuyến mãi tự động</span><span>-{money(preview.promotionDiscount)}</span></div>}{preview?.voucherApplied && <div className="flex justify-between text-[var(--bb-success)]"><span>Voucher {preview.code}</span><span>-{money(preview.voucherDiscount)}</span></div>}<div className="flex justify-between border-t border-[var(--bb-border)] pt-3 text-base font-bold"><span>{state.recurring.enabled ? 'Kỳ đầu tiên' : 'Tổng cộng'}</span><span className="text-[var(--bb-brand-strong)]">{priceResource.loading ? 'Đang tính giá…' : preview ? money(total) : 'Chưa có giá xác nhận'}</span></div></div>{state.recurring.enabled && <p className="mt-3 text-xs text-[var(--bb-muted)]">Giá từng kỳ được xác định khi tạo chuỗi lịch. Voucher không áp dụng cho chuỗi lịch.</p>}{(serviceResource.error || priceResource.error || recurringResource.error) && <div className="mt-4"><InlineNotice tone="danger">{(serviceResource.error || priceResource.error || recurringResource.error).message}</InlineNotice><Button variant="secondary" className="mt-2 w-full" onClick={() => { serviceResource.reload(); priceResource.reload(); recurringResource.reload(); }}>Thử lại</Button></div>}<BookingActions loading={submitting || priceResource.loading || previewingRecurring} disabled={!canConfirm || !!attempt} onNext={() => confirm()} nextLabel={state.recurring.enabled ? 'Xác nhận chuỗi lịch' : 'Xác nhận đặt lịch'} summary={preview ? money(total) : 'Đang tính giá…'} /></Card>;

  return <BookingLayout step={5} title="Kiểm tra và xác nhận" aside={summary}>
    {attempt && <div className="mb-5" role="status" aria-live="polite"><InlineNotice tone="warning">{submitting ? 'Đang gửi yêu cầu đặt lịch. Vui lòng chờ kết quả.' : 'Chưa xác định được kết quả đặt lịch. Yêu cầu có thể đã được lưu. Không gửi lịch mới trước khi kiểm tra.'}</InlineNotice>{!submitting && <div className="mt-3 flex flex-wrap gap-3">{canReplayBookingSubmission(attempt) && <Button variant="secondary" onClick={() => confirm(true)}>Kiểm tra lại yêu cầu đã gửi</Button>}<Button variant="secondary" onClick={() => navigate('/customer/appointments')}>Xem lịch hẹn</Button></div>}</div>}
    {submissionError && <InlineNotice tone="danger">{submissionError}</InlineNotice>}
    {policyResource.loading && <p role="status">Đang kiểm tra chính sách đặt lịch…</p>}
    {policyResource.error && <InlineNotice tone="danger">Không thể kiểm tra chính sách đặt lịch. <Button variant="secondary" onClick={policyResource.reload}>Thử lại</Button></InlineNotice>}
    <BookingPolicyNotice policy={policy} acknowledged={acknowledged} onAcknowledge={setAcknowledged} />
    {loading ? <Skeleton rows={6} /> : <div className="space-y-6"><section><h2 className="text-sm font-bold">{state.combo ? `Combo ${state.combo.name}` : 'Dịch vụ đã chọn'}</h2><div className="mt-3 divide-y divide-[var(--bb-border)] rounded-xl border border-[var(--bb-border)]">{services.map((service) => { const variant = variantByService.get(service.id); const duration = variant?.durationMinutes ?? service.durationMinutes; return <div key={service.id} className="bb-booking-service-row flex items-start justify-between gap-4 p-4"><div><p className="font-semibold">{service.name}</p>{variant && <p className="mt-1 text-xs font-semibold text-[var(--bb-brand-strong)]">{variant.name}</p>}<p className="mt-1 text-xs text-[var(--bb-muted)]">{duration > 0 ? `${duration} phút` : 'Thời lượng đang cập nhật'}</p></div>{!state.combo && <p className="font-bold">{variant?.priceDisplay || (variant?.price != null || service.price != null ? money(variant?.price ?? service.price) : 'Giá đang cập nhật')}</p>}</div>; })}</div></section>
      <section><h2 className="text-sm font-bold">Chi tiết lịch hẹn</h2><dl className="mt-3 divide-y divide-[var(--bb-border)] rounded-xl border border-[var(--bb-border)] text-sm">
        <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 p-3 sm:grid-cols-[7rem_minmax(0,1fr)]"><dt className="text-[var(--bb-muted)]">Thời gian</dt><dd className="font-bold">{state.slot && format(new Date(state.slot.start), 'dd/MM/yyyy · HH:mm')}</dd></div>
        <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 p-3 sm:grid-cols-[7rem_minmax(0,1fr)]"><dt className="text-[var(--bb-muted)]">Chi nhánh</dt><dd className="min-w-0"><strong className="block">{state.branchName || 'Chi nhánh đã chọn'}</strong>{state.branchAddress && <span className="mt-1 block text-xs text-[var(--bb-muted)]">{state.branchAddress}</span>}</dd></div>
        <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 p-3 sm:grid-cols-[7rem_minmax(0,1fr)]"><dt className="text-[var(--bb-muted)]">Chuyên viên</dt><dd className="min-w-0 font-semibold">{state.staffId ? (state.staffName || 'Chuyên viên đã chọn') : 'Cơ sở tự phân công người phù hợp'}</dd></div>
        <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 p-3 sm:grid-cols-[7rem_minmax(0,1fr)]"><dt className="text-[var(--bb-muted)]">Người đặt</dt><dd className="min-w-0"><strong className="block">{state.customerInfo.fullName}</strong><span className="mt-1 block text-xs text-[var(--bb-muted)]">{state.customerInfo.phone}</span></dd></div>
      </dl></section>
      {state.recurring.enabled && Object.keys(state.variantSelections).length > 0 && <InlineNotice tone="warning">Lựa chọn dịch vụ này chỉ hỗ trợ đặt một lần. Hãy tắt lịch lặp để tiếp tục.</InlineNotice>}
      {state.recurring.enabled && Object.keys(state.variantSelections).length === 0 && <InlineNotice tone={recurringPreview?.conflictCount ? 'warning' : 'success'}>{previewingRecurring ? 'Đang kiểm tra toàn bộ chuỗi lịch…' : recurringPreview ? `${recurringPreview.availableCount} kỳ còn chỗ${recurringPreview.conflictCount ? `, ${recurringPreview.conflictCount} kỳ xung đột sẽ ${state.recurring.skipConflicts ? 'được bỏ qua' : 'cần xử lý'}.` : '.'}` : 'Chưa có kết quả kiểm tra chuỗi lịch.'}</InlineNotice>}
      {state.voucherCode && !state.recurring.enabled && <InlineNotice tone={preview?.voucherApplied ? 'success' : 'warning'}><span className="flex items-center gap-2"><TicketPercent size={16} />{preview?.voucherApplied ? `Mã ${state.voucherCode} đã áp dụng.` : preview?.explanations?.join(' · ') || `Mã ${state.voucherCode} không đủ điều kiện.`}</span></InlineNotice>}
      <div className="border-t border-[var(--bb-border)] pt-5"><Button variant="secondary" disabled={submitting || !!attempt} onClick={() => navigate('/book/info')}>Quay lại chỉnh sửa</Button></div>
    </div>}
  </BookingLayout>;
}
