import { useEffect, useId, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { bookingsApi } from '../../../api/apiClient';
import { actualTimeCorrectionPayload, emptyActualTimeCorrection, submitActualTimeCorrection } from '../../../utils/actualTimeCorrection';
import { Button, Dialog, Field, Input, Select, Textarea } from '../../ui';

export default function ActualTimeCorrection({ bookingId, item, timezone, serverNow, onRefresh }) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(emptyActualTimeCorrection);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const pending = useRef(false);
  const formId = useId();
  useEffect(() => { setForm((current) => ({ ...current, confirmed: false })); }, [item.revision]);
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value, confirmed: false }));
  const submit = async (event) => {
    event.preventDefault();
    if (pending.current) return;
    setError('');
    let payload;
    try { payload = actualTimeCorrectionPayload(form, { expectedRevision: item.revision, timezone, now: serverNow }); }
    catch (validationError) { setError(validationError.message); return; }
    pending.current = true; setBusy(true);
    try {
      await submitActualTimeCorrection({
        payload, send: (body) => bookingsApi.correctActualTime(bookingId, item.bookingServiceId, body), refresh: onRefresh,
      });
      toast.success('Đã lưu bổ sung / hiệu chỉnh thời gian thực tế');
      setOpen(false); setForm(emptyActualTimeCorrection());
    } catch (saveError) {
      setError(saveError.message || 'Không thể lưu thời gian thực tế.');
      setForm((current) => ({ ...current, confirmed: false }));
    } finally { pending.current = false; setBusy(false); }
  };
  return <>
    <Button size="sm" variant="secondary" className="mt-3 h-auto !whitespace-normal text-left" onClick={() => { setForm(emptyActualTimeCorrection()); setError(''); setOpen(true); }}>Bổ sung / hiệu chỉnh thời gian thực tế</Button>
    <Dialog open={open} onClose={() => !busy && setOpen(false)} title="Bổ sung / hiệu chỉnh thời gian thực tế" description={item.name} footer={<><Button variant="secondary" disabled={busy} onClick={() => setOpen(false)}>Quay lại</Button><Button type="submit" form={formId} loading={busy} disabled={!form.confirmed}>Lưu hiệu chỉnh</Button></>}>
      <form id={formId} onSubmit={submit} className="space-y-4">
        <p className="text-sm text-zinc-600">Chỉ ghi nhận dữ liệu thực tế đã xác minh. Các ô thời gian để trống; giờ dự kiến không được tự điền.</p>
        <Field label="Cách ghi nhận thời gian"><Select aria-label="Cách ghi nhận thời gian thực tế" value={form.timing} disabled={busy} onChange={(event) => { setForm((current) => ({ ...current, timing: event.target.value, actualStartedAt: '', actualCompletedAt: '', confirmed: false })); }}><option value="KNOWN">Đã xác định giờ thực tế</option><option value="UNKNOWN">Dịch vụ đã hoàn thành, chưa xác định giờ thực tế</option></Select></Field>
        {form.timing === 'KNOWN' ? <div className="grid gap-3 sm:grid-cols-2">
          <Field label={`Bắt đầu thực tế (${timezone || 'chưa có múi giờ'})`} required><Input aria-label="Bắt đầu thực tế" type="datetime-local" value={form.actualStartedAt} onChange={set('actualStartedAt')} disabled={busy} /></Field>
          <Field label={`Kết thúc thực tế (${timezone || 'chưa có múi giờ'})`} required><Input aria-label="Kết thúc thực tế" type="datetime-local" value={form.actualCompletedAt} onChange={set('actualCompletedAt')} disabled={busy} /></Field>
        </div> : <p role="status" className="text-sm text-zinc-600">Cả giờ bắt đầu và kết thúc thực tế sẽ được ghi nhận là chưa xác định. Không thay bằng giờ dự kiến.</p>}
        <Field label="Lý do bổ sung / hiệu chỉnh" required><Textarea aria-label="Lý do bổ sung / hiệu chỉnh" value={form.reason} onChange={set('reason')} disabled={busy} maxLength={2000} /></Field>
        <label className="flex items-start gap-2 text-sm text-zinc-700"><input type="checkbox" className="mt-1" checked={form.confirmed} disabled={busy} onChange={(event) => setForm((current) => ({ ...current, confirmed: event.target.checked }))} /><span>Tôi xác nhận dịch vụ đã hoàn thành và dữ liệu trên đã được kiểm tra. Thao tác ghi nhận hoàn thành cho dịch vụ này; giữ nguyên giờ dự kiến, trạng thái lịch hẹn cha và dữ liệu tài chính. Lý do và người hiệu chỉnh được lưu vào lịch sử.</span></label>
        {error && <p role="alert" className="text-sm font-medium text-red-700">{error}</p>}
      </form>
    </Dialog>
  </>;
}
