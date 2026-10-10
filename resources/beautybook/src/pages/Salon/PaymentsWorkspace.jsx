import React, { useEffect, useMemo, useState } from 'react';
import toast from 'react-hot-toast';
import { CircleDollarSign, CreditCard, RefreshCcw } from 'lucide-react';
import { paymentsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { createIdempotencyKey } from '../../utils/idempotencyKey';
import PaymentCorePanels from './PaymentCorePanels';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Input, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';

const paymentTone = (status) => status === 'PAID' ? 'success' : status === 'FAILED' ? 'danger' : status?.includes('REFUND') ? 'warning' : 'info';

export default function PaymentsWorkspace() {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const [payments, setPayments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [bookingId, setBookingId] = useState('');
  const [method, setMethod] = useState('CASH');
  const [collectAmount, setCollectAmount] = useState('');
  const [busy, setBusy] = useState('');
  const [refund, setRefund] = useState(null);
  const [refundForm, setRefundForm] = useState({ amount: '', reason: '' });
  const [verification, setVerification] = useState(null);
  const [filters, setFilters] = useState({ search: '', status: 'ALL', method: 'ALL' });
  const [page, setPage] = useState(1);
  const pageSize = 20;

  const roles = useMemo(() => new Set([...(user?.roles || []), ...(user?.scopes || []).map((scope) => scope.code)]), [user]);
  const finance = roles.has('PLATFORM_ADMIN');
  const receptionist = roles.has('RECEPTIONIST') && !finance;
  const owner = roles.has('BUSINESS_OWNER');
  const pageMeta = finance
    ? { eyebrow: 'Tài chính nền tảng', title: 'Giao dịch & hoàn tiền', description: 'Theo dõi giao dịch, xét duyệt và xử lý hoàn tiền theo quyền nền tảng.' }
    : receptionist
      ? { eyebrow: 'Quầy lễ tân', title: 'Thu tiền tại quầy', description: 'Ghi nhận thanh toán cho lịch hẹn tại chi nhánh được cấp.' }
      : owner
        ? { eyebrow: 'Tài chính doanh nghiệp', title: 'Thanh toán toàn doanh nghiệp', description: 'Theo dõi thanh toán và hoàn tiền trên các chi nhánh thuộc doanh nghiệp.' }
        : { eyebrow: 'Tài chính chi nhánh', title: 'Thanh toán chi nhánh', description: 'Theo dõi giao dịch trong phạm vi chi nhánh được cấp.' };

  const canCollect = can('payment:create:branch') || can('payment:create:tenant');
  const canVerify = can('payment_transaction:verify:branch') || can('payment_transaction:verify:tenant');
  const canRequest = can('refund:create:tenant') || can('refund:create:platform');
  const canApprove = can('refund:approve:tenant') || can('refund:approve:platform');
  const canProcess = can('refund:process:platform');

  const load = async () => {
    setLoading(true); setError('');
    try { setPayments(await paymentsApi.getAll()); }
    catch (requestError) { setError(requestError.message); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);
  const filteredPayments = useMemo(() => {
    const term = filters.search.trim().toLocaleLowerCase('vi');
    return payments.filter((payment) => {
      const matchesSearch = !term || [payment.booking?.bookingCode, payment.bookingId, payment.id, payment.booking?.customer?.user?.fullName].some((value) => String(value || '').toLocaleLowerCase('vi').includes(term));
      return matchesSearch && (filters.status === 'ALL' || payment.status === filters.status) && (filters.method === 'ALL' || payment.method === filters.method);
    });
  }, [filters, payments]);
  useEffect(() => { setPage(1); }, [filters]);
  const totalPages = Math.max(1, Math.ceil(filteredPayments.length / pageSize));
  const visiblePayments = filteredPayments.slice((page - 1) * pageSize, page * pageSize);

  const collect = async (event) => {
    event.preventDefault(); setBusy('collect');
    try {
      await paymentsApi.collect(bookingId.trim(), method, {
        ...(collectAmount ? { amount: Number(collectAmount) } : {}),
        idempotencyKey: `WEB:${bookingId.trim()}:${method}:${createIdempotencyKey()}`,
      });
      toast.success(method === 'BANK_TRANSFER'
        ? 'Đã tạo giao dịch chờ xác minh; chưa cộng tiền vào lịch hẹn'
        : 'Đã xác minh và ghi nhận khoản thanh toán');
      setBookingId(''); setCollectAmount(''); await load();
    }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };
  const verify = async () => {
    if (!verification?.settlementReference?.trim()) return;
    setBusy(verification.id);
    try {
      await paymentsApi.verifyTransaction(
        verification.id,
        verification.settlementReference.trim(),
        { note: verification.note?.trim() || 'Đã đối chiếu chứng từ chuyển khoản tại cơ sở' },
      );
      toast.success('Đã xác minh giao dịch chuyển khoản');
      setVerification(null);
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };
  const requestRefund = async () => {
    const amount = Number(refundForm.amount); if (!amount || !refundForm.reason.trim()) return;
    setBusy('refund');
    try { await paymentsApi.requestRefund(refund.id, amount, refundForm.reason.trim()); toast.success('Đã tạo yêu cầu hoàn tiền'); setRefund(null); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };
  const review = async (id, approve) => {
    setBusy(id);
    try { await paymentsApi.reviewRefund(id, approve); toast.success(approve ? 'Đã duyệt hoàn tiền' : 'Đã từ chối hoàn tiền'); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };
  const process = async (id) => {
    setBusy(id);
    try { await paymentsApi.processRefund(id, { action: 'START' }); toast.success('Đã chuyển yêu cầu sang trạng thái đang xử lý; chưa ghi nhận hoàn tiền'); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };

  return <Page>
    <PageHeader {...pageMeta} />
    <PaymentCorePanels />
    {canCollect && <Card className="p-5"><form onSubmit={collect} className="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_220px_200px_auto]">
      <Field label="Mã lịch hẹn" required hint="Nhập mã nghiệp vụ hiển thị trên lịch, ví dụ BB-2026-01115."><Input required value={bookingId} onChange={(event) => setBookingId(event.target.value)} placeholder="BB-2026-01115" /></Field>
      <Field label="Phương thức" required><Select value={method} onChange={(event) => setMethod(event.target.value)}><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản chờ xác minh</option></Select></Field>
      <Field label="Số tiền" hint="Để trống để thu toàn bộ số dư; nhập số tiền để split payment."><Input type="number" min="1" step="1" value={collectAmount} onChange={(event) => setCollectAmount(event.target.value)} placeholder="Toàn bộ số dư" /></Field>
      <div className="flex items-end"><Button type="submit" loading={busy === 'collect'} className="w-full"><CircleDollarSign size={16} />Ghi nhận</Button></div>
    </form></Card>}
    <Card className="grid gap-3 p-4 md:grid-cols-[minmax(14rem,1fr)_14rem_14rem]"><Field label="Tìm giao dịch"><Input value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} placeholder="Mã lịch, ID giao dịch hoặc khách hàng" /></Field><Field label="Trạng thái"><Select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="ALL">Tất cả trạng thái</option>{[...new Set(payments.map((item) => item.status).filter(Boolean))].sort().map((status) => <option key={status} value={status}>{status}</option>)}</Select></Field><Field label="Phương thức"><Select value={filters.method} onChange={(event) => setFilters({ ...filters, method: event.target.value })}><option value="ALL">Tất cả phương thức</option>{[...new Set(payments.map((item) => item.method).filter(Boolean))].sort().map((paymentMethod) => <option key={paymentMethod} value={paymentMethod}>{paymentMethod}</option>)}</Select></Field></Card>
    {loading ? <Skeleton rows={7} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !visiblePayments.length ? <Card><EmptyState title="Không có giao dịch phù hợp" description={payments.length ? 'Thử thay đổi bộ lọc tìm kiếm.' : 'Các khoản thanh toán trong phạm vi sẽ xuất hiện tại đây.'} /></Card> : <div className="space-y-4">{visiblePayments.map((payment) => <Card as="article" key={payment.id} className="overflow-hidden">
      <header className="flex flex-col gap-3 border-b border-[var(--bb-border)] p-5 sm:flex-row sm:items-center"><span className="grid h-10 w-10 place-items-center rounded-lg bg-blue-50 text-blue-700"><CreditCard size={18} /></span><div className="min-w-0 flex-1"><h2 className="text-sm font-bold">{payment.booking?.bookingCode || payment.bookingId || '—'}</h2><p className="mt-1 text-xs text-[var(--bb-muted)]">{payment.method || '—'}</p></div><p className="text-lg font-bold tabular-nums">{Number(payment.amount || 0).toLocaleString('vi-VN')}₫</p><Badge tone={paymentTone(payment.status)}>{payment.status || '—'}</Badge>{canRequest && ['PAID', 'PARTIALLY_REFUNDED'].includes(payment.status) && <Button variant="secondary" size="sm" onClick={() => { setRefund(payment); setRefundForm({ amount: String(payment.amount || ''), reason: '' }); }}><RefreshCcw size={14} />Yêu cầu hoàn</Button>}</header>
      {(payment.refundRequests || []).length > 0 && <div className="divide-y divide-[var(--bb-border)]">{payment.refundRequests.map((item) => <div key={item.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:pl-16"><div className="min-w-0 flex-1"><p className="text-sm font-bold">Hoàn {Number(item.amount || 0).toLocaleString('vi-VN')}₫</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{item.reason || '—'}</p></div><Badge tone={item.status === 'APPROVED' ? 'success' : item.status === 'REJECTED' ? 'danger' : 'warning'}>{item.status}</Badge>{canApprove && item.status === 'PENDING' && <div className="flex gap-2"><Button size="sm" loading={busy === item.id} onClick={() => review(item.id, true)}>Duyệt</Button><Button variant="secondary" size="sm" disabled={busy === item.id} onClick={() => review(item.id, false)}>Từ chối</Button></div>}{canProcess && item.status === 'APPROVED' && <Button size="sm" loading={busy === item.id} onClick={() => process(item.id)}>Xử lý hoàn</Button>}</div>)}</div>}
      {(payment.transactions || []).some((item) => item.status === 'PENDING') && <div className="border-t border-[var(--bb-border)] p-4 sm:pl-16">{payment.transactions.filter((item) => item.status === 'PENDING').map((item) => <div key={item.id} className="flex flex-col gap-3 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="text-sm font-bold">Giao dịch chuyển khoản chờ xác minh</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{item.transactionRef || item.id}</p></div><Badge tone="warning">PENDING</Badge>{canVerify && <Button size="sm" onClick={() => setVerification({ id: item.id, settlementReference: '', note: '' })}>Xác minh chứng từ</Button>}</div>)}</div>}
    </Card>)}<Card as="footer" className="flex flex-col gap-3 p-4 text-sm sm:flex-row sm:items-center sm:justify-between"><p>Hiển thị {(page - 1) * pageSize + 1}–{Math.min(page * pageSize, filteredPayments.length)} trong {filteredPayments.length.toLocaleString('vi-VN')} giao dịch</p><div className="flex gap-2"><Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Trước</Button><Button size="sm" variant="secondary" disabled={page >= totalPages} onClick={() => setPage((value) => value + 1)}>Sau</Button></div></Card></div>}
    <Dialog open={Boolean(refund)} onClose={() => setRefund(null)} title="Tạo yêu cầu hoàn tiền" description="Yêu cầu đi qua quy trình xét duyệt; không tự động thay đổi payment." footer={<><Button variant="secondary" onClick={() => setRefund(null)}>Hủy</Button><Button loading={busy === 'refund'} disabled={!Number(refundForm.amount) || !refundForm.reason.trim()} onClick={requestRefund}>Gửi yêu cầu</Button></>}><div className="grid gap-4"><Field label="Số tiền hoàn" required><Input type="number" min="1" step="1" value={refundForm.amount} onChange={(event) => setRefundForm({ ...refundForm, amount: event.target.value })} /></Field><Field label="Lý do" required><Textarea value={refundForm.reason} onChange={(event) => setRefundForm({ ...refundForm, reason: event.target.value })} /></Field></div></Dialog>
    <Dialog open={Boolean(verification)} onClose={() => setVerification(null)} title="Xác minh chuyển khoản" description="Chỉ xác minh sau khi đã đối chiếu chứng từ và sao kê. Thao tác này sẽ cộng tiền vào ledger." footer={<><Button variant="secondary" onClick={() => setVerification(null)}>Hủy</Button><Button loading={busy === verification?.id} disabled={!verification?.settlementReference?.trim()} onClick={verify}>Xác minh giao dịch</Button></>}><div className="grid gap-4"><Field label="Mã đối soát" required><Input value={verification?.settlementReference || ''} onChange={(event) => setVerification((current) => ({ ...current, settlementReference: event.target.value }))} /></Field><Field label="Ghi chú chứng từ"><Textarea value={verification?.note || ''} onChange={(event) => setVerification((current) => ({ ...current, note: event.target.value }))} /></Field></div></Dialog>
  </Page>;
}
