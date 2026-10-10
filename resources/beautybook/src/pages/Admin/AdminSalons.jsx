import { useCallback, useDeferredValue, useEffect, useState } from 'react';
import { Building2, ChevronLeft, ChevronRight, Eye, MapPin, Search, Star, Store } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { adminApi } from '../../api/apiClient';
import { HomeMedia } from '../../components/public/HomeMedia';
import { Badge, Card, EmptyState, ErrorState, Field, Input, PageHeader, Select, Skeleton } from '../../components/ui';
import { getBranchFallbackMedia, getBusinessFallbackMedia } from '../../config/homeMedia';
import { useAuthStore } from '../../store/authStore';
import { statusLabel, statusTone } from '../../utils/displayLabels';
import AdminCompliance from './AdminCompliance';
import AdminServiceTaxonomy from './AdminServiceTaxonomy';

const WORKSPACE_ITEMS = [
  ['directory', 'Doanh nghiệp & chi nhánh', 'branch:read:platform'],
  ['review', 'Xét duyệt hồ sơ', 'business:review:platform'],
  ['taxonomy', 'Danh mục dịch vụ chuẩn', 'canonical_service:manage:platform'],
];

const BUSINESS_FILTERS = [
  ['', 'Tất cả trạng thái'],
  ['ACTIVE', 'Đang hoạt động'],
  ['APPROVED', 'Đã duyệt'],
  ['PENDING_REVIEW', 'Chờ xét duyệt'],
  ['NEED_MORE_INFO', 'Cần bổ sung thông tin'],
  ['SUSPENDED', 'Đang bị đình chỉ'],
  ['REJECTED', 'Bị từ chối'],
];

const BRANCH_FILTERS = [
  ['', 'Tất cả trạng thái'],
  ['ACTIVE', 'Đang hoạt động'],
  ['PENDING_REVIEW', 'Chờ xét duyệt'],
  ['NEED_MORE_INFO', 'Cần bổ sung thông tin'],
  ['APPROVED', 'Đã duyệt'],
  ['INACTIVE', 'Tạm ngưng'],
  ['REJECTED', 'Bị từ chối'],
];

function WorkspaceNav({ view, onChange, can }) {
  const items = WORKSPACE_ITEMS.filter(([, , permission]) => can(permission));
  return <nav className="mb-6 grid gap-1 rounded-xl border border-[var(--bb-border)] bg-white p-1 sm:flex sm:overflow-x-auto" aria-label="Quản trị đối tác">
    {items.map(([id, label]) => <button key={id} type="button" onClick={() => onChange(id)} className={`min-h-10 min-w-0 rounded-lg px-4 py-2 text-left text-sm font-bold leading-snug transition-colors ${view === id ? 'bg-zinc-950 text-white' : 'text-zinc-600 hover:bg-zinc-100'}`}>{label}</button>)}
  </nav>;
}

function StatusBadge({ value }) {
  return <Badge tone={statusTone(value)} className="shrink-0 whitespace-nowrap">{statusLabel(value)}</Badge>;
}

function Pagination({ pagination, onChange }) {
  if (!pagination || pagination.totalPages <= 1) return null;
  return <div className="flex flex-col gap-3 border-t border-[var(--bb-border)] px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
    <p className="text-sm text-[var(--bb-muted)]">Trang {pagination.page}/{pagination.totalPages} · {Number(pagination.total || 0).toLocaleString('vi-VN')} kết quả</p>
    <div className="flex gap-2">
      <button type="button" aria-label="Trang trước" disabled={pagination.page <= 1} onClick={() => onChange(pagination.page - 1)} className="grid min-h-10 min-w-10 place-items-center rounded-lg border border-[var(--bb-border)] disabled:cursor-not-allowed disabled:opacity-40"><ChevronLeft size={17} /></button>
      <button type="button" aria-label="Trang sau" disabled={pagination.page >= pagination.totalPages} onClick={() => onChange(pagination.page + 1)} className="grid min-h-10 min-w-10 place-items-center rounded-lg border border-[var(--bb-border)] disabled:cursor-not-allowed disabled:opacity-40"><ChevronRight size={17} /></button>
    </div>
  </div>;
}

function DirectoryMedia({ entity, kind }) {
  const uploaded = kind === 'business'
    ? entity.coverImage
    : entity.coverImage || entity.images?.[0]?.media?.url || entity.images?.[0]?.url;
  const illustration = kind === 'business' ? getBusinessFallbackMedia(entity) : getBranchFallbackMedia(entity);
  if (!uploaded && !illustration?.src) return null;
  const name = kind === 'business' ? entity.name : entity.publicName || entity.name;
  return <HomeMedia
    src={uploaded || illustration}
    alt={uploaded ? `Ảnh do ${kind === 'business' ? 'doanh nghiệp' : 'chi nhánh'} ${name} cung cấp` : illustration.alt}
    ratio="16 / 9"
    className="w-full"
  />;
}

function BusinessCards({ rows }) {
  return <div className="divide-y divide-[var(--bb-border)] md:hidden">{rows.map((business) => <article key={business.id} className="space-y-4 p-4">
    <DirectoryMedia entity={business} kind="business" />
    <div className="flex items-start justify-between gap-3"><div className="min-w-0 flex-1 break-words"><h2 className="font-bold text-[var(--bb-ink)]">{business.name}</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">{business.owner?.fullName || 'Chưa cập nhật chủ sở hữu'}</p><p className="text-xs text-[var(--bb-muted)]">{business.owner?.email || business.contactEmail || 'Chưa cập nhật email'}</p></div><StatusBadge value={business.status} /></div>
    <dl className="grid grid-cols-2 gap-3 text-sm"><div><dt className="text-[var(--bb-muted)]">Chi nhánh</dt><dd className="font-bold">{business.activeBranchCount}/{business.branchCount} đang hoạt động</dd></div><div><dt className="text-[var(--bb-muted)]">Dịch vụ</dt><dd className="font-bold">{business.serviceCount}</dd></div><div><dt className="text-[var(--bb-muted)]">Lịch hẹn</dt><dd className="font-bold">{business.bookingCount.toLocaleString('vi-VN')}</dd></div></dl>
    <Link to={`/admin/businesses/${business.id}`} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-[var(--bb-border)] px-3 text-sm font-bold"><Eye size={15} />Xem chi tiết</Link>
  </article>)}</div>;
}

function BusinessTable({ rows }) {
  return <div className="hidden overflow-x-auto md:block"><table className="w-full min-w-[980px] text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase tracking-wide text-[var(--bb-muted)]"><tr><th className="px-5 py-3">Doanh nghiệp</th><th className="px-4 py-3">Chủ sở hữu</th><th className="px-4 py-3">Chi nhánh</th><th className="px-4 py-3">Dịch vụ</th><th className="px-4 py-3">Lịch hẹn</th><th className="px-4 py-3">Trạng thái</th><th className="px-5 py-3 text-right">Thao tác</th></tr></thead><tbody className="divide-y divide-[var(--bb-border)]">{rows.map((business) => <tr key={business.id} className="hover:bg-zinc-50/70"><td className="px-5 py-4"><div className="flex min-w-[250px] items-center gap-3"><div><p className="font-bold">{business.name}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">Tham gia {new Date(business.createdAt).toLocaleDateString('vi-VN')}</p></div></div></td><td className="px-4 py-4"><p className="font-semibold">{business.owner?.fullName || 'Chưa cập nhật'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{business.owner?.email || business.contactEmail || 'Chưa cập nhật email'}</p></td><td className="px-4 py-4"><strong>{business.activeBranchCount}/{business.branchCount}</strong><p className="text-xs text-[var(--bb-muted)]">đang hoạt động</p></td><td className="px-4 py-4 font-bold tabular-nums">{business.serviceCount}</td><td className="px-4 py-4 font-bold tabular-nums">{business.bookingCount.toLocaleString('vi-VN')}</td><td className="px-4 py-4"><StatusBadge value={business.status} /></td><td className="px-5 py-4 text-right"><Link to={`/admin/businesses/${business.id}`} className="inline-flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-lg border border-[var(--bb-border)] px-3 text-xs font-bold hover:bg-zinc-100"><Eye size={14} />Chi tiết</Link></td></tr>)}</tbody></table></div>;
}

function BranchCards({ rows }) {
  return <div className="divide-y divide-[var(--bb-border)] md:hidden">{rows.map((branch) => <article key={branch.id} className="space-y-4 p-4">
    <DirectoryMedia entity={branch} kind="branch" />
    <div className="flex items-start justify-between gap-3"><div className="min-w-0 flex-1 break-words"><h2 className="font-bold">{branch.publicName || branch.name}</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">{branch.business?.name}</p></div><StatusBadge value={branch.reviewStatus || branch.status} /></div>
    <p className="flex items-start gap-2 text-sm text-[var(--bb-muted)]"><MapPin size={15} className="mt-0.5 shrink-0" />{branch.location || 'Chưa cập nhật địa chỉ'}</p>
    <div className="flex flex-wrap gap-4 text-sm"><span><strong>{Number(branch.services || branch.serviceCount || 0)}</strong> dịch vụ</span><span><strong>{Number(branch.bookings || branch.bookingCount || 0).toLocaleString('vi-VN')}</strong> lịch hẹn</span><span><strong>{branch.rating || '—'}</strong> điểm</span></div>
    <Link to={`/admin/branches/${branch.id}`} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-[var(--bb-border)] px-3 text-sm font-bold"><Eye size={15} />Xem chi tiết</Link>
  </article>)}</div>;
}

function BranchTable({ rows }) {
  return <div className="hidden overflow-x-auto md:block"><table className="w-full min-w-[1020px] text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase tracking-wide text-[var(--bb-muted)]"><tr><th className="px-5 py-3">Chi nhánh</th><th className="px-4 py-3">Doanh nghiệp</th><th className="px-4 py-3">Khu vực</th><th className="px-4 py-3">Dịch vụ</th><th className="px-4 py-3">Lịch hẹn</th><th className="px-4 py-3">Đánh giá</th><th className="px-4 py-3">Trạng thái</th><th className="px-5 py-3 text-right">Thao tác</th></tr></thead><tbody className="divide-y divide-[var(--bb-border)]">{rows.map((branch) => <tr key={branch.id} className="hover:bg-zinc-50/70"><td className="px-5 py-4"><div className="flex min-w-[250px] items-center gap-3"><span className="font-bold">{branch.publicName || branch.name}</span></div></td><td className="px-4 py-4">{branch.business?.name}</td><td className="max-w-[260px] px-4 py-4"><span className="flex items-start gap-1.5 text-[var(--bb-muted)]"><MapPin size={15} className="mt-0.5 shrink-0" />{branch.location || 'Chưa cập nhật'}</span></td><td className="px-4 py-4 font-bold tabular-nums">{Number(branch.services || branch.serviceCount || 0)}</td><td className="px-4 py-4 font-bold tabular-nums">{Number(branch.bookings || branch.bookingCount || 0).toLocaleString('vi-VN')}</td><td className="px-4 py-4"><span className="inline-flex items-center gap-1 font-semibold"><Star size={14} className="fill-amber-400 text-amber-400" />{branch.rating || 'Chưa có'}</span></td><td className="px-4 py-4"><StatusBadge value={branch.reviewStatus || branch.status} /></td><td className="px-5 py-4 text-right"><Link to={`/admin/branches/${branch.id}`} className="inline-flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-lg border border-[var(--bb-border)] px-3 text-xs font-bold hover:bg-zinc-100"><Eye size={14} />Chi tiết</Link></td></tr>)}</tbody></table></div>;
}

function Directory() {
  const [entity, setEntity] = useState('business');
  const [search, setSearch] = useState('');
  const deferredSearch = useDeferredValue(search);
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, total: 0, totalPages: 1 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const request = entity === 'business' ? adminApi.getBusinessDirectory : adminApi.getBranchDirectory;
      const response = await request({ search: deferredSearch.trim(), status, page, limit: 10 });
      setRows(response?.data || []);
      setPagination(response?.pagination || { page, total: 0, totalPages: 1 });
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải danh sách quản trị.');
    } finally { setLoading(false); }
  }, [deferredSearch, entity, page, status]);

  useEffect(() => { load(); }, [load]);
  const filters = entity === 'business' ? BUSINESS_FILTERS : BRANCH_FILTERS;
  const changeEntity = (next) => {
    setRows([]);
    setLoading(true);
    setEntity(next);
    setStatus('');
    setSearch('');
    setPage(1);
  };

  return <div className="space-y-5">
    <PageHeader eyebrow="Đối tác BeautyBook" title="Doanh nghiệp & chi nhánh" description="Theo dõi pháp nhân trước, sau đó đi sâu vào từng địa điểm vận hành." />
    <div className="inline-flex rounded-xl border border-[var(--bb-border)] bg-white p-1" role="tablist" aria-label="Loại hồ sơ">
      <button type="button" role="tab" aria-selected={entity === 'business'} onClick={() => changeEntity('business')} className={`inline-flex min-h-10 items-center gap-2 rounded-lg px-4 text-sm font-bold ${entity === 'business' ? 'bg-zinc-950 text-white' : 'text-zinc-600 hover:bg-zinc-100'}`}><Building2 size={16} />Doanh nghiệp</button>
      <button type="button" role="tab" aria-selected={entity === 'branch'} onClick={() => changeEntity('branch')} className={`inline-flex min-h-10 items-center gap-2 rounded-lg px-4 text-sm font-bold ${entity === 'branch' ? 'bg-zinc-950 text-white' : 'text-zinc-600 hover:bg-zinc-100'}`}><Store size={16} />Chi nhánh</button>
    </div>
    <Card className="p-4"><div className="grid gap-3 md:grid-cols-[minmax(260px,1fr)_260px_auto]"><Field label={entity === 'business' ? 'Tìm doanh nghiệp hoặc chủ sở hữu' : 'Tìm chi nhánh, doanh nghiệp hoặc địa chỉ'}><div className="relative"><Search size={17} className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" /><Input className="pl-10" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder={entity === 'business' ? 'Tên doanh nghiệp, email...' : 'Tên chi nhánh, khu vực...'} /></div></Field><Field label="Trạng thái"><Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>{filters.map(([value, label]) => <option key={value || 'all'} value={value}>{label}</option>)}</Select></Field><div className="flex items-end"><p className="min-h-11 rounded-lg bg-[var(--bb-surface-subtle)] px-4 py-3 text-sm font-semibold text-[var(--bb-muted)]">{Number(pagination.total || 0).toLocaleString('vi-VN')} hồ sơ</p></div></div></Card>
    <Card className="overflow-hidden">{loading ? <div className="p-5"><Skeleton rows={8} /></div> : error ? <ErrorState message={error} onRetry={load} /> : !rows.length ? <EmptyState icon={entity === 'business' ? Building2 : Store} title="Không có hồ sơ phù hợp" description="Thử đổi từ khóa hoặc trạng thái lọc." /> : <>{entity === 'business' ? <><BusinessCards rows={rows} /><BusinessTable rows={rows} /></> : <><BranchCards rows={rows} /><BranchTable rows={rows} /></>}<Pagination pagination={pagination} onChange={setPage} /></>}</Card>
  </div>;
}

export function AdminSalons() {
  const can = useAuthStore((state) => state.can);
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedView = searchParams.get('view') || 'directory';
  const available = new Set(WORKSPACE_ITEMS.filter(([, , permission]) => can(permission)).map(([id]) => id));
  const view = available.has(requestedView) ? requestedView : 'directory';
  const changeView = (next) => setSearchParams(next === 'directory' ? {} : { view: next });

  return <div className="min-h-full bg-[var(--bb-canvas)] p-4 sm:p-6 lg:p-8"><div className="mx-auto max-w-[1500px]">
    <WorkspaceNav view={view} onChange={changeView} can={can} />
    {view === 'review' ? <AdminCompliance /> : view === 'taxonomy' ? <AdminServiceTaxonomy /> : <Directory />}
  </div></div>;
}

export default AdminSalons;
