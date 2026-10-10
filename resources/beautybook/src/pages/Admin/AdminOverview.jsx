import { useEffect, useState } from 'react';
import { ArrowRight, Building2, CalendarCheck, CircleDollarSign, Scale, Star, TrendingUp, Users } from 'lucide-react';
import { Link } from 'react-router-dom';
import { branchesApi, reportsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Card, EmptyState, ErrorState, MetricCard, Page, PageHeader, Skeleton } from '../../components/ui';
import { CategoryPieChart } from '../../components/charts/CategoryPieChart';
import { TopSalonsBarChart } from '../../components/charts/TopSalonsBarChart';
import { FINANCE_WORKSPACE_ENABLED } from '../../config/featureAvailability';

const workspaces = [
  ['/admin/salons?view=review', 'Xét duyệt hồ sơ', 'Duyệt hồ sơ doanh nghiệp và chi nhánh cần xử lý.', Scale, ['business:review:platform']],
  ['/admin/appointments', 'Điều phối lịch hẹn', 'Theo dõi lịch, danh sách và các thao tác vận hành.', CalendarCheck, ['booking:read:platform']],
  ...(FINANCE_WORKSPACE_ENABLED ? [['/admin/payments', 'Tài chính & hoàn tiền', 'Thu tiền, phê duyệt và xử lý hoàn tiền theo quyền.', CircleDollarSign, ['payment:read:platform']]] : []),
  ['/admin/reviews', 'Kiểm duyệt đánh giá', 'Xử lý nội dung đánh giá theo chính sách.', Star, ['review:moderate:platform']],
  ['/admin/salons', 'Doanh nghiệp & chi nhánh', 'Tra cứu và quản lý đối tác theo pháp nhân và địa điểm.', Building2, ['branch:read:platform']],
  ['/admin/users', 'Người dùng', 'Tra cứu tài khoản và quản trị trạng thái.', Users, ['user:read:platform']],
];

export function AdminOverview() {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const canOverview = can('report:overview:platform');
  const [data, setData] = useState({ branches: [], overview: null, categories: [], topSalons: [] });
  const [loading, setLoading] = useState(canOverview);
  const [error, setError] = useState('');
  const available = workspaces.filter(([, , , , permissions]) => permissions.some((permission) => can(permission)));
  const load = async () => {
    if (!canOverview) return;
    setLoading(true); setError('');
    try {
      const [branchResult, overview, categoryResult, salonResult] = await Promise.all([
        can('branch:read:platform') ? branchesApi.getManage() : Promise.resolve([]),
        reportsApi.getOverview(), reportsApi.getCategories(), reportsApi.getTopSalons(10),
      ]);
      const branches = Array.isArray(branchResult) ? branchResult : branchResult?.data || [];
      const categoryTotals = (categoryResult?.data || categoryResult || []).reduce((totals, item) => {
        const name = item.category || item.name || 'Khác';
        const value = Number(item.value ?? item.count ?? item.bookings ?? 0);
        totals.set(name, (totals.get(name) || 0) + (Number.isFinite(value) ? value : 0));
        return totals;
      }, new Map());
      const categories = Array.from(categoryTotals, ([name, value]) => ({ name, value }))
        .filter((item) => item.value > 0)
        .sort((a, b) => b.value - a.value);
      const topSalons = (salonResult?.data || salonResult || []).map((item) => ({ name: item.name || item.salon_name || item.branch_name || 'Cơ sở', bookings: Number(item.bookings || item.totalBookings || 0) }));
      setData({ branches, overview, categories, topSalons });
    } catch (loadError) { setError(loadError.message || 'Không thể tải tổng quan nền tảng.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [canOverview]);
  const pending = data.branches.filter((branch) => ['PENDING', 'Chờ duyệt'].includes(branch.status)).length;
  const overview = data.overview || {};
  const money = `${Number(overview.totalRevenue || 0).toLocaleString('vi-VN')} ₫`;
  return <Page>
    <PageHeader eyebrow="Không gian quản trị" title={`Xin chào${user?.fullName ? `, ${user.fullName}` : ''}`} description="Trang vào vai trò: chỉ hiển thị khu vực và dữ liệu mà quyền hiện tại cho phép." />
    {canOverview && (loading ? <Card className="p-5"><Skeleton rows={4} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : <>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><MetricCard icon={Building2} label="Tổng cơ sở" value={overview.totalBranches ?? overview.totalSalons ?? data.branches.length} note={`${pending} chờ duyệt`} /><MetricCard icon={Users} label="Tổng người dùng" value={overview.totalUsers ?? 0} note={overview.newUsersThisMonth ? `+${overview.newUsersThisMonth} tháng này` : undefined} tone="info" /><MetricCard icon={CalendarCheck} label="Tổng lịch hẹn" value={overview.totalBookings ?? 0} note={overview.pendingBookings ? `${overview.pendingBookings} đang chờ` : undefined} tone="success" />{FINANCE_WORKSPACE_ENABLED && <MetricCard icon={TrendingUp} label="Doanh thu hệ thống" value={money} tone="warning" />}</div>
      <div className="grid gap-4 lg:grid-cols-2"><Card className="p-5"><h2 className="font-bold">Lịch hẹn theo nhóm dịch vụ</h2><div className="mt-4">{data.categories.length ? <CategoryPieChart data={data.categories} /> : <EmptyState title="Chưa có dữ liệu danh mục" />}</div></Card><Card className="p-5"><h2 className="font-bold">Cơ sở có nhiều lượt đặt</h2><div className="mt-4">{data.topSalons.length ? <TopSalonsBarChart data={data.topSalons} /> : <EmptyState title="Chưa có dữ liệu xếp hạng" />}</div></Card></div>
    </>)}
    <section><h2 className="text-base font-bold">Khu vực được cấp quyền</h2><div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">{available.map(([path, title, description, Icon]) => <Link key={path} to={path} className="group rounded-[var(--bb-radius-card)] border border-[var(--bb-border)] bg-white p-5 transition hover:border-pink-300 hover:shadow-[var(--bb-shadow-card)]"><div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-pink-50 text-pink-700"><Icon size={18} /></span><div className="min-w-0 flex-1"><h3 className="font-bold text-[var(--bb-ink)]">{title}</h3><p className="mt-1 text-sm leading-6 text-[var(--bb-muted)]">{description}</p></div><ArrowRight className="mt-1 text-[var(--bb-muted)] transition group-hover:translate-x-1" size={17} /></div></Link>)}{!available.length && <Card className="md:col-span-2 xl:col-span-3"><EmptyState title="Chưa có khu vực được cấp quyền" description="Liên hệ quản trị viên để kiểm tra vai trò và quyền của tài khoản." /></Card>}</div></section>
  </Page>;
}

export default AdminOverview;
