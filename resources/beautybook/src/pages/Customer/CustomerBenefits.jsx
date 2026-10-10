import { useCallback, useEffect, useState } from 'react';
import { CalendarPlus, Heart, Trash2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { savedServicesApi } from '../../api/apiClient';
import { Badge, Button, Card, EmptyState, ErrorState, Page, PageHeader, Skeleton } from '../../components/ui';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;

export default function CustomerBenefits() {
  const [data, setData] = useState({ saved: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setData({ saved: (await savedServicesApi.list()) || [] });
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải dịch vụ đã lưu');
    }
    setLoading(false);
  }, []);

  useEffect(() => { void load(); }, [load]);

  const run = async (key, action, success) => {
    setBusy(key);
    try {
      await action();
      toast.success(success);
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  return <Page>
    <PageHeader eyebrow="Tiện ích cá nhân" title="Dịch vụ đã lưu" description="Xem lại các dịch vụ yêu thích của bạn." />
    {error ? <ErrorState message={error} onRetry={load} /> : loading ? <Card className="p-5"><Skeleton rows={8} /></Card> : <div className="space-y-7">

      <section><div className="mb-3 flex items-end justify-between gap-3"><div><h2 className="text-xl font-bold">Dịch vụ đã lưu</h2><p className="text-sm text-[var(--bb-muted)]">{data.saved.length} lựa chọn để xem lại sau.</p></div><Link className="text-sm font-semibold text-[var(--bb-brand-strong)]" to="/explore">Khám phá thêm</Link></div>{!data.saved.length ? <Card><EmptyState icon={Heart} title="Chưa lưu dịch vụ nào" description="Mở một dịch vụ và chọn Lưu để xem lại tại đây." /></Card> : <div className="grid gap-4 md:grid-cols-2">{data.saved.map((item) => <Card key={item.id} className="p-5"><div className="flex items-start gap-3"><span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><Heart size={19} fill="currentColor" /></span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h3 className="font-bold">{item.offering?.name || 'Dịch vụ không còn hiển thị'}</h3><Badge tone={item.available ? 'success' : 'warning'}>{item.available ? 'Đang nhận lịch' : 'Tạm không khả dụng'}</Badge></div><p className="mt-1 text-sm text-[var(--bb-muted)]">{item.offering?.branch?.name || 'Cơ sở đã cập nhật'} · {money(item.offering?.price)}</p></div></div><div className="mt-4 flex flex-wrap gap-2">{item.offering && <Link to={`/explore/services/${item.offering.id}`}><Button variant="secondary">Xem chi tiết</Button></Link>}{item.available && <Link to={`/book?branchId=${item.offering.branchId}&serviceId=${item.offering.id}`}><Button><CalendarPlus size={16} />Đặt lịch</Button></Link>}<Button variant="ghost" loading={busy === item.branchServiceOfferingId} onClick={() => run(item.branchServiceOfferingId, () => savedServicesApi.remove(item.branchServiceOfferingId), 'Đã bỏ lưu dịch vụ')}><Trash2 size={16} />Bỏ lưu</Button></div></Card>)}</div>}</section>
    </div>}
  </Page>;
}
