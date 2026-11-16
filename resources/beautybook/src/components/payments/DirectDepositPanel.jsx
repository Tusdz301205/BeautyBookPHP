import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Banknote, Copy, Wallet } from 'lucide-react';
import toast from 'react-hot-toast';
import { paymentsApi } from '../../api/apiClient';
import { Badge, Button, Field, Input, InlineNotice } from '../ui';
import { DEPOSIT_STATUS, REFUND_REASON, REFUND_STATUS, maskAccount, minutesToPay, paymentInstructions, refundStep, storePaymentView } from '../../utils/directDeposit';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN') : '—');
const POLL_MS = 8000;

const walletStatus = {
  HELD: { label: 'Đang giữ cọc', tone: 'warning', description: 'Cọc đang được giữ trong ví và sẽ được tính vào giá dịch vụ khi hoàn tất lịch hẹn.' },
  APPLIED: { label: 'Đã tính vào hóa đơn', tone: 'success', description: 'Cọc đã được tính vào tiền dịch vụ.' },
  REFUNDED: { label: 'Đã hoàn về ví', tone: 'success', description: 'Cọc đã được hoàn về số dư khả dụng trong ví.' },
  FORFEITED: { label: 'Mất cọc', tone: 'danger', description: 'Cọc thuộc về cơ sở theo điều khoản hủy sát giờ hoặc vắng mặt.' },
};

function WalletDepositSummary({ deposit }) {
  const status = walletStatus[deposit.status] || { label: deposit.status, tone: 'neutral' };
  return <section className="rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface)] p-4" aria-label="Cọc đặt lịch từ ví">
    <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="flex items-center gap-2 text-sm font-bold"><Wallet size={16} aria-hidden="true" />Cọc 30% từ ví</h3><Badge tone={status.tone}>{status.label}</Badge></div>
    <dl className="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
      <div><dt className="text-xs text-[var(--bb-muted)]">Số tiền cọc</dt><dd className="font-bold tabular-nums">{money(deposit.amountVnd)}</dd></div>
      <div><dt className="text-xs text-[var(--bb-muted)]">Phần còn lại theo giá dịch vụ</dt><dd className="tabular-nums">{money(deposit.remainingVnd)}</dd></div>
      <div><dt className="text-xs text-[var(--bb-muted)]">Tổng giá dịch vụ</dt><dd className="tabular-nums">{money(deposit.totalVnd)}</dd></div>
    </dl>
    {status.description && <p className="mt-3 text-sm text-[var(--bb-muted)]">{status.description}</p>}
  </section>;
}

function CopyField({ label, value }) {
  const copy = async () => { try { await navigator.clipboard.writeText(String(value)); toast.success(`Đã sao chép ${label.toLowerCase()}`); } catch { toast.error('Không sao chép được, hãy chọn và sao chép thủ công'); } };
  return <div className="flex items-center justify-between gap-3 border-b border-[var(--bb-border)] py-2 last:border-0"><dt className="text-[var(--bb-muted)]">{label}</dt>
    <dd className="flex min-w-0 items-center gap-2 font-semibold"><span className="min-w-0 break-all text-right bb-mono">{value}</span><button type="button" onClick={copy} aria-label={`Sao chép ${label.toLowerCase()}`} className="grid h-10 w-10 shrink-0 place-items-center rounded-md border sm:h-8 sm:w-8 border-[var(--bb-border)] hover:bg-[var(--bb-surface-subtle)]"><Copy size={14} aria-hidden="true" /></button></dd></div>;
}

function RefundRow({ refund, role, bookingId, onChanged }) {
  const step = refundStep(refund, role);
  const [form, setForm] = useState({ bankCode: '', accountNumber: '', accountName: '', reference: '' });
  const [busy, setBusy] = useState(false);
  const run = async (action, body, done) => {
    setBusy(true);
    try { await paymentsApi.depositAction(bookingId, action, { version: refund.version, refundId: refund.id, ...body }); toast.success(done); await onChanged(); }
    catch (error) { toast.error(error.message || 'Không thể cập nhật khoản hoàn'); await onChanged(); } finally { setBusy(false); }
  };
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });
  return <li className="rounded-lg border border-[var(--bb-border)] p-3 text-sm">
    <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold">{money(refund.amountVnd)} · {REFUND_REASON[refund.reason] || refund.reason}</span><Badge tone={refund.status === 'CONFIRMED' ? 'success' : refund.status === 'REPORTED' ? 'info' : 'warning'}>{REFUND_STATUS[refund.status] || refund.status}</Badge></div>
    {refund.destination && <p className="mt-1 text-xs text-[var(--bb-muted)]">Chuyển trả về: {refund.destination.bankCode} · {maskAccount(refund.destination.accountNumber)} · {refund.destination.accountName}</p>}
    {refund.reference && <p className="mt-1 text-xs text-[var(--bb-muted)]">Mã giao dịch chuyển trả: {refund.reference}{refund.reportedAt ? ` · ${when(refund.reportedAt)}` : ''}</p>}
    {step.waitingFor === 'CUSTOMER' && <p className="mt-2 text-xs text-[var(--bb-muted)]">{role === 'CUSTOMER' ? '' : 'Đang chờ khách khai báo tài khoản nhận hoàn hoặc xác nhận đã nhận.'}</p>}
    {step.waitingFor === 'OWNER' && <p className="mt-2 text-xs text-[var(--bb-muted)]">Đang chờ chủ cơ sở chuyển khoản và ghi nhận mã giao dịch.</p>}
    {step.action === 'destination' && <form className="mt-3 grid gap-3 sm:grid-cols-3" onSubmit={(event) => { event.preventDefault(); void run('refund-destination', { bankCode: form.bankCode, accountNumber: form.accountNumber, accountName: form.accountName }, 'Đã gửi tài khoản nhận hoàn'); }}>
      <Field label="Ngân hàng" required><Input required value={form.bankCode} onChange={set('bankCode')} placeholder="Vietcombank" /></Field>
      <Field label="Số tài khoản" required><Input required value={form.accountNumber} onChange={set('accountNumber')} inputMode="numeric" /></Field>
      <Field label="Tên chủ tài khoản" required><Input required value={form.accountName} onChange={set('accountName')} /></Field>
      <div className="sm:col-span-3"><Button type="submit" size="sm" loading={busy}>Gửi tài khoản nhận hoàn</Button></div></form>}
    {step.action === 'report' && <form className="mt-3 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); void run('refund-report', { reference: form.reference }, 'Đã ghi nhận chuyển trả'); }}>
      <Field label="Mã giao dịch chuyển trả" required><Input required value={form.reference} onChange={set('reference')} /></Field><Button type="submit" size="sm" loading={busy}>Báo đã chuyển trả</Button></form>}
    {step.action === 'confirm' && <div className="mt-3"><Button size="sm" loading={busy} onClick={() => run('refund-confirm', {}, 'Cảm ơn bạn đã xác nhận')}>Tôi đã nhận đủ tiền</Button></div>}
  </li>;
}

/**
 * Direct deposits go to the business; historical wallet deposits retain their summary.
 * Poll only while a direct payment is awaited.
 */
export default function DirectDepositPanel({ bookingId, onChange }) {
  const [state, setState] = useState({ loading: true, data: null, error: '' });
  const [busy, setBusy] = useState(false);
  const [reference, setReference] = useState('');
  const [method, setMethod] = useState('CASH');
  const [, tick] = useState(0);
  const load = useCallback(async () => {
    try { setState({ loading: false, data: await paymentsApi.deposit(bookingId), error: '' }); }
    catch (error) { setState({ loading: false, data: null, error: error.status === 404 ? '' : (error.message || 'Không tải được thông tin cọc') }); }
  }, [bookingId]);
  useEffect(() => { void load(); }, [load]);
  const deposit = state.data?.deposit;
  const awaiting = deposit?.fundingSource === 'DIRECT_SEPAY' && deposit.status === 'AWAITING_PAYMENT';
  useEffect(() => {
    if (!awaiting) return undefined;
    const timer = setInterval(() => { tick((value) => value + 1); void load(); }, POLL_MS);
    return () => clearInterval(timer);
  }, [awaiting, load]);
  // Tell the page once when the awaited payment arrives (never on every render, never on first load).
  const previous = useRef(null);
  const notify = useRef(onChange);
  notify.current = onChange;
  useEffect(() => {
    const status = deposit?.status ?? null;
    if (previous.current === 'AWAITING_PAYMENT' && status && status !== 'AWAITING_PAYMENT') notify.current?.();
    previous.current = status;
  }, [deposit?.status]);

  if (state.loading) return null;
  if (state.error) return <InlineNotice tone="warning">{state.error}</InlineNotice>;
  if (deposit?.fundingSource === 'SIMULATED_WALLET') return <WalletDepositSummary deposit={deposit} />;
  if (!deposit || deposit.fundingSource !== 'DIRECT_SEPAY') return null;
  const role = state.data.role;
  const status = DEPOSIT_STATUS[deposit.status] || { label: deposit.status, tone: 'neutral' };
  const pay = paymentInstructions(deposit);
  const left = minutesToPay(deposit);
  const store = storePaymentView(state.data.payment, money);
  const recordStore = async () => {
    setBusy(true);
    try { await paymentsApi.depositAction(bookingId, 'store-receipt', { version: deposit.version, reference, method }); toast.success('Đã ghi nhận thu phần còn lại tại cơ sở'); setReference(''); await load(); notify.current?.(); }
    catch (error) { toast.error(error.message || 'Không thể ghi nhận'); await load(); } finally { setBusy(false); }
  };

  return <section className="rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface)] p-4" aria-label="Cọc chuyển khoản trực tiếp">
    <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="flex items-center gap-2 text-sm font-bold"><Banknote size={16} aria-hidden="true" />Cọc 30% chuyển khoản cho cơ sở</h3><Badge tone={status.tone}>{status.label}</Badge></div>
    <dl className="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
      <div><dt className="text-xs text-[var(--bb-muted)]">Cọc</dt><dd className="font-bold tabular-nums">{money(deposit.amountVnd)}</dd></div>
      <div><dt className="text-xs text-[var(--bb-muted)]">Trả tại cơ sở</dt><dd className="tabular-nums">{money(deposit.remainingVnd)}</dd></div>
      <div><dt className="text-xs text-[var(--bb-muted)]">Tổng giá dịch vụ</dt><dd className="tabular-nums">{money(deposit.totalVnd)}</dd></div></dl>

    {awaiting && role === 'CUSTOMER' && (pay ? <div className="mt-4 space-y-3">
      <InlineNotice tone={pay.testOnly ? 'warning' : 'info'}>{pay.testOnly ? 'Môi trường thử nghiệm: đây không phải tài khoản thật, không chuyển tiền thật. ' : ''}Chuyển đúng số tiền và đúng nội dung bên dưới. Còn {left} phút để thanh toán; trang sẽ tự cập nhật khi cơ sở nhận được tiền.</InlineNotice>
      <dl className="rounded-lg border border-[var(--bb-border)] px-3 text-sm"><CopyField label="Ngân hàng" value={pay.bank} /><CopyField label="Số tài khoản" value={pay.accountNumber} /><CopyField label="Chủ tài khoản" value={pay.accountName} /><CopyField label="Số tiền" value={String(pay.amountVnd)} /><CopyField label="Nội dung chuyển khoản" value={pay.content} /></dl>
      {pay.qrUrl && <img src={pay.qrUrl} alt="Mã QR chuyển khoản cọc" width="220" height="220" className="mx-auto rounded-lg border border-[var(--bb-border)]" referrerPolicy="no-referrer" />}</div>
      : <InlineNotice tone="warning">Đã hết hạn thanh toán cọc. Nếu bạn đã chuyển tiền, cơ sở sẽ hoàn lại khoản đó.</InlineNotice>)}
    {awaiting && role !== 'CUSTOMER' && <p className="mt-3 text-sm text-[var(--bb-muted)]">Đang chờ khách chuyển khoản cọc{left != null ? ` (còn ${left} phút)` : ''}. Chưa thể xác nhận lịch cho đến khi nhận cọc.</p>}
    {deposit.status === 'PAID' && <p className="mt-3 text-sm">Đã nhận cọc lúc {when(deposit.paidAt)}.</p>}
    {store?.hasPayments && <div className="mt-3 rounded-lg border border-[var(--bb-border)] p-3 text-sm" data-testid="store-payments">
      <h4 className="text-sm font-bold">Thu tại cơ sở</h4>
      <dl className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-3">
        <div><dt className="text-xs text-[var(--bb-muted)]">Đã thu</dt><dd className="font-bold tabular-nums">{store.collected}</dd></div>
        <div><dt className="text-xs text-[var(--bb-muted)]">Đang chờ xác minh</dt><dd className="tabular-nums">{store.pending || money(0)}</dd></div>
        <div><dt className="text-xs text-[var(--bb-muted)]">Còn có thể thu</dt><dd className="font-bold tabular-nums">{store.collectable}</dd></div></dl>
      {store.initialRemaining && <p className="mt-1 text-xs text-[var(--bb-muted)]">Phần còn lại theo điều khoản cọc khi đặt lịch: {store.initialRemaining}. Số còn phải trả hiện tại: {store.due}.</p>}
      {store.breakdown.length > 0 && <p className="mt-1 text-xs">Theo phương thức: {store.breakdown.join(' · ')}</p>}
      <ul className="mt-2 space-y-1">{store.entries.map((entry) => <li key={entry.key} className={`flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-[var(--bb-border)] pt-1 text-xs ${entry.reversed ? 'text-[var(--bb-muted)] line-through' : ''}`}>
        <span className="font-semibold">{entry.amount}</span><span>{entry.method}</span>{entry.reference && <span>tham chiếu {entry.reference}</span>}{entry.by && <span>bởi {entry.by}</span>}{entry.at && <span>{when(entry.at)}</span>}
        {entry.reversed && <Badge tone="neutral">Đã đảo</Badge>}{entry.pending && <Badge tone="warning">Chờ xác minh</Badge>}{entry.refunded && <Badge tone="info">Đã hoàn {entry.refunded}</Badge>}{entry.legacy && <Badge tone="neutral">Ghi nhận cũ</Badge>}</li>)}</ul></div>}
    {state.data.canRecordStore && <form className="mt-3 flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); void recordStore(); }}>
      <Field label="Phương thức thu"><select value={method} onChange={(event) => setMethod(event.target.value)} className="h-11 rounded-lg border border-[var(--bb-border)] bg-[var(--bb-surface)] px-3 text-sm"><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản</option></select></Field>
      <Field label={store ? `Tham chiếu thu ${store.collectable}` : 'Tham chiếu thu phần còn lại'} required><Input required value={reference} onChange={(event) => setReference(event.target.value)} placeholder={method === 'CASH' ? 'Số phiếu thu / ghi chú' : 'Mã giao dịch ngân hàng'} /></Field><Button type="submit" size="sm" loading={busy}>Ghi nhận đã thu tại cơ sở</Button></form>}
    {deposit.refunds.length > 0 && <div className="mt-4"><h4 className="text-sm font-bold">Hoàn lại tiền</h4><ul className="mt-2 space-y-2">{deposit.refunds.map((refund) => <RefundRow key={refund.id} refund={refund} role={role} bookingId={bookingId} onChanged={load} />)}</ul></div>}
  </section>;
}
