import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { branchChangesApi, branchesApi, mediaApi } from '../../api/apiClient';
import { FileUpload } from '../media/FileUpload';
import { Badge, Button, Card, EmptyState, Field, Input, Select, Skeleton, Textarea } from '../ui';

export const registrationTypes = [
  ['BUSINESS_LOCATION', 'Địa điểm kinh doanh'],
  ['ENTERPRISE_BRANCH', 'Chi nhánh doanh nghiệp'],
  ['EXISTING_REGISTERED_ADDRESS', 'Địa chỉ đã đăng ký'],
];
const labels = { PENDING: 'Chờ duyệt', APPROVED: 'Đã duyệt', REJECTED: 'Từ chối', WITHDRAWN: 'Đã rút' };

export default function BranchChangesPanel({ branch, admin = false, onChanged }) {
  const titleId = useId();
  const [result, setResult] = useState({ data: [], page: 1, totalPages: 1 });
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('PENDING');
  const [districts, setDistricts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const errorRef = useRef(null);
  const actionLock = useRef(false);
  const generation = useRef(0);
  const [notes, setNotes] = useState({});
  const [media, setMedia] = useState(null);
  const [form, setForm] = useState({ addressLine: branch?.addressLine || '', districtId: branch?.districtId || '', ward: branch?.ward || '', registrationType: branch?.registrationType || 'BUSINESS_LOCATION', reason: '' });
  const report = (failure) => { setError(failure.message || 'Không thể xử lý yêu cầu.'); requestAnimationFrame(() => errorRef.current?.focus()); };
  const load = useCallback(async () => {
    const current = ++generation.current;
    setLoading(true);
    setError('');
    try {
      const [rows, choices] = await Promise.all([
        admin ? branchChangesApi.queue({ status, page }) : branchChangesApi.list(branch.id, page),
        branchesApi.getDistricts(),
      ]);
      if (current === generation.current) { setResult(rows); setDistricts(Array.isArray(choices) ? choices : choices?.data || []); }
    } catch (failure) { if (current === generation.current) report(failure); }
    finally { if (current === generation.current) setLoading(false); }
  }, [admin, branch?.id, page, status]);
  useEffect(() => { void load(); return () => { generation.current += 1; }; }, [load]);
  const run = async (work) => {
    if (actionLock.current) return;
    actionLock.current = true; setBusy(true); setError('');
    try { await work(); await load(); await onChanged?.(); }
    catch (failure) { report(failure); }
    finally { actionLock.current = false; setBusy(false); }
  };
  const submit = (event) => {
    event.preventDefault();
    if (!media?.id) { report(new Error('Tải PDF minh chứng trước khi gửi.')); return; }
    void run(async () => { await branchChangesApi.create(branch.id, { ...form, mediaId: media.id }); setMedia(null); });
  };
  const download = (id) => run(async () => {
    const blob = await mediaApi.download(id);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a'); link.href = url; link.download = 'giay-dang-ky-chi-nhanh.pdf'; link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
  const change = (key) => (event) => setForm((value) => ({ ...value, [key]: event.target.value }));
  const pending = result.data.some((row) => row.status === 'PENDING');

  return <Card className="space-y-5 p-5" aria-labelledby={titleId}>
    <div><h2 id={titleId} className="text-lg font-bold">{admin ? 'Duyệt thay đổi thông tin chi nhánh' : 'Thay đổi địa chỉ và đăng ký'}</h2>
      <p className="mt-1 text-sm text-[var(--bb-muted)]">Địa chỉ đã duyệt tiếp tục hiển thị cho khách trong lúc chờ xử lý. Cần giải quyết lịch hẹn đang hiệu lực trước khi chuyển địa điểm.</p></div>
    {error && <div ref={errorRef} role="alert" tabIndex={-1} className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">{error}<div className="mt-2"><Button variant="secondary" disabled={busy} onClick={() => void load()}>Thử tải lại</Button></div></div>}
    {admin && <Field label="Trạng thái yêu cầu"><Select value={status} disabled={busy} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>{Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select></Field>}
    {loading ? <Skeleton rows={3} /> : result.data.length === 0 ? <EmptyState title="Chưa có yêu cầu" description="Các yêu cầu thay đổi sẽ xuất hiện tại đây." /> : <ul className="space-y-4">{result.data.map((row) => <li key={row.id} className="space-y-3 rounded-xl border border-[var(--bb-border)] p-4">
      <div className="flex flex-wrap justify-between gap-2"><h3 className="font-bold">{row.branchName}</h3><Badge tone={row.status === 'PENDING' ? 'warning' : row.status === 'APPROVED' ? 'success' : 'neutral'}>{labels[row.status]}</Badge></div>
      <dl className="grid gap-3 text-sm sm:grid-cols-2"><div><dt className="font-semibold">Địa chỉ hiện tại khi gửi</dt><dd className="mt-1 break-words">{row.baseSnapshot.addressLine || 'Chưa có'}</dd><dd>{row.baseSnapshot.ward}</dd></div><div><dt className="font-semibold">Địa chỉ đề xuất</dt><dd className="mt-1 break-words">{row.proposedSnapshot.addressLine}</dd><dd>{row.proposedSnapshot.ward}</dd></div></dl>
      <p className="text-sm">Loại đăng ký: {registrationTypes.find(([value]) => value === row.proposedSnapshot.registrationType)?.[1]}</p>
      {row.reason && <p className="whitespace-pre-wrap break-words text-sm">Lý do: {row.reason}</p>}
      {row.reviewNote && <p className="whitespace-pre-wrap break-words text-sm">Kết quả: {row.reviewNote}</p>}
      <Button variant="secondary" disabled={busy} onClick={() => void download(row.mediaId)}>Tải PDF minh chứng</Button>
      {row.status === 'PENDING' && (admin ? <div className="space-y-3"><Field label="Nội dung xét duyệt (bắt buộc khi từ chối)"><Textarea maxLength={2000} value={notes[row.id] || ''} disabled={busy} onChange={(event) => setNotes((value) => ({ ...value, [row.id]: event.target.value }))} /></Field><div className="flex flex-wrap gap-2"><Button loading={busy} onClick={() => void run(() => branchChangesApi.review(row.id, { decision: 'APPROVE', expectedVersion: row.version, note: notes[row.id] || null }))}>Phê duyệt thay đổi</Button><Button variant="danger" disabled={busy || !notes[row.id]?.trim()} onClick={() => void run(() => branchChangesApi.review(row.id, { decision: 'REJECT', expectedVersion: row.version, note: notes[row.id] }))}>Từ chối</Button></div></div> : <Button variant="secondary" disabled={busy} onClick={() => void run(() => branchChangesApi.withdraw(branch.id, row.id, row.version))}>Rút yêu cầu</Button>)}
    </li>)}</ul>}
    {result.totalPages > 1 && <div className="flex items-center gap-3"><Button variant="secondary" disabled={busy || page <= 1} onClick={() => setPage(page - 1)}>Trang trước</Button><span className="text-sm">{page}/{result.totalPages}</span><Button variant="secondary" disabled={busy || page >= result.totalPages} onClick={() => setPage(page + 1)}>Trang sau</Button></div>}
    {!admin && !loading && !pending && <form onSubmit={submit} className="grid gap-4 border-t border-[var(--bb-border)] pt-5 sm:grid-cols-2">
      <Field label="Địa chỉ đề xuất" required className="sm:col-span-2"><Input required minLength={3} maxLength={255} disabled={busy} value={form.addressLine} onChange={change('addressLine')} /></Field>
      <Field label="Quận / huyện" required><Select required disabled={busy} value={form.districtId} onChange={change('districtId')}><option value="">Chọn quận / huyện</option>{districts.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.province?.name}</option>)}</Select></Field>
      <Field label="Phường / xã"><Input maxLength={160} disabled={busy} value={form.ward} onChange={change('ward')} /></Field>
      <Field label="Loại đăng ký" required className="sm:col-span-2"><Select value={form.registrationType} onChange={change('registrationType')} disabled={busy}>{registrationTypes.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select></Field>
      <Field label="Lý do thay đổi" className="sm:col-span-2"><Textarea maxLength={2000} disabled={busy} value={form.reason} onChange={change('reason')} /></Field>
      <div className="sm:col-span-2"><FileUpload document entityType="BRANCH_DOCUMENT" entityId={branch.id} businessId={branch.businessId || branch.business?.id} branchId={branch.id} value={media} onUploaded={setMedia} onRemoved={() => setMedia(null)} onBusyChange={setUploading} disabled={busy} label="Tải PDF minh chứng" /></div>
      <div className="sm:col-span-2"><Button type="submit" loading={busy} disabled={uploading}>Gửi yêu cầu xét duyệt</Button></div>
    </form>}
  </Card>;
}
