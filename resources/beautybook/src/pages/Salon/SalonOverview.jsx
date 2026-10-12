import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { endOfMonth, format, startOfMonth } from 'date-fns';
import {
  CalendarCheck,
  CheckCircle2,
  Clock3,
  CreditCard,
  MapPin,
  Scissors,
  Star,
  TrendingUp,
  UserRoundCheck,
  Users,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { bookingsApi, branchesApi, paymentsApi, reportsApi, reviewsApi, servicesApi, staffApi } from '../../api/apiClient';
import { FINANCE_WORKSPACE_ENABLED } from '../../config/featureAvailability';
import { Badge, Card, EmptyState, ErrorState, Field, InlineNotice, Input, MetricCard, Page, PageHeader, Select, Skeleton } from '../../components/ui';
import { useAuthStore } from '../../store/authStore';
import { normalizeBooking } from '../../utils/bookingCalendar.adapter';

const TONES = { PENDING: 'warning', CONFIRMED: 'info', CHECKED_IN: 'info', IN_PROGRESS: 'brand', COMPLETED: 'success', CANCELLED: 'danger', NO_SHOW: 'neutral', EXPIRED: 'neutral' };
const LABELS = { PENDING: 'Chờ xác nhận', CONFIRMED: 'Đã xác nhận', CHECKED_IN: 'Đã đến', IN_PROGRESS: 'Đang thực hiện', COMPLETED: 'Hoàn thành', CANCELLED: 'Đã hủy', NO_SHOW: 'Không đến', EXPIRED: 'Hết hạn giữ chỗ' };
const DATA_LABELS = { bookings: 'lịch hẹn', services: 'dịch vụ', staff: 'nhân sự', reviews: 'đánh giá', payments: 'thanh toán', overview: 'tổng quan', dashboard: 'phân tích' };
const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const axisMoney = (value) => {
  const amount = Number(value || 0);
  if (Math.abs(amount) >= 1000000) return `${Number((amount / 1000000).toFixed(1)).toLocaleString('vi-VN')}tr`;
  if (Math.abs(amount) >= 1000) return `${Number((amount / 1000).toFixed(1)).toLocaleString('vi-VN')}k`;
  return amount.toLocaleString('vi-VN');
};
const statusLabel = (value) => LABELS[value] || value;
const CHART_COLORS = [
  'var(--bb-warning)',
  'var(--bb-info)',
  'var(--bb-brand)',
  'var(--bb-brand-strong)',
  'var(--bb-success)',
  'var(--bb-danger)',
  'var(--bb-muted)',
];

export function SalonOverview() {
  const user = useAuthStore((state) => state.user);
  const can = useAuthStore((state) => state.can);
  const roles = useMemo(() => new Set([...(user?.roles || []), ...(user?.scopes || []).map((scope) => scope.code)]), [user]);
  const owner = roles.has('BUSINESS_OWNER');
  const financeAllowed = FINANCE_WORKSPACE_ENABLED && (can('payment:read:tenant') || can('report:revenue:tenant'));
  const [from, setFrom] = useState(format(startOfMonth(new Date()), 'yyyy-MM-dd'));
  const [to, setTo] = useState(format(endOfMonth(new Date()), 'yyyy-MM-dd'));
  const [branches, setBranches] = useState([]);
  const [branchId, setBranchId] = useState('ALL');
  const [data, setData] = useState({ bookings: [], services: [], staff: [], reviews: [], payments: [], overview: null, dashboard: null });
  const [warnings, setWarnings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    branchesApi.getAccessible().then((result) => {
      const rows = Array.isArray(result) ? result : result?.data || [];
      setBranches(rows);
      setBranchId((current) => current !== 'ALL' && rows.some((branch) => branch.id === current) ? current : owner && rows.length > 1 ? 'ALL' : rows[0]?.id || 'ALL');
    }).catch(() => setBranches([]));
  }, [owner]);

  const load = useCallback(async () => {
    setLoading(true); setError(''); setWarnings([]);
    const selectedBranch = branchId === 'ALL' ? undefined : branchId;
    const tasks = [
      ['bookings', bookingsApi.getAll({ branchId: selectedBranch, dateFrom: from, dateTo: to, limit: 100 })],
      ['services', servicesApi.getManage(selectedBranch)],
      ['staff', staffApi.getAll(selectedBranch)],
      ...(financeAllowed ? [
        ['payments', paymentsApi.getAll()],
        ['overview', reportsApi.getOverview()],
        ['dashboard', reportsApi.getOwnerDashboard({ branchId: selectedBranch, from, to })],
      ] : []),
      ...(can('review:moderate:tenant') ? [['reviews', reviewsApi.getForManagement({ branchId: selectedBranch, status: 'PENDING', page: 1, limit: 20 })]] : []),
    ];
    const results = await Promise.allSettled(tasks.map(([, promise]) => promise));
    const next = { bookings: [], services: [], staff: [], reviews: [], payments: [], overview: null, dashboard: null };
    const failed = [];
    results.forEach((result, index) => {
      const key = tasks[index][0];
      if (result.status === 'fulfilled') {
        const value = result.value;
        if (key === 'overview' || key === 'dashboard') next[key] = value;
        else next[key] = value?.data ?? value ?? [];
      } else failed.push(key);
    });
    setData(next);
    setWarnings(failed);
    if (failed.length === tasks.length) setError(results[0]?.reason?.message || 'Không thể tải tổng quan vận hành.');
    setLoading(false);
  }, [branchId, can, financeAllowed, from, owner, to]);
  useEffect(() => { void load(); }, [load]);

  const bookings = useMemo(() => data.bookings.map(normalizeBooking), [data.bookings]);
  const upcoming = useMemo(() => bookings.filter((booking) => booking.startAt && booking.startAt >= new Date() && !['COMPLETED', 'CANCELLED', 'NO_SHOW'].includes(booking.status)).sort((a, b) => a.startAt - b.startAt).slice(0, 6), [bookings]);
  const pending = bookings.filter((booking) => booking.status === 'PENDING').length;
  const completed = bookings.filter((booking) => booking.status === 'COMPLETED').length;
  const activeServices = data.services.filter((service) => service.active !== false && service.status !== 'INACTIVE').length;
  const activeStaff = data.staff.filter((staff) => staff.status === 'ACTIVE').length;
  const bookableStaff = data.staff.filter((staff) => staff.status === 'ACTIVE' && staff.isBookable).length;
  const scopedPayments = data.payments.filter((payment) => {
    const timestamp = new Date(payment.paidAt || payment.createdAt);
    const inRange = timestamp >= new Date(`${from}T00:00:00`) && timestamp <= new Date(`${to}T23:59:59`);
    return inRange && (branchId === 'ALL' || payment.booking?.branch?.id === branchId || payment.booking?.branchId === branchId);
  });
  const grossRevenue = scopedPayments.filter((payment) => ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'].includes(payment.status)).reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
  const refunded = scopedPayments.flatMap((payment) => payment.refundRequests || []).filter((refund) => refund.status === 'REFUNDED').reduce((sum, refund) => sum + Number(refund.amount || 0), 0);
  const pendingReviews = data.reviews.length;
  const actionItems = [
    { label: 'Lịch cần xác nhận', count: pending, to: '/salon/appointments', icon: CalendarCheck, tone: pending ? 'warning' : 'neutral' },
    { label: 'Đánh giá chờ xử lý', count: pendingReviews, to: '/salon/reviews', icon: Star, tone: pendingReviews ? 'warning' : 'neutral' },
  ];
  const dashboard = data.dashboard;
  const chartData = dashboard?.charts;
  const hasChartActivity = Boolean(chartData && (
    chartData.revenueSeries?.some((item) => item.grossRevenue || item.netRevenue || item.refundAmount || item.discountAmount || item.previousNetRevenue)
    || chartData.bookingStatus?.some((item) => item.count)
    || chartData.branchComparison?.length
    || chartData.topServices?.length
    || chartData.topCombos?.length
    || chartData.review?.distribution?.some((item) => item.count)
    || chartData.review?.trend?.some((item) => item.count || item.average)
  ));
  const comparison = (metric) => {
    const change = dashboard?.kpis?.[metric]?.changePercent;
    if (change === null) return 'Kỳ trước bằng 0; chưa tính được tỷ lệ';
    if (change === undefined) return 'Chưa có dữ liệu so sánh';
    return `${change > 0 ? '+' : ''}${change}% so với kỳ trước`;
  };
  const netRevenue = dashboard?.kpis?.netRevenue?.current ?? (grossRevenue - refunded);
  const bookingCount = dashboard?.kpis?.bookings?.current ?? bookings.length;
  const completedCount = dashboard?.kpis?.completedBookings?.current ?? completed;

  return <Page className="max-w-[1500px]">
    <PageHeader eyebrow={owner ? 'Toàn doanh nghiệp' : 'Chi nhánh được phân công'} title={owner ? 'Tổng quan doanh nghiệp' : 'Tổng quan chi nhánh'} description="Số liệu, lịch hẹn và cảnh báo trong phạm vi bạn được quản lý." />
    <Card className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4"><Field label="Phạm vi chi nhánh"><Select value={branchId} onChange={(event) => setBranchId(event.target.value)}>{owner && branches.length > 1 && <option value="ALL">Tất cả chi nhánh ({branches.length})</option>}{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Select></Field><Field label="Từ ngày"><Input type="date" value={from} onChange={(event) => setFrom(event.target.value)} /></Field><Field label="Đến ngày"><Input type="date" min={from} value={to} onChange={(event) => setTo(event.target.value)} /></Field><div className="flex items-end pb-5"><p className="text-xs leading-5 text-[var(--bb-muted)]"><MapPin size={14} className="mr-1 inline" />{branchId === 'ALL' ? `Đang xem ${branches.length} chi nhánh` : branches.find((branch) => branch.id === branchId)?.name || 'Đang xác định phạm vi'}</p></div></Card>
    {loading ? <Skeleton rows={9} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : <>
      {warnings.length > 0 && <InlineNotice tone="warning">Một phần dữ liệu chưa tải được: {warnings.map((key) => DATA_LABELS[key] || 'thông tin').join(', ')}. Những thông tin đã tải vẫn được hiển thị; hãy thử tải lại để xem đủ dữ liệu.</InlineNotice>}
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">{financeAllowed && <MetricCard icon={TrendingUp} label="Doanh thu thuần trong kỳ" value={money(netRevenue)} note={comparison('netRevenue')} />}<MetricCard icon={CalendarCheck} label="Lịch trong kỳ" value={bookingCount} note={comparison('bookings')} tone="info" /><MetricCard icon={CheckCircle2} label="Lịch hoàn thành" value={completedCount} note={comparison('completedBookings')} tone="success" /><MetricCard icon={Scissors} label="Dịch vụ đang mở" value={`${activeServices}/${data.services.length}`} /><MetricCard icon={Users} label="Nhân sự hoạt động" value={`${activeStaff}/${data.staff.length}`} tone="info" /></div>

      <section><div className="mb-3"><h2 className="text-lg font-bold">Trung tâm hành động</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">Ưu tiên các vấn đề đang cần người vận hành xử lý.</p></div><div className="grid gap-3 md:grid-cols-2">{actionItems.map((item) => <Link key={item.label} to={item.to} className="group"><Card className="flex h-full items-center gap-4 p-5 transition group-hover:border-[var(--bb-brand)]"><span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--bb-surface-subtle)] text-[var(--bb-brand-strong)]"><item.icon size={20} /></span><div className="min-w-0 flex-1"><p className="text-2xl font-bold tabular-nums">{item.count}</p><p className="text-sm font-semibold text-[var(--bb-muted)]">{item.label}</p></div><Badge tone={item.tone}>{item.count ? 'Cần xử lý' : 'Ổn định'}</Badge></Card></Link>)}</div></section>

      {financeAllowed && <section>
        <div className="mb-3"><h2 className="text-lg font-bold">Phân tích trong kỳ</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">Biểu đồ theo chi nhánh và khoảng ngày đã chọn ở trên.</p></div>
        {!chartData ? <Card><EmptyState title="Chưa tải được dữ liệu phân tích" description="Hãy thử tải lại để xem các biểu đồ trong kỳ." /></Card> : !hasChartActivity ? <Card><EmptyState title="Chưa có hoạt động để vẽ biểu đồ" description="Thử chọn khoảng ngày khác; biểu đồ sẽ xuất hiện khi có lịch hẹn, giao dịch hoặc đánh giá phù hợp." /></Card> : (
          <div className="grid gap-5 xl:grid-cols-2">
            {chartData.revenueSeries?.some((item) => item.grossRevenue || item.netRevenue || item.refundAmount || item.discountAmount || item.previousNetRevenue) && <ChartCard title="Doanh thu theo thời gian" to="/salon/payments">
              <ResponsiveContainer width="100%" height={280}>
                <AreaChart data={chartData.revenueSeries}>
                  <defs><linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--bb-brand)" stopOpacity={0.28} /><stop offset="100%" stopColor="var(--bb-brand)" stopOpacity={0.02} /></linearGradient></defs>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="date" tickFormatter={(value) => value.slice(5)} minTickGap={24} />
                  <YAxis tickFormatter={axisMoney} width={60} />
                  <Tooltip formatter={(value) => money(value)} labelFormatter={(value) => new Date(`${value}T00:00:00`).toLocaleDateString('vi-VN')} />
                  <Legend />
                  <Area type="monotone" dataKey="grossRevenue" name="Doanh thu gộp" stroke="var(--bb-brand)" fill="url(#revenueFill)" strokeWidth={2} />
                  <Area type="monotone" dataKey="netRevenue" name="Doanh thu thuần" stroke="var(--bb-info)" fill="transparent" strokeWidth={2} />
                  <Area type="monotone" dataKey="discountAmount" name="Giảm giá" stroke="var(--bb-warning)" fill="transparent" strokeDasharray="3 3" />
                  <Area type="monotone" dataKey="refundAmount" name="Hoàn tiền" stroke="var(--bb-danger)" fill="transparent" strokeDasharray="5 4" />
                  <Area type="monotone" dataKey="previousNetRevenue" name="Thuần kỳ trước" stroke="var(--bb-muted)" fill="transparent" strokeDasharray="7 5" />
                </AreaChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.bookingStatus?.some((item) => item.count) && <ChartCard title="Trạng thái lịch hẹn" to="/salon/appointments">
              <ResponsiveContainer width="100%" height={280}>
                <PieChart>
                  <Pie data={chartData.bookingStatus.filter((item) => item.count)} dataKey="count" nameKey="status" innerRadius={66} outerRadius={100} paddingAngle={2}>
                    {chartData.bookingStatus.filter((item) => item.count).map((item, index) => <Cell key={item.status} fill={CHART_COLORS[index % CHART_COLORS.length]} />)}
                  </Pie>
                  <Tooltip formatter={(value, name) => [value, statusLabel(name)]} />
                  <Legend formatter={statusLabel} />
                </PieChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.branchComparison?.length > 0 && <ChartCard title="So sánh chi nhánh">
              <ResponsiveContainer width="100%" height={Math.max(280, chartData.branchComparison.length * 54)}>
                <BarChart data={chartData.branchComparison} layout="vertical" margin={{ left: 16 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" tickFormatter={axisMoney} />
                  <YAxis type="category" dataKey="branchName" width={110} tick={{ fontSize: 12 }} />
                  <Tooltip formatter={(value, name) => name === 'netRevenue' ? money(value) : value} />
                  <Legend />
                  <Bar dataKey="netRevenue" name="Doanh thu thuần" fill="var(--bb-brand)" radius={[0, 5, 5, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.topServices?.length > 0 && <ChartCard title="Dịch vụ được đặt nhiều" to="/salon/services">
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={chartData.topServices.slice(0, 6)} layout="vertical">
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" allowDecimals={false} />
                  <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 12 }} />
                  <Tooltip formatter={(value, name) => name === 'revenue' ? money(value) : value} />
                  <Bar dataKey="bookings" name="Lượt đặt" fill="var(--bb-brand-strong)" radius={[0, 5, 5, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.topServices?.length > 0 && <ChartCard title="Dịch vụ có doanh thu cao" to="/salon/services">
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={[...chartData.topServices].sort((left, right) => right.revenue - left.revenue).slice(0, 6)} layout="vertical">
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" tickFormatter={axisMoney} />
                  <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 12 }} />
                  <Tooltip formatter={(value) => money(value)} />
                  <Bar dataKey="revenue" name="Doanh thu" fill="var(--bb-success)" radius={[0, 5, 5, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.topCombos?.length > 0 && <ChartCard title="Combo được đặt nhiều" to="/salon/combos">
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={chartData.topCombos} layout="vertical">
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                  <XAxis type="number" allowDecimals={false} />
                  <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 12 }} />
                  <Tooltip />
                  <Bar dataKey="bookings" name="Lượt đặt" fill="var(--bb-info)" radius={[0, 5, 5, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.review?.distribution?.some((item) => item.count) && <ChartCard title="Phân bố đánh giá" to="/salon/reviews" meta={chartData.review?.average ? `${chartData.review.average}/5 · ${chartData.review.count} đánh giá` : undefined}>
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={chartData.review.distribution}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="rating" tickFormatter={(value) => `${value}★`} />
                  <YAxis allowDecimals={false} />
                  <Tooltip />
                  <Bar dataKey="count" name="Đánh giá" fill="var(--bb-warning)" radius={[5, 5, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>}

            {chartData.review?.trend?.some((item) => item.count || item.average) && <ChartCard title="Xu hướng đánh giá" to="/salon/reviews" meta={chartData.review?.pending ? `${chartData.review.pending} đánh giá chờ xử lý` : undefined}>
              <ResponsiveContainer width="100%" height={280}>
                <LineChart data={chartData.review.trend}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="date" tickFormatter={(value) => value.slice(5)} minTickGap={24} />
                  <YAxis domain={[1, 5]} allowDecimals={false} />
                  <Tooltip labelFormatter={(value) => new Date(`${value}T00:00:00`).toLocaleDateString('vi-VN')} />
                  <Line type="monotone" dataKey="average" name="Điểm trung bình" connectNulls stroke="var(--bb-warning)" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </ChartCard>}
          </div>
        )}
      </section>}

      <div className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.8fr)]"><Card className="overflow-hidden"><header className="flex items-center justify-between gap-3 border-b border-[var(--bb-border)] p-5"><div><h2 className="font-bold">Lịch hẹn tiếp theo</h2><p className="mt-1 text-xs text-[var(--bb-muted)]">Theo thời gian thực trong phạm vi đã chọn.</p></div><Link to="/salon/appointments" className="min-h-11 px-3 py-3 text-sm font-semibold text-[var(--bb-brand-strong)] hover:underline">Mở lịch</Link></header>{!upcoming.length ? <EmptyState icon={CalendarCheck} title="Không có lịch sắp tới" description="Lịch vẫn sẵn sàng để tạo hoặc tiếp nhận lịch hẹn mới." /> : <div className="divide-y divide-[var(--bb-border)]">{upcoming.map((booking) => <Link key={booking.id} to={`/salon/appointments?bookingId=${booking.id}`} className="flex flex-col gap-3 p-4 hover:bg-[var(--bb-surface-subtle)] sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="break-words text-sm font-bold">{booking.customerName}</p><p className="mt-1 break-words text-xs text-[var(--bb-muted)]">{booking.serviceNames.join(', ') || 'Chưa có dịch vụ'} · {booking.branchName || 'Chi nhánh'}</p></div><p className="text-xs font-semibold">{format(booking.startAt, 'dd/MM · HH:mm')}</p><Badge tone={TONES[booking.status] || 'neutral'}>{LABELS[booking.status] || booking.status}</Badge></Link>)}</div>}</Card><div className="space-y-4"><Card className="p-5"><h2 className="font-bold">Tình trạng nhân sự</h2><div className="mt-4 grid grid-cols-2 gap-3"><Mini icon={UserRoundCheck} label="Đang hoạt động" value={activeStaff} /><Mini icon={Scissors} label="Đang nhận lịch" value={bookableStaff} /></div></Card>{financeAllowed && <Card className="p-5"><h2 className="font-bold">Tóm tắt tài chính</h2><dl className="mt-4 space-y-3 text-sm"><Row label="Đã ghi nhận" value={money(grossRevenue)} /><Row label="Đã hoàn" value={money(refunded)} /><Row label="Thuần" value={money(grossRevenue - refunded)} strong /></dl><Link to="/salon/payments" className="mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-[var(--bb-brand-strong)] hover:underline"><CreditCard size={16} />Mở thanh toán & hoàn tiền</Link></Card>}</div></div>
    </>}
  </Page>;
}

function Mini({ icon: Icon, label, value }) { return <div className="rounded-xl bg-[var(--bb-surface-subtle)] p-3"><Icon size={17} className="text-[var(--bb-brand-strong)]" /><p className="mt-2 text-xl font-bold">{value}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{label}</p></div>; }
function Row({ label, value, strong }) { return <div className="flex justify-between gap-4"><dt className="text-[var(--bb-muted)]">{label}</dt><dd className={strong ? 'font-bold text-[var(--bb-brand-strong)]' : 'font-semibold'}>{value}</dd></div>; }
function ChartCard({ title, to, meta, children }) {
  return <Card className="min-w-0 overflow-hidden"><header className="flex min-h-16 items-center justify-between gap-3 border-b border-[var(--bb-border)] px-5 py-3"><div><h3 className="font-bold">{title}</h3>{meta && <p className="mt-1 text-xs text-[var(--bb-muted)]">{meta}</p>}</div><Link to={to} className="text-sm font-bold text-[var(--bb-brand-strong)] hover:underline">Chi tiết</Link></header><div className="min-h-[280px] p-4">{children}</div></Card>;
}

export default SalonOverview;
