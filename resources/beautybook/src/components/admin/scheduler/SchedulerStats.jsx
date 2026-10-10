import { useCallback, useEffect, useMemo, useState } from 'react';
import { Ban, CalendarDays, CheckCircle2, RefreshCw, UserX } from 'lucide-react';
import { bookingsApi } from '../../../api/apiClient';
import { BOOKING_STATUSES, labelToEnum } from '../../../constants/status';
import { Button, Card, EmptyState, ErrorState, MetricCard, Skeleton } from '../../ui';

function Bars({ title, rows }) {
  const max = Math.max(...rows.map((row) => row.value), 1);
  return (
    <Card className="p-5">
      <h2 className="font-bold">{title}</h2>
      {rows.length === 0 ? (
        <EmptyState title="Chưa có dữ liệu" />
      ) : (
        <div className="mt-4 space-y-3">
          {rows.map((row) => (
            <div key={row.name}>
              <div className="flex justify-between gap-3 text-sm">
                <span className="truncate">{row.name}</span>
                <strong className="tabular-nums">{row.value}</strong>
              </div>
              <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-100">
                <div className="h-full rounded-full bg-pink-500" style={{ width: `${row.value / max * 100}%` }} />
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

export default function SchedulerStats({ branchId, branchIds = [] }) {
  const [data, setData] = useState({ stats: null, bookings: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    if (!branchId && branchIds.length === 0) return;
    setLoading(true);
    setError('');
    try {
      if (branchId) {
        const response = await bookingsApi.getByBranch(branchId);
        setData({ stats: response?.stats || null, bookings: response?.data || [] });
      } else {
        const [stats, response] = await Promise.all([
          bookingsApi.getStats(),
          bookingsApi.getAll({ limit: 100 }),
        ]);
        setData({ stats, bookings: response?.data || [] });
      }
    } catch (loadError) {
      setError(loadError.message || 'Không thể tải thống kê chi nhánh.');
    } finally {
      setLoading(false);
    }
  }, [branchId, branchIds.length]);

  useEffect(() => {
    load();
  }, [load]);

  const statusRows = useMemo(() => {
    const counts = data.bookings.reduce((result, booking) => {
      const code = labelToEnum(booking.status);
      result[code] = (result[code] || 0) + 1;
      return result;
    }, {});
    return Object.entries(counts).map(([code, value]) => ({
      name: BOOKING_STATUSES[code]?.label || code,
      value,
    }));
  }, [data.bookings]);

  const categoryRows = useMemo(() => {
    const counts = data.bookings.reduce((result, booking) => {
      const name = booking.service_category || 'Chưa phân loại';
      result[name] = (result[name] || 0) + 1;
      return result;
    }, {});
    return Object.entries(counts)
      .map(([name, value]) => ({ name, value }))
      .sort((a, b) => b.value - a.value);
  }, [data.bookings]);

  const stats = data.stats || {};
  return (
    <div className="h-full overflow-y-auto bg-[var(--bb-canvas)] p-4 sm:p-5">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h2 className="text-lg font-bold">Thống kê chi nhánh</h2>
          <p className="mt-1 text-sm text-[var(--bb-muted)]">
            Số liệu chỉ thuộc chi nhánh đang chọn, không trộn với dữ liệu toàn nền tảng.
          </p>
        </div>
        <Button variant="secondary" size="sm" onClick={load} loading={loading}>
          <RefreshCw size={15} />
          Tải lại
        </Button>
      </div>
      {loading ? (
        <Card className="p-5"><Skeleton rows={8} /></Card>
      ) : error ? (
        <Card><ErrorState message={error} onRetry={load} /></Card>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard icon={CalendarDays} label="Tổng lịch hẹn" value={stats.total ?? data.bookings.length} />
            <MetricCard icon={CheckCircle2} label="Tỷ lệ hoàn thành" value={`${Number(stats.completionRate || 0).toFixed(0)}%`} tone="success" />
            <MetricCard icon={Ban} label="Tỷ lệ hủy" value={`${Number(stats.cancellationRate || 0).toFixed(0)}%`} tone="warning" />
            <MetricCard icon={UserX} label="Tỷ lệ khách không đến" value={`${Number(stats.noShowRate || 0).toFixed(0)}%`} tone="neutral" />
          </div>
          {data.bookings.length ? (
            <div className="grid gap-4 lg:grid-cols-2">
              <Bars title="Phân bổ trạng thái" rows={statusRows} />
              <Bars title="Lịch theo nhóm dịch vụ" rows={categoryRows} />
            </div>
          ) : (
            <Card><EmptyState icon={CalendarDays} title="Chi nhánh chưa có lịch hẹn" /></Card>
          )}
        </div>
      )}
    </div>
  );
}
