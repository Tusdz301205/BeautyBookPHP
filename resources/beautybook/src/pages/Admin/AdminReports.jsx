import { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarCheck, CircleDollarSign, RefreshCw, Scissors, Users } from 'lucide-react';
import { bookingsApi, reportsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Badge, Button, Card, EmptyState, ErrorState, Field, MetricCard, Page, PageHeader, Select, Skeleton } from '../../components/ui';
import { CategoryBookingBarChart, RevenueBarChart, ServiceBookingPieChartLabeled, StylistPerformanceBars, TopSalonsBookingBarChart, UserGrowthLineChart } from '../../components/charts';

const unwrap = (value) => Array.isArray(value) ? value : value?.data || [];
const chart = (title, data, Component) => <Card className="min-w-0 p-5"><h2 className="font-bold">{title}</h2><div className="mt-4 min-h-64">{data.length ? <Component data={data} /> : <EmptyState title="Chưa có dữ liệu" />}</div></Card>;

export function AdminReports() {
  const can = useAuthStore((state) => state.can);
  const currentYear = new Date().getFullYear();
  const [year, setYear] = useState(currentYear);
  const [reports, setReports] = useState({ revenue: [], users: [], categories: [], salons: [], services: [], staff: [], overview: null, bookings: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const overviewAllowed = can('report:overview:platform');
  const revenueAllowed = can('report:revenue:platform');
  const usersAllowed = can('report:user_growth:platform');
  const bookingsAllowed = can('booking:read:platform');

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const [revenue, users, categories, salons, services, staff, overview, bookings] = await Promise.all([
        revenueAllowed ? reportsApi.getRevenue(year) : [], usersAllowed ? reportsApi.getUserGrowth(year) : [],
        overviewAllowed ? reportsApi.getCategories() : [], overviewAllowed ? reportsApi.getTopSalons(10) : [],
        overviewAllowed ? reportsApi.getServices() : [], overviewAllowed ? reportsApi.getStaffPerformance() : [],
        overviewAllowed ? reportsApi.getDashboardOverview() : null, bookingsAllowed ? bookingsApi.getStats() : null,
      ]);
      setReports({ revenue: unwrap(revenue), users: unwrap(users), categories: unwrap(categories), salons: unwrap(salons), services: unwrap(services), staff: unwrap(staff), overview, bookings });
    } catch (loadError) { setError(loadError.message || 'Không thể tải báo cáo được cấp quyền.'); }
    finally { setLoading(false); }
  }, [year, overviewAllowed, revenueAllowed, usersAllowed, bookingsAllowed]);
  useEffect(() => { load(); }, [load]);
  const years = useMemo(() => Array.from({ length: 5 }, (_, index) => currentYear - index), [currentYear]);
  const statusRows = reports.bookings ? [
    ['Chờ xử lý', reports.bookings.pending, 'warning'], ['Đã xác nhận', reports.bookings.confirmed, 'info'], ['Đang thực hiện', reports.bookings.inProgress, 'brand'], ['Hoàn thành', reports.bookings.completed, 'success'], ['Đã hủy', reports.bookings.cancelled, 'danger'], ['Không đến', reports.bookings.noShow, 'neutral'],
  ] : [];
  return <Page>
    <PageHeader eyebrow="Analytics" title="Báo cáo nền tảng" description="Chỉ gọi endpoint tương ứng permission của vai trò; không thay dữ liệu thiếu bằng dữ liệu mẫu." actions={<div className="flex items-end gap-2"><Field label="Năm"><Select value={year} onChange={(event) => setYear(Number(event.target.value))}>{years.map((item) => <option key={item} value={item}>{item}</option>)}</Select></Field><Button className="mb-0" variant="secondary" onClick={load} loading={loading}><RefreshCw size={16} />Làm mới</Button></div>} />
    {loading ? <Card className="p-5"><Skeleton rows={8} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : <>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><MetricCard icon={CalendarCheck} label="Tổng lịch hẹn" value={reports.bookings?.total ?? reports.overview?.totalBookings ?? '—'} /><MetricCard icon={CircleDollarSign} label={`Doanh thu ${year}`} value={revenueAllowed ? `${Number(reports.overview?.totalRevenue || 0).toLocaleString('vi-VN')} ₫` : '—'} tone="success" /><MetricCard icon={Users} label="Người dùng" value={reports.overview?.totalUsers ?? '—'} tone="info" /><MetricCard icon={Scissors} label="Cơ sở hoạt động" value={reports.overview?.totalBranches ?? '—'} tone="brand" /></div>
      {statusRows.length > 0 && <Card className="p-5"><h2 className="font-bold">Trạng thái lịch hẹn</h2><div className="mt-4 grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6">{statusRows.map(([label, value, tone]) => <div key={label} className="rounded-lg bg-[var(--bb-surface-subtle)] p-3 text-center"><Badge tone={tone}>{label}</Badge><p className="mt-2 text-xl font-bold tabular-nums">{Number(value || 0).toLocaleString('vi-VN')}</p></div>)}</div></Card>}
      {revenueAllowed && chart(`Doanh thu theo tháng · ${year}`, reports.revenue, RevenueBarChart)}
      {overviewAllowed && <><div className="grid gap-4 lg:grid-cols-2">{chart('Lịch hẹn theo nhóm dịch vụ', reports.categories, CategoryBookingBarChart)}{chart('Top cơ sở theo lượt đặt', reports.salons, TopSalonsBookingBarChart)}</div><div className="grid gap-4 lg:grid-cols-2">{chart('Top dịch vụ phổ biến', reports.services, ServiceBookingPieChartLabeled)}{chart('Hiệu suất nhân viên', reports.staff, StylistPerformanceBars)}</div></>}
      {usersAllowed && chart(`Tăng trưởng người dùng · ${year}`, reports.users, UserGrowthLineChart)}
      {!overviewAllowed && !revenueAllowed && !usersAllowed && <Card><EmptyState title="Không có loại báo cáo được cấp quyền" /></Card>}
    </>}
  </Page>;
}

export default AdminReports;
