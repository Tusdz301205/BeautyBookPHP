import React, { useEffect, useMemo, useState } from 'react';
import { CalendarPlus, Check, Copy, TicketPercent } from 'lucide-react';
import toast from 'react-hot-toast';
import { useNavigate } from 'react-router-dom';
import { vouchersApi } from '../../api/apiClient';
import { Button, Badge, Card, EmptyState, ErrorState, Page, PageHeader, Select, Skeleton } from '../../components/ui';
import { useBookingStore } from '../../store/bookingStore';

const STATUS = {
  ACTIVE: ['Có thể dùng', 'success'],
  USED: ['Đã dùng', 'neutral'],
  EXPIRED: ['Hết hạn', 'warning'],
  REVOKED: ['Đã thu hồi', 'danger'],
};

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const discount = (voucher) => voucher.discountType === 'PERCENTAGE' ? `${Number(voucher.discountValue)}%` : money(voucher.discountValue);

export function CustomerVouchers() {
  const navigate = useNavigate();
  const setVoucherCode = useBookingStore((state) => state.setVoucherCode);
  const [items, setItems] = useState([]);
  const [filter, setFilter] = useState('ALL');
  const [copied, setCopied] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const load = async () => {
    setLoading(true); setError('');
    try { setItems(await vouchersApi.getMine()); }
    catch (requestError) { setError(requestError.message); }
    finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);
  const visible = useMemo(() => filter === 'ALL' ? items : items.filter((item) => item.status === filter), [filter, items]);
  const copy = async (code) => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(code);
      toast.success('Đã sao chép mã voucher');
      window.setTimeout(() => setCopied(''), 1800);
    } catch { toast.error('Trình duyệt không cho phép sao chép tự động'); }
  };
  const useVoucher = (item) => {
    setVoucherCode(item.voucher.code);
    navigate('/book');
  };

  return <Page className="max-w-6xl">
    <PageHeader eyebrow="Quyền lợi của tôi" title="Voucher" description="Chỉ hiển thị voucher đã được cấp thật cho tài khoản này; điều kiện cuối cùng được kiểm tra lại khi xác nhận đặt lịch." actions={<Select aria-label="Lọc voucher" value={filter} onChange={(event) => setFilter(event.target.value)} className="min-w-44"><option value="ALL">Tất cả voucher</option><option value="ACTIVE">Có thể dùng</option><option value="USED">Đã dùng</option><option value="EXPIRED">Hết hạn</option><option value="REVOKED">Đã thu hồi</option></Select>} />
    {loading ? <Skeleton rows={5} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !visible.length ? <Card><EmptyState icon={TicketPercent} title="Chưa có voucher phù hợp" description={items.length ? 'Hãy đổi bộ lọc để xem các voucher khác.' : 'Voucher do BeautyBook hoặc cơ sở cấp sẽ xuất hiện tại đây.'} /></Card> : <div className="grid gap-4 md:grid-cols-2">{visible.map((item) => { const voucher = item.voucher; const [statusLabel, tone] = STATUS[item.status] || [item.status, 'neutral']; return <Card as="article" key={item.id} className="relative overflow-hidden p-5"><div className="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-[var(--bb-brand-soft)]" aria-hidden="true" /><div className="relative"><div className="flex items-start justify-between gap-3"><span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><TicketPercent size={20} /></span><Badge tone={tone}>{statusLabel}</Badge></div><p className="mt-5 text-3xl font-bold tracking-tight text-[var(--bb-brand-strong)]">Giảm {discount(voucher)}</p><h2 className="mt-2 font-bold">{voucher.name}</h2><p className="mt-1 text-sm leading-6 text-[var(--bb-muted)]">{voucher.description || `Áp dụng cho đơn từ ${money(voucher.minOrderValue)}.`}</p><dl className="mt-4 grid grid-cols-2 gap-3 text-xs"><div><dt className="text-[var(--bb-muted)]">Đơn tối thiểu</dt><dd className="mt-1 font-semibold">{money(voucher.minOrderValue)}</dd></div><div><dt className="text-[var(--bb-muted)]">Hạn sử dụng</dt><dd className="mt-1 font-semibold">{new Date(item.expiresAt).toLocaleDateString('vi-VN')}</dd></div><div className="col-span-2"><dt className="text-[var(--bb-muted)]">Phạm vi</dt><dd className="mt-1 font-semibold">{voucher.business?.name || 'Toàn nền tảng BeautyBook'}</dd></div></dl><div className="mt-5 flex flex-wrap gap-2 border-t border-[var(--bb-border)] pt-4"><Button variant="secondary" size="sm" onClick={() => copy(voucher.code)}>{copied === voucher.code ? <Check size={15} /> : <Copy size={15} />}<span className="bb-mono">{voucher.code}</span></Button>{item.isUsable && <Button size="sm" onClick={() => useVoucher(item)}><CalendarPlus size={15} />Dùng khi đặt lịch</Button>}</div></div></Card>; })}</div>}
  </Page>;
}

export default CustomerVouchers;
