import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Copy, RefreshCw, Wallet } from 'lucide-react';
import toast from 'react-hot-toast';
import { walletApi } from '../../api/apiClient';
import { createIdempotencyKey } from '../../utils/idempotencyKey';
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Skeleton } from '../../components/ui';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN') : '—');
const statusText = { PENDING: 'Chờ chuyển khoản', SUCCESS: 'Đã nhận', EXPIRED: 'Hết hạn', FAILED: 'Thất bại' };
const statusTone = { PENDING: 'warning', SUCCESS: 'success', EXPIRED: 'neutral', FAILED: 'danger' };
const kindText = { TOPUP: 'Nạp ví', HOLD: 'Giữ cọc đặt lịch', REFUND: 'Hoàn cọc về ví', RELEASE: 'Cọc chuyển cho cơ sở', SETTLEMENT: 'Thanh toán' };
const reviewText = {
  UNDERPAID: 'Số tiền nhận thấp hơn yêu cầu', OVERPAID: 'Số tiền nhận cao hơn yêu cầu', ADDITIONAL_BANK_PAYMENT: 'Khoản nạp này đã được thanh toán trước đó',
  BANK_TRANSACTION_TIME_MISMATCH: 'Thời điểm chuyển khoản không hợp lệ', INVOICE_EXPIRED_OR_CLOSED: 'Yêu cầu nạp đã hết hạn', TEST_LIMIT_EXCEEDED: 'Vượt hạn mức thử nghiệm',
};

function Invoice({ topUp, onChanged }) {
  const copy = (text) => navigator.clipboard?.writeText(text).then(() => toast.success('Đã sao chép'), () => toast.error('Không thể sao chép'));
  const receiver = topUp.receiverSnapshot || {};
  return <Card className="space-y-3 p-5">
    <div className="flex flex-wrap items-center gap-2"><h3 className="font-bold">Nạp {money(topUp.amountVnd)}</h3><Badge tone={statusTone[topUp.status] || 'neutral'}>{statusText[topUp.status] || topUp.status}</Badge><span className="text-xs text-[var(--bb-muted)]">Hết hạn {when(topUp.expiresAt)}</span></div>
    {topUp.status === 'PENDING' && <div className="grid gap-4 sm:grid-cols-[auto_1fr]">
      {topUp.qrUrl && <img src={topUp.qrUrl} alt="Mã QR chuyển khoản" className="h-44 w-44 rounded-lg border border-[var(--bb-border)] bg-white" />}
      <dl className="space-y-1 text-sm"><div><dt className="text-xs text-[var(--bb-muted)]">Ngân hàng</dt><dd>{receiver.bankCode} · {receiver.accountNumber} · {receiver.accountName}</dd></div>
        <div><dt className="text-xs text-[var(--bb-muted)]">Số tiền</dt><dd className="font-bold">{money(topUp.amountVnd)}</dd></div>
        <div><dt className="text-xs text-[var(--bb-muted)]">Nội dung chuyển khoản (bắt buộc đúng)</dt><dd className="flex items-center gap-2"><code className="rounded bg-[var(--bb-surface-subtle)] px-2 py-1 font-mono font-bold">{topUp.code}</code><button type="button" aria-label="Sao chép nội dung chuyển khoản" onClick={() => copy(topUp.code)}><Copy size={16} /></button></dd></div>
        <p className="pt-1 text-xs text-[var(--bb-muted)]">Ví chỉ được cộng khi ngân hàng báo có đúng số tiền và nội dung. Không bấm “đã chuyển” để cộng ví.</p></dl></div>}
    {topUp.receipts?.length > 0 && <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><b>Cần đối soát:</b> {topUp.receipts.map((receipt) => `${money(receipt.amountVnd)} — ${reviewText[receipt.reviewReason] || receipt.reviewReason}`).join('; ')}. Quản trị viên sẽ kiểm tra.</div>}
    {topUp.status === 'PENDING' && <Button size="sm" variant="secondary" onClick={onChanged}><RefreshCw size={14} />Kiểm tra lại</Button>}
  </Card>;
}

export default function CustomerWallet() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [amount, setAmount] = useState('50000');
  const [busy, setBusy] = useState(false);
  const attempt = useRef(null);
  const load = useCallback(async () => {
    setError('');
    try { setData(await walletApi.mine({ scope: 'LIVE_LAB' })); } catch (requestError) { setError(requestError.message || 'Không thể tải ví'); }
  }, []);
  useEffect(() => { void load(); }, [load]);
  const pending = data?.topUps?.some((item) => item.status === 'PENDING');
  // While an invoice waits for the bank, refresh so the balance appears as soon as the receipt is applied.
  useEffect(() => {
    if (!pending) return undefined;
    const timer = setInterval(() => { if (!document.hidden) void load(); }, 5000);
    return () => clearInterval(timer);
  }, [pending, load]);

  const create = async (event) => {
    event.preventDefault(); setBusy(true);
    // The key is reused for the same amount until the server answers, so a lost response cannot create a second invoice.
    if (!attempt.current || attempt.current.amount !== amount) attempt.current = { amount, key: `topup-${createIdempotencyKey()}`.replace(/[^A-Za-z0-9._:-]/g, '').padEnd(16, '0') };
    try { await walletApi.createTopUp(amount, attempt.current.key); attempt.current = null; toast.success('Đã tạo yêu cầu nạp ví'); await load(); }
    catch (requestError) { toast.error(requestError.message); } finally { setBusy(false); }
  };

  if (error) return <div className="mx-auto max-w-3xl p-4"><Card><ErrorState message={error} onRetry={load} /></Card></div>;
  if (!data) return <div className="mx-auto max-w-3xl p-4"><Skeleton rows={5} /></div>;
  return <div className="mx-auto max-w-3xl space-y-4 p-4">
    <header className="flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--bb-surface-subtle)] text-[var(--bb-brand-strong)]"><Wallet size={22} aria-hidden="true" /></span>
      <div><p className="text-xs font-semibold uppercase tracking-wide text-[var(--bb-brand-strong)]">BeautyBook · Ví Live lab</p><h1 className="text-2xl font-bold">Ví của tôi</h1></div></header>
    <div className="grid gap-3 sm:grid-cols-2"><Card className="p-4"><p className="text-xs text-[var(--bb-muted)]">Số dư khả dụng</p><p className="mt-1 text-2xl font-bold tabular-nums">{money(data.wallet.availableVnd)}</p></Card>
      <Card className="p-4"><p className="text-xs text-[var(--bb-muted)]">Cọc đang giữ</p><p className="mt-1 text-2xl font-bold tabular-nums">{money(data.wallet.heldVnd)}</p></Card></div>
    <p className="text-xs text-[var(--bb-muted)]">Ví thử nghiệm riêng: cọc 30% được giữ khi đặt lịch, hoàn về ví nếu lịch bị từ chối, hết hạn hoặc bạn hủy đúng hạn, và mất cọc nếu hủy sát giờ hoặc vắng mặt.</p>
    {!data.liveLabAvailable ? <Card><EmptyState title="Ví Live lab chưa mở cho tài khoản này" description={data.liveLabEligible ? 'Live lab đang tắt trên máy chủ.' : 'Tài khoản chưa nằm trong danh sách thử nghiệm.'} /></Card> :
      <Card className="p-5"><form onSubmit={create} className="grid gap-4 sm:grid-cols-[1fr_auto]"><Field label="Số tiền nạp (₫)" required hint={`Từ ${money(data.liveLabLimits?.minimum)} đến ${money(data.liveLabLimits?.maximum)}; tổng tối đa ${money(data.liveLabLimits?.total)}.`}>
        <Input required inputMode="numeric" pattern="[1-9][0-9]*" value={amount} onChange={(event) => setAmount(event.target.value)} /></Field><div className="flex items-end"><Button type="submit" loading={busy}>Tạo mã nạp ví</Button></div></form></Card>}
    {data.topUps.slice(0, 5).map((topUp) => <Invoice key={topUp.id} topUp={topUp} onChanged={load} />)}
    <Card className="overflow-x-auto"><h2 className="p-4 pb-0 font-bold">Lịch sử giao dịch ví</h2>{!data.history.length ? <EmptyState title="Chưa có giao dịch" description="Nạp ví hoặc đặt lịch có cọc để thấy lịch sử." /> :
      <table className="min-w-full text-left text-sm"><thead className="text-xs uppercase text-[var(--bb-muted)]"><tr><th className="p-4">Thời gian</th><th className="p-4">Loại</th><th className="p-4 text-right">Số tiền</th><th className="p-4 text-right">Khả dụng sau</th></tr></thead>
        <tbody>{data.history.map((row) => <tr key={row.id} className="border-t border-[var(--bb-border)]"><td className="p-4 whitespace-nowrap">{when(row.createdAt)}</td><td className="p-4">{kindText[row.kind] || row.kind}</td><td className="p-4 text-right tabular-nums">{money(row.amountVnd)}</td><td className="p-4 text-right tabular-nums">{money(row.availableAfterVnd)}</td></tr>)}</tbody></table>}</Card>
  </div>;
}
