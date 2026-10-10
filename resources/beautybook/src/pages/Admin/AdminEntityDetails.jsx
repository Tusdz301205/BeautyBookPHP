import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, Building2, CalendarDays, Clock3, FileText, History, MapPin, ShieldCheck, UsersRound } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { branchesApi, businessApi, usersApi } from '../../api/apiClient';
import AccountStatusControl from '../../components/admin/AccountStatusControl';
import LegalDocuments from '../../components/admin/LegalDocuments';
import { HomeMedia } from '../../components/public/HomeMedia';
import { Badge, Card, EmptyState, ErrorState, Page, PageHeader, Skeleton } from '../../components/ui';
import { getBranchFallbackMedia, getBusinessFallbackMedia, getServiceFallbackMedia } from '../../config/homeMedia';
import { documentLabel, statusLabel, statusTone } from '../../utils/displayLabels';

const date = (value, withTime = false) => value ? new Date(value).toLocaleString('vi-VN', withTime ? { dateStyle: 'short', timeStyle: 'short' } : { dateStyle: 'short' }) : '—';
const money = (value) => value == null ? '—' : `${Number(value).toLocaleString('vi-VN')} ₫`;
const timeOnly = (value) => {
  if (!value) return '—';
  const text = String(value);
  const directTime = text.match(/(?:T|^)(\d{2}:\d{2})/);
  if (directTime) return directTime[1];
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? text : parsed.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' });
};
const roleNames = { PLATFORM_ADMIN: 'Quản trị nền tảng', BUSINESS_OWNER: 'Chủ doanh nghiệp', RECEPTIONIST: 'Lễ tân', STAFF: 'Nhân viên', CUSTOMER: 'Khách hàng', GUEST: 'Khách vãng lai' };
const workspaceNames = { PLATFORM: 'Nền tảng', BUSINESS: 'Doanh nghiệp', BRANCH: 'Chi nhánh', CUSTOMER: 'Khách hàng' };
const readinessLabels = {
  ready: 'Đủ điều kiện nhận lịch', profileComplete: 'Thông tin chi nhánh', hoursConfigured: 'Giờ hoạt động',
  servicesAvailable: 'Dịch vụ đang mở', staffAvailable: 'Nhân viên nhận lịch', policyConfirmed: 'Chính sách đặt lịch',
  locationComplete: 'Địa chỉ hoạt động', approved: 'Hồ sơ đã duyệt', businessEligible: 'Doanh nghiệp đủ điều kiện',
};

function DetailShell({ load, backTo, eyebrow, title, description, children }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const reload = async () => { setLoading(true); setError(''); try { setData(await load()); } catch (requestError) { setError(requestError.message || 'Không thể tải chi tiết.'); } finally { setLoading(false); } };
  useEffect(() => { reload(); }, [load]);
  return <Page><Link to={backTo} className="mb-4 inline-flex min-h-10 items-center gap-2 text-sm font-bold text-[var(--bb-muted)] hover:text-[var(--bb-brand)]"><ArrowLeft size={16} />Quay lại danh sách</Link><PageHeader eyebrow={eyebrow} title={data ? title(data) : 'Chi tiết'} description={description} />{loading ? <Card className="p-6"><Skeleton rows={9} /></Card> : error ? <Card><ErrorState message={error} onRetry={reload} /></Card> : !data ? <Card><EmptyState title="Không tìm thấy dữ liệu" /></Card> : children(data, reload)}</Page>;
}

function Section({ title, icon: Icon, children }) {
  return <Card className="overflow-hidden"><div className="flex items-center gap-2 border-b border-[var(--bb-border)] px-5 py-4"><Icon size={18} className="text-[var(--bb-brand)]" /><h2 className="font-bold">{title}</h2></div><div className="p-5">{children}</div></Card>;
}

function Grid({ children, columns = 'xl:grid-cols-3' }) { return <div className={`grid gap-3 sm:grid-cols-2 ${columns}`}>{children}</div>; }
function Info({ label, value }) { return <div className="rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface-subtle)] p-4"><p className="text-xs font-bold uppercase tracking-wide text-[var(--bb-muted)]">{label}</p><p className="mt-1 break-words font-semibold">{value ?? '—'}</p></div>; }
function Timeline({ items, empty = 'Chưa có lịch sử.', showNotes = false }) {
  if (!items?.length) return <p className="text-sm text-[var(--bb-muted)]">{empty}</p>;
  return <ol className="space-y-3">{items.map((item, index) => <li key={item.id || index} className="border-l-2 border-pink-200 pl-4"><p className="text-sm font-semibold">{item.toStatus ? statusLabel(item.toStatus) : item.action === 'CREATE' ? 'Đã tạo hồ sơ' : item.action === 'UPDATE' ? 'Đã cập nhật thông tin' : item.action === 'STATUS_CHANGE' ? 'Đã đổi trạng thái' : item.title || 'Đã cập nhật hồ sơ'}</p><p className="text-xs text-[var(--bb-muted)]">{date(item.createdAt, true)}</p>{showNotes && (item.reason || item.note) && <p className="mt-1 text-sm text-[var(--bb-ink-soft)]">{item.reason || item.note}</p>}</li>)}</ol>;
}

export function AdminUserDetail() {
  const { userId } = useParams();
  const load = useMemo(() => () => usersApi.getById(userId), [userId]);
  return <DetailShell load={load} backTo="/admin/users" eyebrow="Tài khoản & phân quyền" title={(user) => user.fullName || user.email} description="Thông tin tài khoản, vai trò được cấp, phiên đăng nhập và lịch hẹn liên quan.">{(user, reload) => {
    const roles = user.userRoles || [];
    const customerBookings = user.customerProfile?.bookings || user.customerBookings || [];
    const staffBookings = user.staffProfile?.futureBookings || [];
    return <div className="space-y-5"><Grid><Info label="Email" value={user.email} /><Info label="Số điện thoại" value={user.phone || 'Chưa cập nhật'} /><Info label="Trạng thái" value={user.isActive ? 'Đang hoạt động' : 'Đang bị khóa'} /><Info label="Xác minh email" value={user.isEmailVerified ? 'Đã xác minh' : 'Chưa xác minh'} /><Info label="Ngày tạo" value={date(user.createdAt, true)} /><Info label="Đăng nhập gần nhất" value={date(user.lastLoginAt, true)} /></Grid>
      <AccountStatusControl user={user} onChanged={reload} />
      <Section title="Vai trò và phạm vi" icon={ShieldCheck}>{roles.length ? <div className="space-y-2">{roles.map((assignment) => <div key={assignment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[var(--bb-border)] p-4"><div><p className="font-bold">{roleNames[assignment.role?.code] || assignment.role?.name || 'Vai trò chưa xác định'}</p><p className="text-sm text-[var(--bb-muted)]">{assignment.business?.name || 'Toàn nền tảng'}{assignment.branch?.name ? ` · ${assignment.branch.name}` : ''}</p></div><Badge tone={assignment.expiresAt && new Date(assignment.expiresAt) < new Date() ? 'danger' : 'success'}>{assignment.expiresAt ? `Hết hạn ${date(assignment.expiresAt)}` : 'Đang hiệu lực'}</Badge></div>)}</div> : <EmptyState title="Chưa có vai trò" />}</Section>
      <Section title="Phiên đăng nhập" icon={Clock3}>{user.sessions?.length ? <div className="overflow-x-auto"><table className="w-full min-w-[620px] text-sm"><thead><tr className="text-left text-xs uppercase text-[var(--bb-muted)]"><th className="pb-3">Không gian</th><th className="pb-3">Phạm vi</th><th className="pb-3">Tạo lúc</th><th className="pb-3">Trạng thái</th></tr></thead><tbody>{user.sessions.map((session) => <tr key={session.id} className="border-t border-[var(--bb-border)]"><td className="py-3 font-bold">{workspaceNames[session.workspace] || 'Không gian làm việc'}</td><td>{session.business?.name || session.branch?.name || 'Cá nhân/nền tảng'}</td><td>{date(session.createdAt, true)}</td><td>{session.revokedAt ? 'Đã thu hồi' : 'Đang hoạt động'}</td></tr>)}</tbody></table></div> : <p className="text-sm text-[var(--bb-muted)]">Không có phiên đăng nhập.</p>}</Section>
      <Section title="Lịch hẹn liên quan" icon={CalendarDays}>{[...customerBookings, ...staffBookings].length ? <div className="space-y-2">{[...customerBookings, ...staffBookings].map((booking) => <Link key={booking.id} to={`/admin/appointments?bookingId=${booking.id}`} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[var(--bb-border)] p-4 hover:border-pink-300"><div><p className="font-bold">{booking.bookingCode || 'Lịch hẹn'}</p><p className="text-sm text-[var(--bb-muted)]">{booking.branch?.name || 'Chi nhánh'} · {date(booking.appointmentDate, true)}</p></div><Badge tone={statusTone(booking.status)}>{statusLabel(booking.status)}</Badge></Link>)}</div> : <EmptyState title="Chưa có lịch hẹn liên quan" />}</Section>
      <Section title="Nhật ký hoạt động" icon={History}><Timeline items={user.auditTrail} /></Section></div>;
  }}</DetailShell>;
}

export function AdminBranchDetail() {
  const { branchId } = useParams();
  const load = useMemo(() => () => branchesApi.getDetail(branchId), [branchId]);
  return <DetailShell load={load} backTo="/admin/salons" eyebrow="Hồ sơ chi nhánh" title={(branch) => branch.publicName || branch.name} description="Thông tin địa điểm, điều kiện vận hành, dịch vụ, đội ngũ và lịch sử xét duyệt.">{(branch) => <div className="space-y-5"><HomeMedia src={branch?.images?.[0]?.media?.url || null} fallbackSrc={getBranchFallbackMedia(branch)} alt={`Không gian ${branch.publicName || branch.name}`} fallbackAlt={`Hình minh họa cho chi nhánh ${branch.publicName || branch.name} trên BeautyBook`} ratio="16 / 7" label="Ảnh chi nhánh" className="w-full" /><Grid><Info label="Doanh nghiệp" value={branch.business?.name} /><Info label="Trạng thái hồ sơ" value={statusLabel(branch.reviewStatus || branch.status)} /><Info label="Trạng thái vận hành" value={statusLabel(branch.operationalStatus)} /><Info label="Địa chỉ" value={[branch.addressLine, branch.district?.name, branch.district?.province?.name].filter(Boolean).join(', ') || branch.address} /><Info label="Dịch vụ đang mở" value={branch.services?.filter((item) => item.status === 'ACTIVE').length || 0} /><Info label="Nhân viên nhận lịch" value={branch.staffAssignments?.filter((item) => item.staff?.isBookable).length || 0} /></Grid>
    {branch.business?.id && <Link to={`/admin/businesses/${branch.business.id}`} className="inline-flex min-h-10 items-center gap-2 font-bold text-[var(--bb-brand)]"><Building2 size={17} />Xem doanh nghiệp chủ quản</Link>}
    <Section title="Điều kiện nhận đặt lịch" icon={ShieldCheck}><Grid>{Object.entries(branch.readiness || {}).filter(([, value]) => typeof value !== 'object').map(([key, value]) => <Info key={key} label={readinessLabels[key] || 'Điều kiện vận hành'} value={typeof value === 'boolean' ? value ? 'Đã đạt' : 'Chưa đạt' : String(value ?? '—')} />)}</Grid></Section>
    <Section title="Giờ hoạt động" icon={Clock3}>{branch.workingHours?.length ? <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{branch.workingHours.map((row) => <Info key={row.id} label={['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][row.dayOfWeek] || `Ngày ${row.dayOfWeek}`} value={row.isClosed ? 'Đóng cửa' : `${timeOnly(row.openTime)} – ${timeOnly(row.closeTime)}`} />)}</div> : <EmptyState title="Chưa cấu hình giờ hoạt động" />}</Section>
    <Section title="Dịch vụ" icon={FileText}>{branch.services?.length ? <div className="grid gap-2 sm:grid-cols-2">{branch.services.map((service) => <div key={service.id} className="flex gap-3 rounded-xl border border-[var(--bb-border)] p-4"><HomeMedia src={service?.images?.[0]?.media?.url || null} fallbackSrc={getServiceFallbackMedia(service)} alt={`Dịch vụ ${service.name}`} fallbackAlt={`Hình minh họa dịch vụ ${service.name}`} ratio="1 / 1" label="Ảnh dịch vụ" className="w-20 shrink-0 overflow-hidden rounded-lg" /><div><p className="font-bold">{service.name}</p><p className="text-sm text-[var(--bb-muted)]">{money(service.price)} · {service.durationMinutes} phút</p></div></div>)}</div> : <EmptyState title="Chưa có dịch vụ" />}</Section>
    <Section title="Đội ngũ đang phân công" icon={UsersRound}>{branch.staffAssignments?.length ? <div className="grid gap-2 sm:grid-cols-2">{branch.staffAssignments.map((assignment) => <div key={assignment.id} className="rounded-xl border border-[var(--bb-border)] p-4"><p className="font-bold">{assignment.staff?.fullName}</p><p className="text-sm text-[var(--bb-muted)]">{assignment.staff?.position || 'Nhân viên'} · {assignment.staff?.isBookable ? 'Có nhận lịch' : 'Không nhận lịch'}</p></div>)}</div> : <EmptyState title="Chưa có nhân viên được phân công" />}</Section>
    <Section title="Lịch sử xét duyệt" icon={History}><Timeline items={branch.reviewEvents} showNotes /></Section></div>}</DetailShell>;
}

const BUSINESS_TABS = [
  ['overview', 'Tổng quan'], ['branches', 'Chi nhánh'], ['services', 'Dịch vụ'], ['registration', 'Hồ sơ đăng ký'], ['history', 'Lịch sử'],
];

function BusinessDetailContent({ business }) {
  const [tab, setTab] = useState('overview');
  const owner = business.owner?.user || business.owner;
  const summary = business.summary || {};
  return <div className="space-y-5">
    <nav className="flex gap-1 overflow-x-auto rounded-xl border border-[var(--bb-border)] bg-white p-1" aria-label="Chi tiết doanh nghiệp">{BUSINESS_TABS.map(([id, label]) => <button key={id} type="button" onClick={() => setTab(id)} className={`min-h-10 whitespace-nowrap rounded-lg px-4 text-sm font-bold ${tab === id ? 'bg-zinc-950 text-white' : 'text-zinc-600 hover:bg-zinc-100'}`}>{label}</button>)}</nav>
    {tab === 'registration' && <Grid>
      <Info label="Loại hình dịch vụ" value={business.businessTypeLabel || 'Chưa chọn loại hình dịch vụ'} />
      <Info label="Tên pháp nhân" value={business.owner?.companyName || 'Chưa cập nhật'} />
      <Info label="Mã số thuế" value={business.owner?.taxCode || 'Chưa cập nhật'} />
      <Info label="Đường dẫn BeautyBook" value={business.slug || 'Chưa cập nhật'} />
    </Grid>}
    {tab === 'overview' && <><HomeMedia fallbackSrc={getBusinessFallbackMedia(business)} fallbackAlt={`Hình minh họa cho doanh nghiệp ${business.name} trên BeautyBook`} ratio="16 / 7" label="Ảnh doanh nghiệp" className="w-full" /><Grid><Info label="Chủ sở hữu" value={owner?.fullName || business.legalRepresentative || 'Chưa cập nhật'} /><Info label="Email liên hệ" value={business.contactEmail || owner?.email || 'Chưa cập nhật'} /><Info label="Số điện thoại" value={business.contactPhone || owner?.phone || 'Chưa cập nhật'} /><Info label="Trạng thái" value={statusLabel(business.status)} /><Info label="Ngày tham gia" value={date(business.createdAt)} /><Info label="Điểm đánh giá" value={summary.averageRating == null ? 'Chưa có đánh giá' : `${summary.averageRating}/5 · ${summary.reviewCount || 0} đánh giá`} /></Grid><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Info label="Chi nhánh" value={`${summary.activeBranchCount || 0}/${summary.branchCount ?? business.branches?.length ?? 0} đang hoạt động`} /><Info label="Dịch vụ" value={summary.serviceCount ?? business.serviceCatalog?.length ?? 0} /><Info label="Lịch hẹn" value={Number(summary.bookingCount || 0).toLocaleString('vi-VN')} /><Info label="Nhân sự" value={business.members?.length || 0} /></div></>}
    {tab === 'branches' && <Section title="Chi nhánh trực thuộc" icon={MapPin}>{business.branches?.length ? <div className="grid gap-3 md:grid-cols-2">{business.branches.map((branch) => <Link key={branch.id} to={`/admin/branches/${branch.id}`} className="overflow-hidden rounded-xl border border-[var(--bb-border)] hover:border-pink-300"><HomeMedia fallbackSrc={getBranchFallbackMedia(branch)} fallbackAlt={`Hình minh họa cho chi nhánh ${branch.publicName || branch.name} trên BeautyBook`} ratio="16 / 9" label="Ảnh chi nhánh" className="w-full rounded-none border-0" /><div className="p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-bold">{branch.publicName || branch.name}</p><p className="mt-1 text-sm text-[var(--bb-muted)]">{branch.addressLine || 'Chưa cập nhật địa chỉ'}</p></div><Badge tone={statusTone(branch.reviewStatus || branch.status)}>{statusLabel(branch.reviewStatus || branch.status)}</Badge></div><div className="mt-3 flex flex-wrap gap-3 text-xs text-[var(--bb-muted)]"><span>{branch._count?.services || 0} dịch vụ</span><span>{branch._count?.staff || 0} nhân viên</span><span>{branch._count?.bookings || 0} lịch hẹn</span></div></div></Link>)}</div> : <EmptyState title="Chưa có chi nhánh" />}</Section>}
    {tab === 'services' && <Section title="Dịch vụ toàn doanh nghiệp" icon={FileText}>{business.serviceCatalog?.length ? <div className="grid gap-3 md:grid-cols-2">{business.serviceCatalog.map((service) => <div key={service.id} className="flex gap-3 rounded-xl border border-[var(--bb-border)] p-4"><HomeMedia fallbackSrc={getServiceFallbackMedia(service)} fallbackAlt={`Hình minh họa dịch vụ ${service.name}`} ratio="1 / 1" label="Ảnh dịch vụ" className="w-20 shrink-0 overflow-hidden rounded-lg" /><div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div><p className="font-bold">{service.name}</p><p className="mt-1 text-sm text-[var(--bb-muted)]">{service.canonicalService?.name || 'Chưa phân loại'} · {money(service.basePrice)} · {service.baseDurationMinutes} phút</p></div><Badge tone={statusTone(service.status)}>{statusLabel(service.status)}</Badge></div><p className="mt-3 text-xs text-[var(--bb-muted)]">Đang áp dụng tại {service._count?.branchServices || 0} chi nhánh</p></div></div>)}</div> : <EmptyState title="Chưa có dịch vụ" />}</Section>}
    {tab === 'registration' && <div className="space-y-5"><Grid><Info label="Người đại diện pháp luật" value={business.legalRepresentative || owner?.fullName || 'Chưa cập nhật'} /><Info label="Email đăng ký" value={business.contactEmail || owner?.email || 'Chưa cập nhật'} /><Info label="Số điện thoại" value={business.contactPhone || owner?.phone || 'Chưa cập nhật'} /><Info label="Địa chỉ đăng ký" value={business.addressLine || 'Chưa cập nhật'} /><Info label="Ngày gửi hồ sơ" value={date(business.submittedAt)} /><Info label="Ngày xét duyệt" value={date(business.reviewedAt)} /></Grid><Section title="Tài liệu đăng ký" icon={FileText}><LegalDocuments documents={business.documents || []} /></Section></div>}
    {tab === 'history' && <div className="grid gap-5 xl:grid-cols-2"><Section title="Lịch sử xét duyệt" icon={ShieldCheck}><Timeline items={business.reviewEvents} showNotes /></Section><Section title="Nhật ký hệ thống" icon={History}><Timeline items={business.auditTrail} /></Section></div>}
  </div>;
}

export function AdminBusinessDetail() {
  const { businessId } = useParams();
  const load = useMemo(() => () => businessApi.getDetail(businessId), [businessId]);
  return <DetailShell load={load} backTo="/admin/salons" eyebrow="Hồ sơ doanh nghiệp" title={(business) => business.name} description="Thông tin chủ sở hữu, chi nhánh, dịch vụ, hồ sơ đăng ký và lịch sử xử lý.">{(business) => <BusinessDetailContent business={business} />}</DetailShell>;
}
