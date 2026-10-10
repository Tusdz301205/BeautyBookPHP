import { useEffect, useMemo, useState } from 'react';
import { Building2, CheckCircle2, Clock3, Eye, FileSearch, Search, Store, XCircle } from 'lucide-react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { adminApi, branchesApi, businessApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Badge, Button, Card, Dialog, Drawer, EmptyState, ErrorState, Field, Input, MetricCard, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';
import { documentLabel, statusLabel, statusTone } from '../../utils/displayLabels';

const REVIEWABLE = new Set(['PENDING_REVIEW', 'SUBMITTED']);
const APPROVED_BUSINESS = new Set(['APPROVED', 'ACTIVE']);
const FILTER_STATUSES = ['', 'PENDING_REVIEW', 'SUBMITTED', 'NEED_MORE_INFO', 'REJECTED', 'APPROVED', 'ACTIVE'];
const date = (value) => value ? new Date(value).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' }) : '—';

function toItems(queue) {
  return [
    ...(queue.pendingBusinesses || []).map((record) => ({ kind: 'business', record, id: record.id, name: record.name, status: record.status, typeLabel: 'Doanh nghiệp' })),
    ...(queue.pendingBranches || []).map((record) => ({ kind: 'branch', record, id: record.id, name: record.publicName || record.name, status: record.reviewStatus || record.status, typeLabel: 'Chi nhánh' })),
  ];
}

function RecordMeta({ item }) {
  if (item.kind === 'business') {
    const owner = item.record.owner?.user;
    return <><p className="font-semibold">{owner?.fullName || item.record.legalRepresentative || 'Chưa cập nhật người đại diện'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{owner?.email || item.record.contactEmail || 'Chưa cập nhật email'} · {item.record.branches?.length || 0} chi nhánh · {item.record.documents?.length || 0} giấy tờ</p></>;
  }
  return <><p className="font-semibold">{item.record.business?.name || 'Chưa xác định doanh nghiệp'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{item.record.addressLine || 'Chưa cập nhật địa chỉ'} · {item.record.documents?.length || 0} giấy tờ</p></>;
}

function QueueCards({ items, onOpen }) {
  return <div className="divide-y divide-[var(--bb-border)] lg:hidden">{items.map((item) => <article key={`${item.kind}-${item.id}`} className="space-y-4 p-4"><div className="flex items-start justify-between gap-3"><div><Badge>{item.typeLabel}</Badge><h2 className="mt-2 font-bold">{item.name}</h2></div><Badge tone={statusTone(item.status)}>{statusLabel(item.status)}</Badge></div><RecordMeta item={item} />{item.kind === 'branch' && !APPROVED_BUSINESS.has(item.record.business?.status) && <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Doanh nghiệp chủ quản chưa được duyệt.</p>}<Button size="sm" variant="secondary" onClick={() => onOpen(item)}><Eye size={15} />Xem hồ sơ</Button></article>)}</div>;
}

function QueueTable({ items, onOpen }) {
  return <div className="hidden overflow-x-auto lg:block"><table className="w-full min-w-[920px] text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase tracking-wide text-[var(--bb-muted)]"><tr><th className="px-5 py-3">Hồ sơ</th><th className="px-4 py-3">Loại</th><th className="px-4 py-3">Thông tin liên hệ / doanh nghiệp</th><th className="px-4 py-3">Giấy tờ</th><th className="px-4 py-3">Trạng thái</th><th className="px-5 py-3 text-right">Thao tác</th></tr></thead><tbody className="divide-y divide-[var(--bb-border)]">{items.map((item) => <tr key={`${item.kind}-${item.id}`} className="align-top hover:bg-zinc-50/70"><td className="px-5 py-4"><p className="font-bold">{item.name}</p>{item.record.reviewNote && <p className="mt-1 max-w-xs text-xs text-[var(--bb-muted)]">Ghi chú gần nhất: {item.record.reviewNote}</p>}</td><td className="px-4 py-4"><Badge>{item.typeLabel}</Badge></td><td className="px-4 py-4"><RecordMeta item={item} />{item.kind === 'branch' && !APPROVED_BUSINESS.has(item.record.business?.status) && <p className="mt-2 text-xs font-semibold text-amber-700">Doanh nghiệp mẹ chưa đủ điều kiện</p>}</td><td className="px-4 py-4 font-bold tabular-nums">{item.record.documents?.length || 0}</td><td className="px-4 py-4"><Badge tone={statusTone(item.status)}>{statusLabel(item.status)}</Badge></td><td className="px-5 py-4 text-right"><Button size="sm" variant="secondary" onClick={() => onOpen(item)}><Eye size={14} />Xem hồ sơ</Button></td></tr>)}</tbody></table></div>;
}

function ReviewDrawer({ item, canReviewBusiness, canReviewBranch, onClose, onDecision }) {
  if (!item) return null;
  const { record } = item;
  const canReview = item.kind === 'business' ? canReviewBusiness : canReviewBranch;
  const canAct = canReview && REVIEWABLE.has(item.status);
  const parentEligible = item.kind === 'business' || APPROVED_BUSINESS.has(record.business?.status);
  const detailPath = item.kind === 'business' ? `/admin/businesses/${item.id}` : `/admin/branches/${item.id}`;
  const documents = record.documents || [];
  const events = record.reviewEvents || [];
  const owner = record.owner?.user;

  return <Drawer open onClose={onClose} size="lg" title={item.name} description={`${item.typeLabel} · ${statusLabel(item.status)}`} footer={<div className="flex w-full flex-wrap justify-end gap-2"><Link to={detailPath}><Button variant="secondary"><Eye size={15} />Mở trang chi tiết</Button></Link>{canAct && <><Button variant="secondary" onClick={() => onDecision(item, 'REQUEST_INFO')}>Yêu cầu bổ sung</Button><Button variant="danger" onClick={() => onDecision(item, item.kind === 'business' ? 'REJECT' : 'REJECTED')}><XCircle size={15} />Từ chối</Button><Button disabled={!parentEligible} onClick={() => onDecision(item, item.kind === 'business' ? 'APPROVE' : 'ACTIVE')}><CheckCircle2 size={15} />Phê duyệt</Button></>}</div>}>
    <div className="space-y-6">
      {item.kind === 'business' && <section>
        <h3 className="font-bold">Phân loại dịch vụ và pháp nhân</h3>
        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
          <Info label="Loại hình dịch vụ" value={record.businessTypeLabel || 'Chưa chọn loại hình dịch vụ'} />
          <Info label="Tên pháp nhân" value={record.owner?.companyName || 'Chưa cập nhật'} />
          <Info label="Mã số thuế" value={record.owner?.taxCode || 'Chưa cập nhật'} />
          <Info label="Người đại diện pháp luật" value={record.legalRepresentative || 'Chưa cập nhật'} />
          <Info label="Email liên hệ doanh nghiệp" value={record.contactEmail || 'Chưa cập nhật'} />
          <Info label="Điện thoại liên hệ doanh nghiệp" value={record.contactPhone || 'Chưa cập nhật'} />
          <Info label="Địa chỉ đăng ký" value={record.addressLine || 'Chưa cập nhật'} />
        </dl>
      </section>}
      {!parentEligible && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Chưa thể duyệt chi nhánh.</strong><p className="mt-1">Doanh nghiệp {record.business?.name} phải được duyệt trước.</p></div>}
      <section><h3 className="font-bold">Thông tin hồ sơ</h3><dl className="mt-3 grid gap-3 sm:grid-cols-2">{item.kind === 'business' ? <><Info label="Chủ sở hữu" value={owner?.fullName || record.legalRepresentative || 'Chưa cập nhật'} /><Info label="Email" value={owner?.email || record.contactEmail || 'Chưa cập nhật'} /><Info label="Số điện thoại" value={owner?.phone || record.contactPhone || 'Chưa cập nhật'} /><Info label="Số chi nhánh" value={record.branches?.length || 0} /></> : <><Info label="Doanh nghiệp chủ quản" value={record.business?.name || 'Chưa cập nhật'} /><Info label="Trạng thái doanh nghiệp" value={statusLabel(record.business?.status)} /><Info label="Địa chỉ" value={record.addressLine || 'Chưa cập nhật'} /><Info label="Ngày gửi" value={date(record.submittedAt)} /></>}</dl></section>
      <section><h3 className="font-bold">Giấy tờ đã nộp</h3>{documents.length ? <div className="mt-3 divide-y divide-[var(--bb-border)] rounded-xl border border-[var(--bb-border)]">{documents.map((document) => <div key={document.id} className="flex flex-wrap items-center justify-between gap-2 p-4"><p className="font-semibold">{documentLabel(document.documentType)}</p><Badge tone={statusTone(document.status)}>{statusLabel(document.status)}</Badge></div>)}</div> : <p className="mt-2 text-sm text-[var(--bb-muted)]">Chưa có giấy tờ được ghi nhận.</p>}</section>
      <section><h3 className="font-bold">Lịch sử xét duyệt</h3>{events.length ? <ol className="mt-3 space-y-3">{events.map((event) => <li key={event.id} className="border-l-2 border-pink-200 pl-4"><p className="text-sm font-semibold">{statusLabel(event.toStatus, 'Đã cập nhật hồ sơ')}</p><p className="mt-0.5 text-xs text-[var(--bb-muted)]">{date(event.createdAt)}{event.actor?.fullName ? ` · ${event.actor.fullName}` : ''}</p>{event.reason && <p className="mt-1 text-sm">{event.reason}</p>}</li>)}</ol> : <p className="mt-2 text-sm text-[var(--bb-muted)]">Chưa có lần xét duyệt nào.</p>}</section>
    </div>
  </Drawer>;
}

function Info({ label, value }) {
  return <div className="rounded-xl bg-[var(--bb-surface-subtle)] p-4"><dt className="text-xs font-bold uppercase tracking-wide text-[var(--bb-muted)]">{label}</dt><dd className="mt-1 break-words font-semibold">{value}</dd></div>;
}

export default function AdminCompliance() {
  const can = useAuthStore((state) => state.can);
  const [queue, setQueue] = useState({ pendingBusinesses: [], pendingBranches: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [typeFilter, setTypeFilter] = useState('ALL');
  const [statusFilter, setStatusFilter] = useState('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState(null);
  const [decision, setDecision] = useState(null);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const canReviewBusiness = can('business:review:platform');
  const canReviewBranch = can('branch:status:platform');

  const load = async () => {
    setLoading(true); setError('');
    try {
      const result = await adminApi.getComplianceQueue();
      setQueue({ pendingBusinesses: result?.pendingBusinesses || [], pendingBranches: result?.pendingBranches || [] });
    } catch (loadError) { setError(loadError.message || 'Không thể tải danh sách hồ sơ xét duyệt.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const items = useMemo(() => {
    const keyword = search.trim().toLocaleLowerCase('vi');
    return toItems(queue).filter((item) => {
      if (typeFilter !== 'ALL' && item.kind !== typeFilter) return false;
      if (statusFilter && item.status !== statusFilter) return false;
      if (!keyword) return true;
      const values = [item.name, item.record.business?.name, item.record.owner?.user?.fullName, item.record.owner?.user?.email, item.record.contactEmail, item.record.addressLine];
      return values.filter(Boolean).some((value) => String(value).toLocaleLowerCase('vi').includes(keyword));
    });
  }, [queue, search, statusFilter, typeFilter]);

  const pendingCount = toItems(queue).filter((item) => REVIEWABLE.has(item.status)).length;
  const openDecision = (item, value) => { setSelected(null); setNote(''); setDecision({ item, value }); };
  const submitDecision = async () => {
    if (!decision) return;
    const needsReason = !['APPROVE', 'ACTIVE'].includes(decision.value);
    if (needsReason && !note.trim()) return toast.error('Vui lòng nhập lý do hoặc nội dung cần bổ sung.');
    setBusy(true);
    try {
      if (decision.item.kind === 'business') await businessApi.review(decision.item.id, decision.value, note.trim() || undefined);
      else await branchesApi.updateStatus(decision.item.id, decision.value, note.trim() || undefined);
      toast.success('Đã cập nhật kết quả xét duyệt');
      setDecision(null); setNote(''); await load();
    } catch (actionError) { toast.error(actionError.message || 'Không thể cập nhật hồ sơ.'); }
    finally { setBusy(false); }
  };

  return <Page>
    <PageHeader eyebrow="Quản trị đối tác" title="Xét duyệt hồ sơ" description="Danh sách hồ sơ doanh nghiệp và chi nhánh cần xét duyệt, với lý do rõ ràng cho mọi yêu cầu bổ sung hoặc từ chối." />
    <div className="grid gap-3 sm:grid-cols-3"><MetricCard icon={Clock3} label="Chờ xử lý" value={pendingCount} tone="warning" /><MetricCard icon={Building2} label="Hồ sơ doanh nghiệp" value={queue.pendingBusinesses.length} /><MetricCard icon={Store} label="Hồ sơ chi nhánh" value={queue.pendingBranches.length} tone="info" /></div>
    <Card className="p-4"><div className="grid gap-3 lg:grid-cols-[minmax(240px,1fr)_210px_240px]"><Field label="Tìm hồ sơ"><div className="relative"><Search size={16} className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" /><Input className="pl-9" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tên, email, địa chỉ..." /></div></Field><Field label="Loại hồ sơ"><Select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}><option value="ALL">Tất cả hồ sơ</option><option value="business">Doanh nghiệp</option><option value="branch">Chi nhánh</option></Select></Field><Field label="Trạng thái"><Select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>{FILTER_STATUSES.map((value) => <option key={value || 'all'} value={value}>{value ? statusLabel(value) : 'Tất cả trạng thái'}</option>)}</Select></Field></div></Card>
    <Card className="overflow-hidden">{loading ? <div className="p-5"><Skeleton rows={8} /></div> : error ? <ErrorState message={error} onRetry={load} /> : !items.length ? <EmptyState icon={FileSearch} title="Không có hồ sơ phù hợp" description="Thử thay đổi từ khóa hoặc bộ lọc." /> : <><QueueCards items={items} onOpen={setSelected} /><QueueTable items={items} onOpen={setSelected} /></>}</Card>
    <ReviewDrawer item={selected} canReviewBusiness={canReviewBusiness} canReviewBranch={canReviewBranch} onClose={() => setSelected(null)} onDecision={openDecision} />
    <Dialog open={Boolean(decision)} onClose={() => setDecision(null)} title={['APPROVE', 'ACTIVE'].includes(decision?.value) ? 'Phê duyệt hồ sơ?' : decision?.value === 'REQUEST_INFO' ? 'Yêu cầu bổ sung thông tin' : 'Từ chối hồ sơ?'} description={decision?.item?.name} footer={<><Button variant="secondary" onClick={() => setDecision(null)}>Hủy</Button><Button variant={['REJECT', 'REJECTED'].includes(decision?.value) ? 'danger' : 'primary'} loading={busy} onClick={submitDecision}>Xác nhận</Button></>}>
      {!['APPROVE', 'ACTIVE'].includes(decision?.value) ? <Field label="Lý do / nội dung cần bổ sung" required><Textarea autoFocus value={note} onChange={(event) => setNote(event.target.value)} placeholder="Ghi rõ thông tin để doanh nghiệp có thể xử lý..." /></Field> : <p className="text-sm leading-6 text-[var(--bb-muted)]">Hồ sơ sẽ được chuyển sang trạng thái đã duyệt sau khi hệ thống kiểm tra đầy đủ điều kiện.</p>}
    </Dialog>
  </Page>;
}
