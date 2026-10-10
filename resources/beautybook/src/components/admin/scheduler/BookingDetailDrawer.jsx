import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CalendarDays, Clock3, History, MapPin, Phone, Scissors, Tag, UserRound } from 'lucide-react';
import { format } from 'date-fns';
import toast from 'react-hot-toast';
import { bookingsApi, servicesApi, staffApi } from '../../../api/apiClient';
import { BOOKING_STATUSES } from '../../../constants/status';
import { useAuthStore } from '../../../store/authStore';
import { normalizeBooking } from '../../../utils/bookingCalendar.adapter';
import { bookingCapabilities, bookingItemActions } from '../../../utils/authScope';
import { counterServicePayload, noShowAvailability } from '../../../utils/bookingAffordances';
import { formatActualServiceTime } from '../../../utils/bookingActualTime';
import { Button, Dialog, Drawer, Field, Input, Select, Textarea } from '../../ui';
import { BookingPolicyNotice } from '../../customer/BookingPolicyNotice';

import ActualTimeCorrection from './ActualTimeCorrection';
import ActualTimeCorrectionHistory from './ActualTimeCorrectionHistory';
import OperationalTiming, { OperationalClock, useOperationalElapsed } from './OperationalTiming';
import { useOperationalRefresh } from '../../../hooks/useOperationalRefresh';
import { anchoredServerNow, itemOperationalLabel, SERVICE_STATUS_LABELS } from '../../../utils/operationalTiming';

const money = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 });

function ItemOperation({ bookingId, branchId, item, allowedActions, cancelWholeBooking = false, onDone }) {
  const [action, setAction] = useState('');
  const [reason, setReason] = useState('');
  const [value, setValue] = useState('');
  const [staff, setStaff] = useState([]);
  const [busy, setBusy] = useState(false);
  const options = [
    ...(item.status === 'SCHEDULED' ? [{ value: 'START', label: 'Bắt đầu' }, { value: 'REMOVE', label: cancelWholeBooking ? 'Hủy toàn bộ lịch' : 'Hủy dịch vụ này' }] : []),
    ...(['SCHEDULED', 'IN_PROGRESS'].includes(item.status) ? [{ value: 'SKIP', label: 'Bỏ qua' }, { value: 'REASSIGN', label: 'Đổi nhân viên' }, { value: 'RESIZE', label: 'Đổi thời lượng' }, { value: 'REPRICE', label: 'Điều chỉnh giá' }] : []),
    ...(item.status === 'IN_PROGRESS' ? [{ value: 'COMPLETE', label: 'Hoàn thành' }] : []),
  ].filter((option) => allowedActions.includes(option.value));
  useEffect(() => {
    if (action !== 'REASSIGN') return;
    staffApi.getPublic(branchId, [item.id]).then((rows) => setStaff(Array.isArray(rows) ? rows : [])).catch(() => setStaff([]));
  }, [action, branchId, item.id]);
  if (!options.length) return null;
  const submit = async () => {
    if (!allowedActions.includes(action) || !reason.trim()) { toast.error('Chọn thao tác hợp lệ và nhập lý do'); return; }
    const payload = { action, reason: reason.trim(), expectedRevision: item.revision };
    if (action === 'REASSIGN') payload.staffId = value;
    if (action === 'RESIZE') payload.durationMinutes = Number(value);
    if (action === 'REPRICE') payload.price = Number(value);
    setBusy(true);
    try {
      if (action === 'REMOVE' && cancelWholeBooking) {
        await bookingsApi.updateStatus(bookingId, 'CANCELLED', undefined, reason.trim());
        toast.success('Đã hủy toàn bộ lịch và thông báo cho khách');
      } else {
        await bookingsApi.updateItem(bookingId, item.bookingServiceId, payload);
        toast.success('Đã cập nhật dịch vụ trong lịch');
      }
      setAction(''); setReason(''); setValue('');
      await onDone();
    } catch (error) { toast.error(error.message || 'Không thể cập nhật dịch vụ'); }
    finally { setBusy(false); }
  };
  return <div className="mt-3 grid gap-2 rounded-lg bg-zinc-50 p-3 sm:grid-cols-2"><Select value={action} onChange={(event) => { setAction(event.target.value); setValue(''); }}><option value="">Thao tác với dịch vụ</option>{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select>{action === 'REASSIGN' && <Select value={value} onChange={(event) => setValue(event.target.value)}><option value="">Chọn nhân viên</option>{staff.map((person) => <option key={person.id} value={person.id}>{person.fullName}</option>)}</Select>}{action === 'RESIZE' && <Input type="number" min="1" value={value} onChange={(event) => setValue(event.target.value)} placeholder="Thời lượng mới (phút)" />}{action === 'REPRICE' && <Input type="number" min="0" value={value} onChange={(event) => setValue(event.target.value)} placeholder="Giá mới" />}<Input className={action && !['REASSIGN', 'RESIZE', 'REPRICE'].includes(action) ? 'sm:col-span-2' : ''} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Lý do bắt buộc" /><Button size="sm" loading={busy} disabled={!action || !reason.trim() || (['REASSIGN', 'RESIZE', 'REPRICE'].includes(action) && !value)} onClick={submit}>Áp dụng</Button></div>;
}

function AddItemOperation({ bookingId, branchId, onDone }) {
  const [open, setOpen] = useState(false);
  const [services, setServices] = useState([]);
  const [serviceId, setServiceId] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!open) return;
    servicesApi.getAll(branchId).then((rows) => setServices(rows?.data ?? rows ?? [])).catch((error) => toast.error(error.message));
  }, [open, branchId]);
  if (!open) return <Button className="mt-3" size="sm" variant="secondary" onClick={() => setOpen(true)}>Thêm dịch vụ phát sinh</Button>;
  const submit = async () => {
    setBusy(true);
    try {
      await bookingsApi.addItem(bookingId, counterServicePayload({ serviceId, reason }));
      toast.success('Đã thêm dịch vụ và tạo khoản chênh lệch cần thu');
      setOpen(false); setServiceId(''); setReason('');
      await onDone();
    } catch (error) { toast.error(error.message || 'Không thể thêm dịch vụ'); }
    finally { setBusy(false); }
  };
  return <div className="mt-3 grid gap-2 rounded-xl border border-zinc-200 p-3"><Select value={serviceId} onChange={(event) => setServiceId(event.target.value)}><option value="">Chọn dịch vụ phát sinh</option>{services.map((service) => <option key={service.id} value={service.id}>{service.name} · {money.format(service.price)}</option>)}</Select><Input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Lý do thêm dịch vụ" /><div className="flex gap-2"><Button size="sm" loading={busy} disabled={!serviceId || !reason.trim()} onClick={submit}>Thêm</Button><Button size="sm" variant="ghost" onClick={() => setOpen(false)}>Đóng</Button></div></div>;
}

export default function BookingDetailDrawer(props) {
  return <OperationalClock><BookingDetailContent {...props} /></OperationalClock>;
}

function BookingDetailContent({ booking, onClose, onUpdated }) {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [normalCancelDialog, setNormalCancelDialog] = useState(false);
  const [noShowDialog, setNoShowDialog] = useState(false);
  const [noShowConfirmed, setNoShowConfirmed] = useState(false);
  const elapsed = useOperationalElapsed();
  const detailSequence = useRef(0);
  const [cancelDialog, setCancelDialog] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [cancelPreview, setCancelPreview] = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const activeBooking = detail || booking;

  const clock = anchoredServerNow(activeBooking?.timingAnchor, elapsed);

  useEffect(() => { setNoShowDialog(false); setNoShowConfirmed(false); }, [booking?.id]);

  const roles = useMemo(() => new Set([
    ...(user?.roles || []),
    ...(user?.scopes || []).map((scope) => scope.code),
  ]), [user]);
  const platform = user?.workspace === 'PLATFORM' || roles.has('PLATFORM_ADMIN');
  const capabilities = bookingCapabilities(user, activeBooking);
  const canSeeInternalNote = can('booking:read_internal_note:branch', capabilities.ctx) || can('booking:read:platform');
  const canSeePhone = can('booking:read:branch', capabilities.ctx) || can('booking:read:tenant', capabilities.ctx) || can('booking:read:platform');
  const canUpdate = !platform && capabilities.canUpdate;
  const canCheckIn = !platform && capabilities.canCheckIn;
  const canProvide = !platform && capabilities.canProvide;
  const canForceCancel = platform && can('booking:cancel:platform');
  const receivesGuests = !platform && capabilities.frontDesk;

  const reloadDetail = useCallback(async ({ required = false } = {}) => {
    if (!booking?.id) return;
    const sequence = ++detailSequence.current;
    try {
      const payload = await bookingsApi.getById(booking.id);
      if (sequence === detailSequence.current) setDetail(normalizeBooking(payload));
    } catch (error) { if (required) throw error; /* Retain observed facts until the next successful refresh. */ }
    finally { if (sequence === detailSequence.current) setDetailLoading(false); }
  }, [booking?.id]);
  useOperationalRefresh(reloadDetail);

  useEffect(() => {
    if (!booking) {
      setDetail(null);
      return undefined;
    }
    setDetail(null);
    setDetailLoading(true);
    void reloadDetail();
    return () => { detailSequence.current += 1; };
  }, [booking, reloadDetail]);

  if (!activeBooking) return null;
  const status = BOOKING_STATUSES[activeBooking.status] ?? BOOKING_STATUSES.PENDING;
  const duration = Math.max(0, Math.round((activeBooking.endAt - activeBooking.startAt) / 60000));
  const history = activeBooking.raw?.statusHistory || [];

  let nextAction = null;
  if (activeBooking.status === 'PENDING' && receivesGuests && canUpdate) nextAction = { status: 'CONFIRMED', label: 'Xác nhận lịch' };
  if (activeBooking.status === 'CONFIRMED' && receivesGuests && canCheckIn) nextAction = { status: 'CHECKED_IN', label: 'Xác nhận khách đã đến' };
  if (activeBooking.status === 'CHECKED_IN' && canProvide) nextAction = { status: 'IN_PROGRESS', label: 'Bắt đầu dịch vụ' };
  if (activeBooking.status === 'IN_PROGRESS' && !platform && capabilities.canComplete) nextAction = { status: 'COMPLETED', label: 'Hoàn thành dịch vụ' };
  const timeDecision = nextAction ? activeBooking.raw?.transitionAvailability?.[nextAction.status] : null;
  const blockedActionReason = timeDecision?.allowed === false ? timeDecision.reason : null;
  const forceCancellable = !['CANCELLED', 'COMPLETED'].includes(activeBooking.status);
  const canCancel = !platform && capabilities.canCancel && forceCancellable;
  const canMarkNoShow = !platform && !detailLoading && Number.isFinite(clock) && noShowAvailability({ ...activeBooking, startAt: activeBooking.services.find((item) => item.itemStartAt)?.itemStartAt }, capabilities, clock);
  const activeServiceCount = activeBooking.services.filter((service) => !['COMPLETED', 'SKIPPED', 'CANCELLED'].includes(service.status)).length;

  const runAction = async () => {
    if (!nextAction) return;
    detailSequence.current += 1;
    setBusy(true);
    try {
      const updated = nextAction.status === 'CHECKED_IN'
        ? await bookingsApi.checkin(activeBooking.id)
        : await bookingsApi.updateStatus(activeBooking.id, nextAction.status);
      detailSequence.current += 1;
      if (updated) setDetail(normalizeBooking(updated));
      toast.success(`Đã cập nhật: ${nextAction.label}`);
      await onUpdated?.();
    } catch (error) {
      toast.error(error.message || 'Không thể cập nhật lịch hẹn');
    } finally {
      setBusy(false);
    }
  };

  const refreshDetail = async () => {
    const refreshed = await bookingsApi.getById(activeBooking.id);
    setDetail(normalizeBooking(refreshed));
    onUpdated?.();
  };

  const markNoShow = async () => {
    if (!canMarkNoShow || !noShowConfirmed) return;
    setBusy(true);
    try {
      const updated = await bookingsApi.updateStatus(activeBooking.id, 'NO_SHOW', undefined, undefined, true);
      detailSequence.current += 1;
      if (updated) setDetail(normalizeBooking(updated));
      setNoShowDialog(false);
      setNoShowConfirmed(false);
      toast.success('Đã ghi nhận khách không đến');
      await onUpdated?.();
    } catch (error) { toast.error(error.message || 'Không thể ghi nhận khách không đến'); }
    finally { setBusy(false); }
  };

  const openForceCancel = async () => {
    setCancelDialog(true);
    setCancelReason('');
    setCancelPreview(null);
    setPreviewLoading(true);
    try {
      setCancelPreview(await bookingsApi.getForceCancelPreview(activeBooking.id));
    } catch (error) {
      toast.error(error.message || 'Không thể kiểm tra ảnh hưởng hủy lịch');
      setCancelDialog(false);
    } finally {
      setPreviewLoading(false);
    }
  };

  const cancelBooking = async () => {
    if (!cancelReason.trim()) return;
    setBusy(true);
    try {
      const updated = await bookingsApi.updateStatus(activeBooking.id, 'CANCELLED', undefined, cancelReason.trim());
      detailSequence.current += 1;
      if (updated) setDetail(normalizeBooking(updated));
      toast.success('Đã hủy lịch và thông báo cho khách');
      setNormalCancelDialog(false);
      setCancelReason('');
      await onUpdated?.();
    } catch (error) {
      toast.error(error.message || 'Không thể hủy lịch hẹn');
    } finally {
      setBusy(false);
    }
  };

  const forceCancel = async () => {
    if (!cancelReason.trim()) return;
    setBusy(true);
    try {
      await bookingsApi.forceCancel(activeBooking.id, cancelReason.trim());
      toast.success('Đã force-cancel lịch hẹn và ghi nhận lý do');
      setCancelDialog(false);
      onUpdated?.();
      onClose?.();
    } catch (error) {
      toast.error(error.message || 'Không thể force-cancel lịch hẹn');
    } finally {
      setBusy(false);
    }
  };

  const footer = (
    <div className="flex w-full flex-col gap-2">
      {platform && <p className="text-xs text-zinc-500">Platform chỉ quan sát; các thao tác vận hành do cơ sở thực hiện.</p>}
      {nextAction && !blockedActionReason && <Button className="w-full" loading={busy} onClick={runAction}>{nextAction.label}</Button>}
      {canCancel && <Button className="w-full" variant="danger" onClick={() => { setCancelReason(''); setNormalCancelDialog(true); }}>Hủy lịch</Button>}
      {canMarkNoShow && <Button className="w-full" variant="secondary" disabled={busy} onClick={() => { setNoShowConfirmed(false); setNoShowDialog(true); }}>Ghi nhận khách không đến</Button>}
      {canForceCancel && forceCancellable && <Button className="w-full" variant="danger" onClick={openForceCancel}>Force-cancel theo ngoại lệ</Button>}
    </div>
  );

  return <>
    <Drawer open={Boolean(activeBooking)} onClose={onClose} title="Chi tiết lịch hẹn" description={activeBooking.bookingCode} footer={footer}>
      <div className="space-y-6">
        <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${status.color}`}>{status.label}</span>
        <OperationalTiming booking={activeBooking} />
        <p className="text-xs text-zinc-600">{activeBooking.raw?.statusHistory?.some((entry) => entry.status === 'CHECKED_IN') || activeBooking.status === 'CHECKED_IN' ? 'Đã ghi nhận check-in' : 'Chưa có ghi nhận check-in'}</p>
        {detailLoading && <p role="status" className="text-xs font-medium text-zinc-500">Đang tải dữ liệu chi tiết và lịch sử...</p>}
        <section className="grid gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 sm:grid-cols-2">
          <Detail icon={CalendarDays} label="Ngày" value={format(activeBooking.startAt, 'dd/MM/yyyy')} />
          <Detail icon={Clock3} label="Giờ hẹn dự kiến" value={`${format(activeBooking.startAt, 'HH:mm')} – ${format(activeBooking.endAt, 'HH:mm')} · ${duration} phút`} />
          <Detail icon={UserRound} label="Khách hàng" value={activeBooking.customerName} />
          {canSeePhone && <Detail icon={Phone} label="Số điện thoại" value={activeBooking.customerPhone || '—'} />}
          <Detail icon={MapPin} label="Chi nhánh" value={activeBooking.branchName || '—'} />
          <Detail icon={UserRound} label="Nhân viên" value={activeBooking.primaryStaffName || 'Chưa phân công'} />
        </section>
        <section>
          <h3 className="text-sm font-bold text-zinc-950">Dịch vụ</h3>
          <div className="mt-2 divide-y divide-zinc-100 rounded-xl border border-zinc-200">
            {activeBooking.services.map((service, index) => {
              const timezone = activeBooking.raw?.branch?.timezone || 'Asia/Ho_Chi_Minh';
              const started = formatActualServiceTime(service.actualStartedAt, timezone);
              const stopped = formatActualServiceTime(service.actualStoppedAt, timezone);
              return <div key={service.bookingServiceId || index} className="px-4 py-3 text-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <span className="flex min-w-0 items-center gap-2 font-medium text-zinc-900"><Scissors size={15} className="shrink-0 text-pink-700" />{service.name}</span>
                  <span className="text-right text-zinc-500">Dự kiến: {service.durationMinutes ? `${service.durationMinutes} phút` : '—'}<small className="block">{SERVICE_STATUS_LABELS[service.status] || 'Chưa xác định'}</small></span>
                </div>
                {itemOperationalLabel(activeBooking.status, service, clock) && <p className="mt-2 text-xs font-semibold text-amber-800">{itemOperationalLabel(activeBooking.status, service, clock)}</p>}
                <dl className="mt-2 space-y-1 text-xs text-zinc-600">
                  <div><dt className="inline">Khung giờ dự kiến ({timezone}): </dt><dd className="inline">{formatActualServiceTime(service.itemStartAt, timezone) || 'Chưa có dữ liệu'} – {formatActualServiceTime(service.itemEndAt, timezone) || 'Chưa có dữ liệu'}</dd></div>
                  <div><dt className="inline">Bắt đầu thực tế: </dt><dd className="inline">{started || (service.actualTimingStatus === 'UNKNOWN' ? 'Chưa xác định' : 'Chưa có ghi nhận')}</dd></div>
                  <div><dt className="inline">{service.status === 'SKIPPED' ? 'Dừng thực tế: ' : 'Kết thúc thực tế: '}</dt><dd className="inline">{stopped || (service.actualTimingStatus === 'UNKNOWN' ? 'Chưa xác định' : 'Chưa có ghi nhận')}</dd></div>
                </dl>
                {service.actualTimingStatus === 'UNKNOWN' && <p className="mt-2 text-xs text-zinc-600">Thời gian thực tế chưa xác định.</p>}
                {service.actualTimingSource === 'ACTUAL_TIME_CORRECTION' && <p className="mt-1 text-xs text-zinc-600">Thời gian thực tế đã được bổ sung / hiệu chỉnh.</p>}
                {service.canCorrectActualTime && !detailLoading && service.bookingServiceId && <ActualTimeCorrection bookingId={activeBooking.id} item={service} timezone={activeBooking.raw?.branch?.timezone} serverNow={clock} onRefresh={() => reloadDetail({ required: true })} />}
                {service.canCorrectActualTime && !detailLoading && service.bookingServiceId && <ActualTimeCorrectionHistory bookingId={activeBooking.id} itemId={service.bookingServiceId} revision={service.revision} timezone={activeBooking.raw?.branch?.timezone} />}
                {!platform && service.bookingServiceId && <ItemOperation allowedActions={bookingItemActions(user, activeBooking, service)} bookingId={activeBooking.id} branchId={activeBooking.branchId} item={service} cancelWholeBooking={service.status === 'SCHEDULED' && activeServiceCount === 1} onDone={refreshDetail} />}
              </div>;
            })}
          </div>
          {canUpdate && ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'].includes(activeBooking.status) && <AddItemOperation bookingId={activeBooking.id} branchId={activeBooking.branchId} onDone={refreshDetail} />}
        </section>
        <section className="grid gap-3 sm:grid-cols-2">
          <Detail icon={Tag} label="Tổng tiền" value={money.format(activeBooking.totalAmount)} />
          <Detail icon={Tag} label="Giảm giá" value={activeBooking.discountAmount ? money.format(activeBooking.discountAmount) : '—'} />
        </section>
        <Note title="Ghi chú khách hàng" value={activeBooking.note} />
        <BookingPolicyNotice policy={activeBooking.raw?.violationSummary} salon />
        {canSeeInternalNote && <Note title="Ghi chú nội bộ" value={activeBooking.internalNote} />}
        {history.length > 0 && <section><h3 className="flex items-center gap-2 text-sm font-bold text-zinc-950"><History size={16} />Lịch sử trạng thái</h3><ol className="mt-2 space-y-2">{history.map((entry) => { const entryStatus = BOOKING_STATUSES[entry.status] ?? { label: entry.status }; return <li key={entry.id} className="rounded-xl border border-zinc-200 px-4 py-3"><div className="flex flex-wrap items-center justify-between gap-2"><strong className="text-sm text-zinc-900">{entryStatus.label}</strong><time className="text-xs text-zinc-500">{entry.createdAt ? format(new Date(entry.createdAt), 'HH:mm · dd/MM/yyyy') : '—'}</time></div>{entry.note && <p className="mt-1 text-sm text-zinc-600">{entry.note}</p>}</li>; })}</ol></section>}
        {blockedActionReason && <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">{blockedActionReason}</p>}
      </div>
    </Drawer>
    <Dialog open={noShowDialog} onClose={() => !busy && setNoShowDialog(false)} title="Xác nhận khách không đến" description="Chỉ ghi nhận sau 15 phút kể từ giờ hẹn. Khách đã báo hủy hoặc báo trễ không thuộc trường hợp này." footer={<><Button variant="secondary" disabled={busy} onClick={() => setNoShowDialog(false)}>Quay lại</Button><Button variant="danger" loading={busy} disabled={!noShowConfirmed || !canMarkNoShow} onClick={markNoShow}>Xác nhận không đến</Button></>}>
      <label className="flex items-start gap-3 text-sm text-zinc-700"><input type="checkbox" className="mt-1" checked={noShowConfirmed} onChange={(event) => setNoShowConfirmed(event.target.checked)} /><span>Tôi đã kiểm tra: khách chưa đến, chưa yêu cầu hủy và chưa báo hủy hoặc báo trễ qua điện thoại hay kênh khác.</span></label>
    </Dialog>
    <Dialog open={normalCancelDialog} onClose={() => !busy && setNormalCancelDialog(false)} title="Hủy lịch hẹn" description={`Mã lịch ${activeBooking.bookingCode}. Khách hàng sẽ nhận được trạng thái và thông báo hủy.`} footer={<><Button variant="secondary" onClick={() => setNormalCancelDialog(false)} disabled={busy}>Giữ lịch</Button><Button variant="danger" loading={busy} disabled={!cancelReason.trim()} onClick={cancelBooking}>Xác nhận hủy</Button></>}>
      <Field label="Lý do hủy" required><Textarea value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} maxLength={1000} placeholder="Nhập lý do để khách hàng biết vì sao lịch bị hủy" /></Field>
    </Dialog>
    <Dialog open={cancelDialog} onClose={() => !busy && setCancelDialog(false)} title="Force-cancel lịch hẹn" description="Ngoại lệ của Platform, luôn được ghi audit" footer={<><Button variant="secondary" onClick={() => setCancelDialog(false)} disabled={busy}>Đóng</Button><Button variant="danger" loading={busy} disabled={previewLoading || !cancelReason.trim()} onClick={forceCancel}>Xác nhận force-cancel</Button></>}>
      <div className="space-y-4">
        {previewLoading ? <p className="text-sm text-zinc-500">Đang kiểm tra ảnh hưởng...</p> : cancelPreview && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><p className="font-bold">Ảnh hưởng dự kiến</p><ul className="mt-2 list-disc space-y-1 pl-5"><li>Giải phóng {cancelPreview.impact?.releasesAssignedSlots || 0} phân công dịch vụ.</li><li>{cancelPreview.impact?.hasSuccessfulPayment ? 'Lịch có giao dịch đã thanh toán.' : 'Chưa ghi nhận giao dịch đã thanh toán.'}</li><li>Hệ thống không tự hoàn tiền; {cancelPreview.impact?.requiresSeparateRefundWorkflow ? 'cần mở quy trình hoàn tiền riêng.' : 'không cần quy trình hoàn tiền.'}</li></ul></div>}
        <Field label="Lý do bắt buộc" required><Textarea value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} maxLength={1000} placeholder="Nêu rõ căn cứ hỗ trợ, rủi ro hoặc tranh chấp..." /></Field>
      </div>
    </Dialog>
  </>;
}

function Detail({ icon: Icon, label, value }) {
  return <div className="min-w-0"><p className="flex items-center gap-1.5 text-xs font-medium text-zinc-500"><Icon size={14} />{label}</p><p className="mt-1 break-words text-sm font-semibold text-zinc-900">{value}</p></div>;
}

function Note({ title, value }) {
  return <section><h3 className="text-sm font-bold text-zinc-950">{title}</h3><p className="mt-2 rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm leading-6 text-zinc-700">{value || '—'}</p></section>;
}
