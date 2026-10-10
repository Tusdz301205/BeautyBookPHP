import { useEffect, useState } from 'react';
import { BarChart3, CalendarCheck, CircleDollarSign, RotateCcw, WalletCards } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { reportsApi } from '../../api/apiClient';
import { Card, EmptyState, ErrorState, MetricCard, Page, PageHeader, Select, Skeleton } from '../../components/ui';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;

export function SalonStats() {
  const [year, setYear] = useState(new Date().getFullYear());
  const [data, setData] = useState({ financial: null, revenue: [], services: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const load = async () => {
    setLoading(true); setError('');
    try {
      const from = `${year}-01-01`;
      const to = year === new Date().getFullYear() ? new Date().toISOString().slice(0, 10) : `${year}-12-31`;
      const [financial, revenue, services] = await Promise.all([
        reportsApi.getFinancialSummary({ from, to }), reportsApi.getRevenue(year), reportsApi.getServices(),
      ]);
      setData({ financial, revenue, services });
    } catch (requestError) { setError(requestError.message); }
    finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, [year]);
  const financial = data.financial;
  return <Page>
    <PageHeader eyebrow="Phân tích" title="Báo cáo vận hành & tài chính" description="Doanh thu chỉ lấy giao dịch đã xác minh; refund và reversal được ghi nhận đúng kỳ, độc lập với giá niêm yết." actions={<Select aria-label="Chọn năm" value={year} onChange={(event) => setYear(Number(event.target.value))}>{[0, 1, 2].map((offset) => { const value = new Date().getFullYear() - offset; return <option key={value}>{value}</option>; })}</Select>} />
    {loading ? <Skeleton rows={8} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : <>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard icon={CalendarCheck} label="Lịch đã tạo" value={financial?.bookedCount ?? '—'} title={financial?.definitions?.bookedCount} />
        <MetricCard icon={WalletCards} label="Thực thu" value={money(financial?.grossCollected)} tone="info" title={financial?.definitions?.grossCollected} />
        <MetricCard icon={RotateCcw} label="Refund / reversal" value={money(financial?.refundAmount)} tone="warning" title={financial?.definitions?.netCollected} />
        <MetricCard icon={CircleDollarSign} label="Thu ròng" value={money(financial?.netCollected)} tone="success" title={financial?.definitions?.netCollected} />
      </div>
      <Card className="p-5"><div className="flex flex-wrap justify-between gap-3"><div><h2 className="font-bold">Đối chiếu ghi nhận</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">{financial?.definitions?.recognizedServiceRevenue}</p></div><strong>{money(financial?.recognizedServiceRevenue)}</strong></div><dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3"><div><dt className="text-[var(--bb-muted)]">Hoàn thành</dt><dd className="font-bold">{financial?.completedCount || 0}</dd></div><div><dt className="text-[var(--bb-muted)]">Đã hủy</dt><dd className="font-bold">{financial?.cancelledCount || 0}</dd></div><div><dt className="text-[var(--bb-muted)]">Còn phải thu</dt><dd className="font-bold">{money(financial?.outstandingAmount)}</dd></div></dl></Card>
      <Card className="p-5"><h2 className="font-bold">Thu ròng theo tháng</h2><div className="mt-5 h-72">{data.revenue.length ? <ResponsiveContainer width="100%" height="100%"><BarChart data={data.revenue}><CartesianGrid stroke="#e4e1dd" strokeDasharray="3 3" vertical={false} /><XAxis dataKey="month" /><YAxis /><Tooltip formatter={(value) => money(value)} /><Bar dataKey="netRevenue" name="Thu ròng" fill="#c0266d" radius={[6, 6, 0, 0]} /></BarChart></ResponsiveContainer> : <EmptyState title="Chưa có giao dịch đã xác minh" />}</div></Card>
      <Rank title="Nhu cầu theo dịch vụ" items={data.services} />
      <Card className="p-5"><div className="flex items-center gap-2"><BarChart3 size={18} /><h2 className="font-bold">Nguồn đối chiếu</h2></div><p className="mt-2 text-sm text-[var(--bb-muted)]">{financial?.drillDown?.bookingIds?.length || 0} booking · {financial?.drillDown?.paymentTransactionIds?.length || 0} giao dịch đã xác minh · {financial?.drillDown?.refundIds?.length || 0} refund.</p></Card>
    </>}
  </Page>;
}

function Rank({ title, items = [] }) {
  return <Card className="p-5"><h2 className="font-bold">{title}</h2>{!items.length ? <EmptyState title="Chưa có dữ liệu" /> : <ol className="mt-4 divide-y divide-[var(--bb-border)]">{items.map((item, index) => <li key={`${item.name}-${index}`} className="flex justify-between gap-3 py-3 text-sm"><span className="min-w-0 truncate"><b className="mr-2 text-[var(--bb-muted)]">{index + 1}</b>{item.name}</span><span className="flex gap-3"><strong>{item.bookedCount ?? item.value ?? 0} đặt</strong><span className="text-emerald-700">{item.completedCount || 0} hoàn thành</span></span></li>)}</ol>}</Card>;
}

export default SalonStats;
