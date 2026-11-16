import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Copy, RefreshCw, Wallet } from 'lucide-react';
import toast from 'react-hot-toast';
import { walletApi } from '../../api/apiClient';
import '../../styles/customer-wallet.css';
import { createIdempotencyKey } from '../../utils/idempotencyKey';
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Skeleton } from '../../components/ui';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN') : '—');
const statusText = { PENDING: 'Chờ chuyển khoản', SUCCESS: 'Đã nhận', EXPIRED: 'Hết hạn', FAILED: 'Thất bại' };
const statusTone = { PENDING: 'warning', SUCCESS: 'success', EXPIRED: 'neutral', FAILED: 'danger' };
const kindText = { TOPUP: 'Nạp ví', HOLD: 'Giữ cọc đặt lịch', REFUND: 'Hoàn cọc về ví', RELEASE: 'Cọc ghi nhận cho cơ sở', SETTLEMENT: 'Chi trả cho cơ sở' };
const reviewText = {
  UNDERPAID: 'Số tiền nhận thấp hơn yêu cầu', OVERPAID: 'Số tiền nhận cao hơn yêu cầu', ADDITIONAL_BANK_PAYMENT: 'Khoản nạp này đã được thanh toán trước đó',
  BANK_TRANSACTION_TIME_MISMATCH: 'Thời điểm chuyển khoản không hợp lệ', INVOICE_EXPIRED_OR_CLOSED: 'Yêu cầu nạp đã hết hạn', TEST_LIMIT_EXCEEDED: 'Vượt hạn mức nạp ví',
};

function Invoice({ topUp, onChanged }) {
  const copy = async (text) => { try { if (!navigator.clipboard) throw new Error(); await navigator.clipboard.writeText(String(text)); toast.success('Đã sao chép'); } catch { toast.error('Không thể sao chép. Bạn có thể chọn và sao chép thông tin bên dưới.'); } };
  const receiver = topUp.receiverSnapshot || {};
  return <Card className="space-y-3 p-5">
    <div className="flex flex-wrap items-center gap-2"><h3 className="font-bold">Nạp {money(topUp.amountVnd)}</h3><Badge tone={statusTone[topUp.status] || 'neutral'}>{statusText[topUp.status] || topUp.status}</Badge><span className="text-xs text-[var(--bb-muted)]">Hết hạn {when(topUp.expiresAt)}</span></div>
    {topUp.status === 'PENDING' && <div className="bb-wallet-transfer">
      {topUp.qrUrl && <img src={topUp.qrUrl} alt="Mã QR chuyển khoản nạp ví" className="bb-wallet-qr" />}
      <div className="min-w-0"><h4 className="mb-3 font-bold">Thông tin chuyển khoản</h4><dl className="bb-wallet-receiver">
        <div><dt>Ngân hàng</dt><dd>{receiver.bankCode}</dd></div>
        <div><dt>Chủ tài khoản</dt><dd>{receiver.accountName}</dd></div>
        <div><dt>Số tài khoản</dt><dd>{receiver.accountNumber}<button type="button" aria-label="Sao chép số tài khoản" onClick={() => copy(receiver.accountNumber)}><Copy size={16} /></button></dd></div>
        <div><dt>Số tiền</dt><dd>{money(topUp.amountVnd)}<button type="button" aria-label="Sao chép số tiền" onClick={() => copy(topUp.amountVnd)}><Copy size={16} /></button></dd></div>
        <div><dt>Nội dung chuyển khoản</dt><dd><code>{topUp.code}</code><button type="button" aria-label="Sao chép nội dung chuyển khoản" onClick={() => copy(topUp.code)}><Copy size={16} /></button></dd></div>
      </dl><p className="mt-3 text-xs text-[var(--bb-muted)]">Giữ nguyên số tiền và nội dung chuyển khoản.</p></div>
    </div>}
    {topUp.status === 'SUCCESS' && <p role="status" className="text-sm text-[var(--bb-success)]">Đã cộng {money(topUp.amountVnd)} vào ví.</p>}
    {topUp.status === 'EXPIRED' && <p className="text-sm text-[var(--bb-muted)]">Mã này đã hết hạn. Hãy tạo mã nạp mới trước khi chuyển tiền.</p>}
    {topUp.receipts?.length > 0 && <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><b>Giao dịch đang được kiểm tra:</b> {topUp.receipts.map((receipt) => `${money(receipt.amountVnd)} — ${reviewText[receipt.reviewReason] || receipt.reviewReason}`).join('; ')}. Quản trị viên sẽ kiểm tra.</div>}
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
    event.preventDefault(); if (busy) return; setBusy(true);
    // The key is reused for the same amount until the server answers, so a lost response cannot create a second invoice.
    if (!attempt.current || attempt.current.amount !== amount) attempt.current = { amount, key: `topup-${createIdempotencyKey()}`.replace(/[^A-Za-z0-9._:-]/g, '').padEnd(16, '0') };
    try { await walletApi.createTopUp(amount, attempt.current.key); attempt.current = null; toast.success('Đã tạo yêu cầu nạp ví'); await load(); }
    catch (requestError) { toast.error(requestError.message); } finally { setBusy(false); }
  };

  if (error) return <div className="mx-auto max-w-3xl p-4"><Card><ErrorState message={error} onRetry={load} /></Card></div>;
  if (!data) return <div className="mx-auto max-w-3xl p-4"><Skeleton rows={5} /></div>;
  const topUpAvailable = data.topUpAvailable ?? data.liveLabAvailable;
  const topUpLimits = data.topUpLimits ?? data.liveLabLimits;
  const amountValid = /^[1-9][0-9]*$/.test(amount) && (!topUpLimits || (Number(amount) >= Number(topUpLimits.minimum) && Number(amount) <= Number(topUpLimits.maximum)));
  return <div className="bb-customer-wallet mx-auto max-w-3xl space-y-4 p-4">
    <header className="flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--bb-surface-subtle)] text-[var(--bb-brand-strong)]"><Wallet size={22} aria-hidden="true" /></span>
      <div><h1 className="text-2xl font-bold">Ví của tôi</h1></div></header>
    <div className="grid gap-3 sm:grid-cols-2"><Card className="p-4"><p className="text-xs text-[var(--bb-muted)]">Số dư khả dụng</p><p className="mt-1 text-2xl font-bold tabular-nums">{money(data.wallet.availableVnd)}</p></Card>
      <Card className="p-4"><p className="text-xs text-[var(--bb-muted)]">Cọc đang giữ</p><p className="mt-1 text-2xl font-bold tabular-nums">{money(data.wallet.heldVnd)}</p></Card></div>
    <details className="bb-wallet-policy"><summary>Chính sách tiền cọc</summary><p className="text-xs text-[var(--bb-muted)]">Cọc 30% được giữ từ ví khi đặt lịch và tính vào giá dịch vụ. Cọc hoàn về ví nếu lịch bị từ chối, hết hạn hoặc bạn hủy đúng hạn; mất cọc nếu hủy sát giờ hoặc vắng mặt.</p></details>
    {!topUpAvailable ? <Card><EmptyState title="Nạp tiền chưa sẵn sàng" description="Hệ thống chưa kết nối tài khoản nhận tiền chung. Bạn vẫn có thể dùng số dư hiện có để đặt lịch." /></Card> :
      <Card className="p-5"><h2 className="font-bold">Nạp tiền vào ví</h2>
        <form onSubmit={create} className="mt-4 space-y-3">
          <div className="bb-wallet-amounts" role="group" aria-label="Chọn nhanh số tiền nạp">{[2000, 10000, 50000, 100000, 200000, 500000].filter(value => !topUpLimits || (value >= Number(topUpLimits.minimum) && value <= Number(topUpLimits.maximum))).map(value => <Button key={value} type="button" variant={amount === String(value) ? 'primary' : 'secondary'} aria-pressed={amount === String(value)} disabled={busy} onClick={() => setAmount(String(value))}>{money(value)}</Button>)}</div>
          <div className="bb-wallet-topup-form"><Field label="Số tiền nạp (₫)" required hint={topUpLimits ? `Từ ${money(topUpLimits.minimum)} đến ${money(topUpLimits.maximum)}.` : undefined}>
            <Input required disabled={busy} inputMode="numeric" pattern="[1-9][0-9]*" value={amount} onChange={(event) => setAmount(event.target.value)} /></Field>
            <Button type="submit" loading={busy} disabled={!amountValid}>Tạo mã nạp ví</Button></div>
        </form></Card>}
    {data.topUps.slice(0, 5).map((topUp) => <Invoice key={topUp.id} topUp={topUp} onChanged={load} />)}
    <Card className="overflow-x-auto"><h2 className="p-4 pb-0 font-bold">Lịch sử giao dịch ví</h2>{!data.history.length ? <EmptyState title="Chưa có giao dịch" /> :
      <table className="min-w-full text-left text-sm"><thead className="text-xs uppercase text-[var(--bb-muted)]"><tr><th className="p-4">Thời gian</th><th className="p-4">Loại</th><th className="p-4 text-right">Số tiền</th><th className="p-4 text-right">Khả dụng sau</th></tr></thead>
        <tbody>{data.history.map((row) => <tr key={row.id} className="border-t border-[var(--bb-border)]"><td className="p-4 whitespace-nowrap">{when(row.createdAt)}</td><td className="p-4">{kindText[row.kind] || row.kind}</td><td className="p-4 text-right tabular-nums">{money(row.amountVnd)}</td><td className="p-4 text-right tabular-nums">{money(row.availableAfterVnd)}</td></tr>)}</tbody></table>}</Card>
  </div>;
}
