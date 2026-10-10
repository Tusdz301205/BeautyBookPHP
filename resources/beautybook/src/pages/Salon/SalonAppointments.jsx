import { useCallback, useEffect, useRef, useState } from 'react';
import { format } from 'date-fns';
import { branchWallTimeToInstant, formatBranchSlotTime } from '../../utils/branchSlotTime';
import { CalendarClock, Clock3, Plus, UserRound } from 'lucide-react';
import toast from 'react-hot-toast';
import { bookingsApi, branchesApi, servicesApi, staffApi } from '../../api/apiClient';
import { Badge, Button, Dialog, Field, InlineNotice, Input, Select, Skeleton, Textarea, cx } from '../../components/ui';
import { AppointmentCalendarWorkspace } from '../Admin/AdminAppointmentsView';
import { useAuthStore } from '../../store/authStore';

const initialForm = { branchId: '', serviceId: '', staffId: '', guestName: '', guestPhone: '', date: format(new Date(), 'yyyy-MM-dd'), appointmentDate: '', manualStart: '', controlledOverbooking: false, overbookingReason: '', note: '' };

function WalkInDialog({ open, onClose, onCreated }) {
  const hasRoleAt = useAuthStore((state) => state.hasRoleAt);
  const slotSequence = useRef(0);
  const [form, setForm] = useState(initialForm);
  const [branches, setBranches] = useState([]);
  const [services, setServices] = useState([]);
  const [staff, setStaff] = useState([]);
  const [slots, setSlots] = useState([]);
  const [slotLoading, setSlotLoading] = useState(false);
  const [slotError, setSlotError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    branchesApi.getAccessible().then((data) => {
      const rows = Array.isArray(data) ? data : data?.data || [];
      setBranches(rows);
      setForm((current) => ({ ...current, branchId: current.branchId || rows[0]?.id || '' }));
    }).catch((error) => toast.error(error.message));
  }, [open]);

  useEffect(() => {
    if (!open || !form.branchId) { setServices([]); return; }
    let active = true;
    servicesApi.getAll(form.branchId).then((data) => {
      if (!active) return;
      const rows = Array.isArray(data) ? data : data?.data || [];
      setServices(rows);
      slotSequence.current += 1;
      setSlots([]);
      setForm((current) => ({ ...current, serviceId: rows.some((item) => item.id === current.serviceId) ? current.serviceId : rows[0]?.id || '', staffId: '', appointmentDate: '' }));
    }).catch((error) => { if (active) toast.error(error.message); });
    return () => { active = false; };
  }, [open, form.branchId]);

  useEffect(() => {
    if (!open || !form.branchId || !form.serviceId) { setStaff([]); return; }
    let active = true;
    staffApi.getPublic(form.branchId, [form.serviceId]).then((data) => { if (active) setStaff(Array.isArray(data) ? data : []); }).catch(() => { if (active) setStaff([]); });
    return () => { active = false; };
  }, [open, form.branchId, form.serviceId]);

  const loadSlots = useCallback(async () => {
    const sequence = ++slotSequence.current;
    setForm((current) => ({ ...current, appointmentDate: '' }));
    if (!open || !form.branchId || !form.serviceId || !form.date) { setSlots([]); setSlotLoading(false); return; }
    setSlotLoading(true); setSlotError('');
    try {
      const result = await bookingsApi.counterSlots({ branchId: form.branchId, staffId: form.staffId, serviceIds: [form.serviceId], date: form.date, source: 'WALK_IN' });
      if (sequence !== slotSequence.current) return;
      setSlots(result.slots || []);
      setForm((current) => ({ ...current, appointmentDate: (result.slots || []).some((slot) => slot.start === current.appointmentDate) ? current.appointmentDate : '' }));
    } catch (error) { if (sequence === slotSequence.current) { setSlots([]); setSlotError(error.message || 'Không thể kiểm tra khung giờ'); } }
    finally { if (sequence === slotSequence.current) setSlotLoading(false); }
  }, [open, form.branchId, form.serviceId, form.staffId, form.date, form.controlledOverbooking]);
  useEffect(() => { void loadSlots(); return () => { slotSequence.current += 1; }; }, [loadSlots]);

  const set = (key) => (event) => {
    const affectsSlots = ['branchId', 'serviceId', 'staffId', 'date'].includes(key);
    if (affectsSlots) { slotSequence.current += 1; setSlots([]); setSlotLoading(true); }
    setForm((current) => ({ ...current, [key]: event.target.value, ...(affectsSlots ? { appointmentDate: '' } : {}), ...(key === 'branchId' ? { serviceId: '', staffId: '' } : key === 'serviceId' ? { staffId: '' } : {}) }));
  };
  const selectedBranch = branches.find((branch) => branch.id === form.branchId);
  const timezone = selectedBranch?.timezone || 'Asia/Ho_Chi_Minh';
  const slotTime = (value) => formatBranchSlotTime(value, timezone);
  const canOverbook = !!selectedBranch?.businessId && hasRoleAt('BUSINESS_OWNER', selectedBranch.businessId);
  const walkInAllowed = selectedBranch?.bookingPolicy?.allowWalkIn !== false;
  const submit = async (event) => {
    event.preventDefault();
    if (!walkInAllowed) {
      toast.error('Chi nhánh hiện không nhận khách vãng lai'); return;
    }
    if (form.controlledOverbooking && !canOverbook) {
      toast.error('Chỉ chủ doanh nghiệp được duyệt overbooking trong doanh nghiệp của mình.'); return;
    }
    const selectedStart = form.controlledOverbooking ? form.manualStart : form.appointmentDate;
    if (!form.branchId || !form.serviceId || !form.guestName.trim() || !selectedStart) {
      toast.error('Nhập đủ chi nhánh, dịch vụ, tên khách và thời gian'); return;
    }
    if (form.controlledOverbooking && form.overbookingReason.trim().length < 10) {
      toast.error('Lý do overbooking phải có ít nhất 10 ký tự'); return;
    }
    const instant = form.controlledOverbooking ? branchWallTimeToInstant(selectedStart, timezone) : new Date(selectedStart);
    if (!instant || Number.isNaN(instant.getTime())) {
      toast.error('Thời gian không hợp lệ'); return;
    }
    if (!form.controlledOverbooking && (slotLoading || !slots.some((slot) => slot.start === form.appointmentDate))) { toast.error('Chọn lại khung giờ còn trống'); return; }
    setSaving(true);
    try {
      await bookingsApi.create({
        branchId: form.branchId, serviceIds: [form.serviceId],
        staffId: form.staffId || undefined,
        guestName: form.guestName.trim(), guestPhone: form.guestPhone.trim() || undefined,
        appointmentDate: instant.toISOString(), note: form.note.trim() || undefined,
        source: 'WALK_IN',
        controlledOverbooking: form.controlledOverbooking,
        overbookingReason: form.controlledOverbooking ? form.overbookingReason.trim() : undefined,
      });
      toast.success('Đã tạo lịch tại quầy và giữ khung giờ');
      setForm(initialForm); onCreated();
    } catch (error) { toast.error(error.message || 'Không thể tạo lịch tại quầy'); }
    finally { setSaving(false); }
  };

  return <Dialog open={open} onClose={onClose} title="Tạo lịch tại quầy" description="Khách vãng lai không cần tài khoản. Giờ mở cửa, dịch vụ, chuyên môn và lịch trùng sẽ được kiểm tra khi tạo." footer={<><Button variant="secondary" onClick={onClose}>Hủy</Button><Button type="submit" form="walk-in-form" loading={saving} disabled={!walkInAllowed}>Tạo lịch</Button></>}>
    <form id="walk-in-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
      <Field label="Chi nhánh" required><Select value={form.branchId} onChange={set('branchId')}><option value="">Chọn chi nhánh</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>
      {!walkInAllowed && <div className="sm:col-span-2"><InlineNotice tone="warning">Chi nhánh này đang tắt nhận khách vãng lai. Hãy chọn chi nhánh khác hoặc bật lại chính sách.</InlineNotice></div>}
      <Field label="Dịch vụ" required><Select value={form.serviceId} onChange={set('serviceId')}><option value="">Chọn dịch vụ</option>{services.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>
      <Field label="Nhân viên"><Select value={form.staffId} onChange={set('staffId')}><option value="">Bất kỳ nhân viên phù hợp</option>{staff.map((item) => <option key={item.id} value={item.id}>{item.fullName}</option>)}</Select></Field>
      <Field label="Ngày phục vụ" required><Input type="date" value={form.date} onChange={set('date')} /></Field>
      <Field label="Tên khách" required><Input value={form.guestName} onChange={set('guestName')} placeholder="Nguyễn Văn A" /></Field>
      <Field label="Số điện thoại"><Input type="tel" value={form.guestPhone} onChange={set('guestPhone')} /></Field>
      <div className="sm:col-span-2"><div className="mb-2 flex flex-wrap items-center justify-between gap-2"><div><p className="text-sm font-bold">Khung giờ còn trống</p><p className="mt-1 text-xs text-[var(--bb-muted)]">Kết quả đã kiểm tra giờ mở cửa, kỹ năng và lịch hẹn hiện có. Múi giờ: {timezone}.</p></div>{slots[0] && <Badge tone="success"><CalendarClock size={13} className="mr-1" />Gần nhất {slotTime(slots[0].start)}</Badge>}</div>{slotLoading ? <Skeleton rows={2} /> : slotError ? <InlineNotice tone="danger">{slotError} <button type="button" className="ml-1 font-bold underline" onClick={loadSlots}>Thử lại</button></InlineNotice> : !slots.length ? <InlineNotice tone="warning">Không còn khung giờ phù hợp trong ngày đã chọn. Hãy đổi ngày hoặc nhân viên.</InlineNotice> : <div className="grid max-h-48 grid-cols-3 gap-2 overflow-y-auto pr-1 sm:grid-cols-5">{slots.map((slot) => <button key={slot.start} type="button" aria-pressed={form.appointmentDate === slot.start} onClick={() => setForm((current) => ({ ...current, appointmentDate: slot.start }))} className={cx('min-h-11 rounded-lg border px-2 text-sm font-semibold', form.appointmentDate === slot.start ? 'border-[var(--bb-brand)] bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]' : 'border-[var(--bb-border)] hover:bg-[var(--bb-surface-subtle)]')}><Clock3 size={14} className="mr-1 inline" />{slotTime(slot.start)}</button>)}</div>}</div>
      {canOverbook && <div className="sm:col-span-2 rounded-xl border border-amber-300 bg-amber-50 p-4"><label className="flex items-center gap-2 text-sm font-bold"><input type="checkbox" checked={form.controlledOverbooking} onChange={(event) => { slotSequence.current += 1; setSlots([]); setForm((current) => ({ ...current, appointmentDate: '', controlledOverbooking: event.target.checked, staffId: event.target.checked ? '' : current.staffId })); }} /> Overbooking có kiểm soát</label><p className="mt-1 text-xs text-amber-800">Chỉ tạo lịch chưa phân công; không bỏ qua xung đột cứng của nhân viên. Lịch điều phối sẽ hiển thị cảnh báo.</p>{form.controlledOverbooking && <div className="mt-3 grid gap-3 sm:grid-cols-2"><Field label={`Thời gian ngoại lệ (${timezone})`} required><Input type="datetime-local" value={form.manualStart} onChange={set('manualStart')} /></Field><Field label="Lý do phê duyệt" required><Input value={form.overbookingReason} onChange={set('overbookingReason')} placeholder="Nêu rõ lý do và phương án phân công" /></Field></div>}</div>}
      {form.serviceId && <div className="sm:col-span-2 rounded-xl bg-[var(--bb-surface-subtle)] p-4"><div className="flex flex-wrap gap-x-6 gap-y-2 text-sm"><span><b>Dịch vụ:</b> {services.find((item) => item.id === form.serviceId)?.name || '—'}</span><span><b>Thời lượng:</b> {services.find((item) => item.id === form.serviceId)?.durationMinutes ? `${services.find((item) => item.id === form.serviceId).durationMinutes} phút` : 'Đang cập nhật'}</span><span><b>Giá:</b> {services.find((item) => item.id === form.serviceId)?.price != null ? `${Number(services.find((item) => item.id === form.serviceId).price).toLocaleString('vi-VN')}₫` : 'Đang cập nhật'}</span><span className="flex items-center gap-1"><UserRound size={15} /><b>Nhân viên:</b> {staff.find((item) => item.id === form.staffId)?.fullName || 'Hệ thống tự xếp'}</span></div></div>}
      <Field className="sm:col-span-2" label="Ghi chú vận hành" hint="Chỉ nhập thông tin cần thiết cho việc phục vụ và điều phối lịch."><Textarea value={form.note} onChange={set('note')} placeholder="Ví dụ: khách cần hỗ trợ di chuyển" /></Field>
    </form>
  </Dialog>;
}

export function SalonAppointments() {
  const can = useAuthStore((state) => state.can);
  const [walkInOpen, setWalkInOpen] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const canCreateWalkIn = can('booking:create:branch') || can('booking:create:tenant') || can('booking:create:platform');
  return <>
    <AppointmentCalendarWorkspace zone="salon" externalRefreshKey={refreshKey} headerAction={canCreateWalkIn ? <Button onClick={() => setWalkInOpen(true)}><Plus size={16} />Tạo lịch tại quầy</Button> : null} />
    {canCreateWalkIn && <WalkInDialog open={walkInOpen} onClose={() => setWalkInOpen(false)} onCreated={() => { setWalkInOpen(false); setRefreshKey((value) => value + 1); }} />}
  </>;
}

export default SalonAppointments;
