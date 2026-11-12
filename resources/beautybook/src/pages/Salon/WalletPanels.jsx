import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Banknote, Landmark, PlugZap, ReceiptText, Wallet } from 'lucide-react';
import toast from 'react-hot-toast';
import { paymentsApi, walletApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { createIdempotencyKey } from '../../utils/idempotencyKey';
import { RECEIPT_PROCESSING, REFUND_STATUS_LABEL, pageInfo } from '../../utils/depositAdmin';
import { REFUND_REASON } from '../../utils/directDeposit';
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Skeleton, cx } from '../../components/ui';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN') : '—');
const day = (value) => (value ? new Date(value).toLocaleDateString('vi-VN') : '—');
const reviewText = {
  MISSING_OR_AMBIGUOUS_CODE: 'Thiếu hoặc nhiều mã nạp ví trong nội dung chuyển khoản',
  INVOICE_NOT_FOUND: 'Không có yêu cầu nạp ví khớp mã',
  UNDERPAID: 'Số tiền nhận thấp hơn yêu cầu nạp',
  OVERPAID: 'Số tiền nhận cao hơn yêu cầu nạp',
  ADDITIONAL_BANK_PAYMENT: 'Yêu cầu nạp này đã được thanh toán trước đó',
  BANK_TRANSACTION_TIME_MISMATCH: 'Thời điểm chuyển khoản không hợp lệ',
  INVOICE_EXPIRED_OR_CLOSED: 'Yêu cầu nạp đã hết hạn hoặc đã đóng',
  RECEIVING_ACCOUNT_MISMATCH: 'Sai tài khoản nhận',
  INVOICE_RECEIVER_MISMATCH: 'Tài khoản nhận khác thông tin trên yêu cầu',
  TEST_ACCOUNT_NOT_AUTHORIZED: 'Tài khoản không thuộc danh sách thử nghiệm',
  TEST_LIMIT_EXCEEDED: 'Vượt hạn mức thử nghiệm',
  LIVE_LAB_DISABLED: 'Live lab đang tắt',
  OUTGOING_TRANSFER: 'Giao dịch tiền ra',
  POSTING_NOT_APPLIED: 'Chưa ghi sổ được',
};
const accountText = { PENDING: 'Chờ xác minh', VERIFIED_DEMO: 'Đã xác minh (demo)', DISABLED: 'Đã tắt' };
const accountTone = { PENDING: 'warning', VERIFIED_DEMO: 'success', DISABLED: 'neutral' };
const withdrawalTone = { PENDING_APPROVAL: 'warning', PROCESSING: 'info', FAILED: 'danger', SIMULATED_COMPLETED: 'success', REJECTED: 'danger', CANCELLED: 'neutral' };
const withdrawalText = { PENDING_APPROVAL: 'Chờ duyệt', PROCESSING: 'Đang xử lý', FAILED: 'Thất bại', SIMULATED_COMPLETED: 'Hoàn tất (mô phỏng)', REJECTED: 'Đã từ chối', CANCELLED: 'Đã hủy' };
const emptyBank = { bankCode: '', bankName: '', accountNumber: '', accountName: '' };
const settlementText = { PENDING: 'Chờ hoàn tất', SIMULATED_PAID: 'Đã hoàn tất (mô phỏng)' };
const settlementTone = { PENDING: 'warning', SIMULATED_PAID: 'success' };
const isoDay = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);

export default function WalletPanels() {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const businessId = useMemo(() => user?.scopes?.find((scope) => scope.businessId)?.businessId || '', [user]);
  const isAdmin = can('platform_statement:manage:platform');
  const isOwner = can('payment_policy:manage:tenant');
  const tabs = useMemo(() => [
    { id: 'overview', label: isAdmin ? 'Đối soát ví' : 'Ví & khoản phải trả', icon: Wallet, visible: isAdmin || isOwner },
    { id: 'accounts', label: 'Tài khoản ngân hàng', icon: Landmark, visible: isAdmin || isOwner },
    { id: 'payouts', label: 'Rút tiền', icon: Banknote, visible: isAdmin || isOwner },
    { id: 'settlements', label: 'Đối soát lô', icon: ReceiptText, visible: isAdmin || isOwner },
    { id: 'direct', label: 'Nhận cọc trực tiếp', icon: PlugZap, visible: isOwner && !isAdmin },
    { id: 'depositAdmin', label: 'Hoàn cọc & chuyển khoản', icon: PlugZap, visible: isAdmin },
  ].filter((item) => item.visible), [isAdmin, isOwner]);
  const [tab, setTab] = useState(tabs[0]?.id || '');
  useEffect(() => { if (!tabs.some((item) => item.id === tab)) setTab(tabs[0]?.id || ''); }, [tab, tabs]);
  if (!tabs.length) return null;
  return <section className="space-y-4" aria-labelledby="wallet-panels-title">
    <div><h2 id="wallet-panels-title" className="text-xl font-bold">Ví, cọc và rút tiền</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">Cọc 30% giữ từ ví khách khi đặt lịch; hoàn tất lịch chuyển cọc thành khoản phải trả. Rút tiền chạy qua nhà cung cấp mô phỏng MOCK, không chuyển tiền thật.</p></div>
    <div className="flex gap-1 overflow-x-auto rounded-xl border border-[var(--bb-border)] bg-white p-1" role="tablist">
      {tabs.map(({ id, label, icon: Icon }) => <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={cx('inline-flex min-h-11 shrink-0 items-center gap-2 rounded-lg px-4 text-sm font-semibold', tab === id ? 'bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)] hover:bg-[var(--bb-surface-subtle)]')}><Icon size={16} aria-hidden="true" />{label}</button>)}
    </div>
    {isAdmin ? <>{tab === 'overview' && <AdminReview />}{tab === 'accounts' && <AdminAccounts />}{tab === 'payouts' && <AdminPayouts />}{tab === 'settlements' && <AdminSettlements />}{tab === 'depositAdmin' && <AdminDirectDeposits />}</>
      : <>{tab === 'overview' && <OwnerOverview businessId={businessId} />}{tab === 'accounts' && <OwnerAccounts businessId={businessId} />}{tab === 'payouts' && <OwnerPayouts businessId={businessId} />}{tab === 'settlements' && <OwnerSettlements businessId={businessId} />}{tab === 'direct' && <OwnerDirectDeposits businessId={businessId} />}</>}
  </section>;
}

function Balances({ balances, held }) {
  const cards = [['Có thể rút', balances?.available], ['Đang giữ cho yêu cầu', balances?.locked], ['Đã rút (mô phỏng)', balances?.completed], ...(held !== undefined ? [['Cọc đang giữ (chưa hoàn tất)', held]] : [])];
  return <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map(([label, value]) => <Card key={label} className="p-4"><p className="text-xs text-[var(--bb-muted)]">{label}</p><p className="mt-1 text-xl font-bold tabular-nums">{money(value)}</p></Card>)}</div>;
}

function useBusiness(businessId) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const load = useCallback(async () => {
    if (!businessId) return;
    setError('');
    try { setData(await walletApi.business(businessId)); } catch (requestError) { setError(requestError.message || 'Không thể tải dữ liệu ví'); }
  }, [businessId]);
  useEffect(() => { void load(); }, [load]);
  return { data, error, load };
}

const NoBusiness = () => <Card><EmptyState title="Cần chọn doanh nghiệp" description="Đăng nhập bằng tài khoản chủ doanh nghiệp để xem ví và rút tiền." /></Card>;

function OwnerOverview({ businessId }) {
  const { data, error, load } = useBusiness(businessId);
  if (!businessId) return <NoBusiness />;
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={4} />;
  return <div className="space-y-4">
    <Balances balances={data.withdrawalBalances} held={data.heldVnd} />
    {!data.walletMode && <Card className="p-4 text-sm text-[var(--bb-muted)]">Chế độ ví chưa bật trên máy chủ nên lịch hẹn mới không giữ cọc.</Card>}
    {!data.payables.length ? <Card><EmptyState title="Chưa có khoản phải trả" description="Khoản cọc trở thành khoản phải trả khi lịch hẹn hoàn tất hoặc khách bị mất cọc." /></Card> :
      <Card className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase text-[var(--bb-muted)]"><tr><th className="p-4">Lịch hẹn</th><th className="p-4">Lý do</th><th className="p-4">Cọc</th><th className="p-4 text-right">Số tiền</th><th className="p-4 text-right">Đã giữ cho yêu cầu</th></tr></thead>
        <tbody>{data.payables.map((row) => <tr key={row.id} className="border-t border-[var(--bb-border)]"><td className="p-4 font-mono text-xs">{row.bookingCode}</td><td className="p-4">{row.reason === 'CUSTOMER_FORFEITURE' ? 'Khách mất cọc' : 'Hoàn tất dịch vụ'}</td><td className="p-4"><Badge tone={row.depositStatus === 'APPLIED' ? 'success' : 'warning'}>{row.depositStatus}</Badge></td><td className="p-4 text-right tabular-nums">{money(row.amountVnd)}</td><td className="p-4 text-right tabular-nums">{money(row.allocatedVnd)}</td></tr>)}</tbody></table></Card>}
  </div>;
}

function AccountRow({ account, children }) {
  return <Card className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="font-bold">{account.bankName} · {account.accountNumberMasked}</p><p className="text-xs text-[var(--bb-muted)]">{account.accountName}{account.businessName ? ` · ${account.businessName}` : ''}</p></div>
    <div className="flex flex-wrap items-center gap-2"><Badge tone={accountTone[account.status] || 'neutral'}>{accountText[account.status] || account.status}</Badge>{account.isDefault && <Badge tone="info">Mặc định</Badge>}{children}</div></Card>;
}

function BankForm({ value, onChange, onSubmit, busy, label }) {
  return <form onSubmit={onSubmit} className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
    <Field label="Mã ngân hàng" required><Input required value={value.bankCode} onChange={(event) => onChange({ ...value, bankCode: event.target.value })} placeholder="ACB" /></Field>
    <Field label="Tên ngân hàng" required><Input required value={value.bankName} onChange={(event) => onChange({ ...value, bankName: event.target.value })} /></Field>
    <Field label="Số tài khoản" required><Input required inputMode="numeric" pattern="[A-Za-z0-9_-]{3,64}" value={value.accountNumber} onChange={(event) => onChange({ ...value, accountNumber: event.target.value })} /></Field>
    <Field label="Tên chủ tài khoản" required><Input required value={value.accountName} onChange={(event) => onChange({ ...value, accountName: event.target.value })} /></Field>
    <div className="flex items-end"><Button type="submit" loading={busy}>{label}</Button></div>
  </form>;
}

function OwnerAccounts({ businessId }) {
  const { data, error, load } = useBusiness(businessId);
  const [bank, setBank] = useState(emptyBank);
  const [replacing, setReplacing] = useState(null);
  const [busy, setBusy] = useState(false);
  if (!businessId) return <NoBusiness />;
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={3} />;
  const submit = async (event) => {
    event.preventDefault(); setBusy(true);
    try {
      if (replacing) await walletApi.replaceAccount(businessId, replacing.id, { version: replacing.version, ...bank });
      else await walletApi.addAccount(businessId, bank);
      toast.success('Đã gửi tài khoản, chờ quản trị viên xác minh'); setBank(emptyBank); setReplacing(null); await load();
    } catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(false); }
  };
  return <div className="space-y-4">
    <Card className="p-5"><h3 className="mb-3 font-bold">{replacing ? `Thay thế tài khoản ${replacing.accountNumberMasked}` : 'Gửi tài khoản nhận tiền'}</h3>
      <BankForm value={bank} onChange={setBank} onSubmit={submit} busy={busy} label={replacing ? 'Gửi tài khoản thay thế' : 'Gửi xác minh'} />
      {replacing && <Button className="mt-3" variant="secondary" size="sm" onClick={() => { setReplacing(null); setBank(emptyBank); }}>Hủy thay thế</Button>}
      <p className="mt-3 text-xs text-[var(--bb-muted)]">Tài khoản chỉ nhận được tiền sau khi quản trị viên xác minh. Tài khoản cũ vẫn dùng được cho đến khi tài khoản thay thế được xác minh.</p></Card>
    {!data.accounts.length ? <Card><EmptyState title="Chưa có tài khoản ngân hàng" description="Gửi tài khoản để quản trị viên xác minh trước khi rút tiền." /></Card> : data.accounts.map((account) => <AccountRow key={account.id} account={account}>{account.status === 'VERIFIED_DEMO' && <Button size="sm" variant="secondary" onClick={() => { setReplacing(account); setBank(emptyBank); }}>Thay thế</Button>}</AccountRow>)}
  </div>;
}

function WithdrawalCard({ item, actions }) {
  return <Card className="p-5"><div className="flex flex-col gap-3 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><h3 className="font-bold">{money(item.amountVnd)} <span className="text-xs font-normal text-[var(--bb-muted)]">· #{item.id}{item.businessName ? ` · ${item.businessName}` : ''}</span></h3>
    <p className="text-xs text-[var(--bb-muted)]">{item.bankSnapshot?.bankName} · …{String(item.bankSnapshot?.accountNumber || '').slice(-4)} · {item.bankSnapshot?.accountName} · {when(item.requestedAt)}</p><p className="mt-1 text-xs">{item.reason}</p></div>
    <Badge tone={withdrawalTone[item.status] || 'neutral'}>{withdrawalText[item.status] || item.status}</Badge>{actions}</div></Card>;
}

function OwnerPayouts({ businessId }) {
  const [data, setData] = useState(null);
  const [summary, setSummary] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [form, setForm] = useState({ amountVnd: '', accountId: '' });
  const [attempt, setAttempt] = useState(null);
  const load = useCallback(async () => {
    if (!businessId) return;
    setError('');
    try {
      const [list, business] = await Promise.all([walletApi.withdrawals(businessId), walletApi.business(businessId)]);
      setData(list); setSummary(business);
    } catch (requestError) { setError(requestError.message || 'Không thể tải dữ liệu rút tiền'); }
  }, [businessId]);
  useEffect(() => { void load(); }, [load]);
  if (!businessId) return <NoBusiness />;
  const verified = (summary?.accounts || []).filter((account) => account.status === 'VERIFIED_DEMO');
  const request = async (event) => {
    event.preventDefault(); setBusy('request');
    // The same key is reused until the server answers, so a retry after a lost response cannot create a second request.
    const signature = `${form.amountVnd}|${form.accountId}`;
    const next = attempt?.sig === signature ? attempt : { sig: signature, key: `wd-${createIdempotencyKey()}`.replace(/[^A-Za-z0-9._:-]/g, '').padEnd(16, '0') };
    setAttempt(next);
    try { await walletApi.requestWithdrawal(businessId, { amountVnd: String(form.amountVnd), accountId: Number(form.accountId) }, next.key); toast.success('Đã gửi yêu cầu rút tiền, chờ quản trị viên duyệt'); setAttempt(null); setForm({ ...form, amountVnd: '' }); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  const cancel = async (item) => {
    const reason = window.prompt('Lý do hủy yêu cầu?'); if (!reason) return;
    setBusy(item.id);
    try { await walletApi.cancelWithdrawal(businessId, item.id, { version: item.version, reason }); toast.success('Đã hủy yêu cầu'); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data || !summary) return <Skeleton rows={4} />;
  return <div className="space-y-4">
    <Balances balances={data.balances} />
    <Card className="p-5"><form onSubmit={request} className="grid gap-4 md:grid-cols-[1fr_1fr_auto]">
      <Field label="Số tiền (₫)" required><Input required inputMode="numeric" pattern="[1-9][0-9]*" value={form.amountVnd} onChange={(event) => setForm({ ...form, amountVnd: event.target.value })} /></Field>
      <Field label="Tài khoản nhận (đã xác minh)" required><Select required value={form.accountId} onChange={(event) => setForm({ ...form, accountId: event.target.value })}><option value="">{verified.length ? 'Chọn tài khoản' : 'Chưa có tài khoản đã xác minh'}</option>{verified.map((account) => <option key={account.id} value={account.id}>{account.bankName} · {account.accountNumberMasked}</option>)}</Select></Field>
      <div className="flex items-end"><Button type="submit" loading={busy === 'request'} disabled={!form.amountVnd || !form.accountId}>Gửi yêu cầu rút</Button></div></form></Card>
    {!data.items.length ? <Card><EmptyState title="Chưa có yêu cầu rút tiền" description="Tiền chỉ rút được từ khoản cọc đã chuyển thành khoản phải trả." /></Card> : data.items.map((item) => <WithdrawalCard key={item.id} item={item} actions={['PENDING_APPROVAL', 'FAILED'].includes(item.status) && <Button size="sm" variant="secondary" loading={busy === item.id} onClick={() => cancel(item)}>Hủy yêu cầu</Button>} />)}
  </div>;
}

function useAdmin() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const load = useCallback(async () => {
    setError('');
    try { setData(await walletApi.admin(1)); } catch (requestError) { setError(requestError.message || 'Không thể tải dữ liệu đối soát'); }
  }, []);
  useEffect(() => { void load(); }, [load]);
  return { data, error, load };
}

function AdminReview() {
  const { data, error, load } = useAdmin();
  const [busy, setBusy] = useState('');
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={4} />;
  const reconcile = async (row) => {
    setBusy(row.id);
    try { const result = await walletApi.reconcile(row.id); toast[result.applied ? 'success' : 'error'](result.applied ? 'Đã ghi nhận vào ví' : 'Vẫn chưa khớp, giữ để đối soát'); await load(); }
    catch (requestError) { toast.error(requestError.message); } finally { setBusy(''); }
  };
  return <div className="space-y-3">
    {!data.webhooks.length ? <Card><EmptyState title="Không có biên nhận cần đối soát" description="Biên nhận không khớp yêu cầu nạp ví sẽ hiện ở đây." /></Card> :
      <Card className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase text-[var(--bb-muted)]"><tr><th className="p-4">Nhận lúc</th><th className="p-4">Mã SePay</th><th className="p-4">Kênh</th><th className="p-4 text-right">Số tiền</th><th className="p-4">Trạng thái</th><th className="p-4" /></tr></thead>
        <tbody>{data.webhooks.map((row) => <tr key={row.id} className="border-t border-[var(--bb-border)]"><td className="p-4 whitespace-nowrap">{when(row.receivedAt)}</td><td className="p-4 font-mono text-xs">{row.providerTransactionId}<br />{row.environment}</td><td className="p-4 text-xs">{row.channel === 'WALLET_LIVE_LAB' ? 'Ví Live lab' : 'Biên nhận thường (chỉ lưu)'}</td><td className="p-4 text-right tabular-nums">{money(row.amountVnd)}</td>
          <td className="p-4"><Badge tone={row.processing === 'MANUAL_REVIEW' ? 'warning' : 'info'}>{row.processing}</Badge>{row.reviewReason && <p className="mt-1 text-xs">{reviewText[row.reviewReason] || row.reviewReason}</p>}</td>
          <td className="p-4 text-right">{row.channel === 'WALLET_LIVE_LAB' && <Button size="sm" variant="secondary" loading={busy === row.id} onClick={() => reconcile(row)}>Đối soát lại</Button>}</td></tr>)}</tbody></table></Card>}
    <Card className="p-4 text-sm"><b>Nạp ví gần đây</b><ul className="mt-2 space-y-1">{data.topUps.slice(0, 8).map((topUp) => <li key={topUp.id} className="flex justify-between gap-3"><span className="font-mono text-xs">{topUp.code}</span><span>{money(topUp.amountVnd)}</span><Badge tone={topUp.status === 'SUCCESS' ? 'success' : topUp.status === 'PENDING' ? 'warning' : 'neutral'}>{topUp.status}</Badge></li>)}{!data.topUps.length && <li className="text-[var(--bb-muted)]">Chưa có yêu cầu nạp.</li>}</ul></Card>
  </div>;
}

function AdminAccounts() {
  const { data, error, load } = useAdmin();
  const [busy, setBusy] = useState('');
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={3} />;
  const verify = async (account) => {
    setBusy(account.id);
    try { await walletApi.verifyAccount(account.businessId, account.id, account.version); toast.success('Đã xác minh tài khoản (mô phỏng)'); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  if (!data.accounts.length) return <Card><EmptyState title="Chưa có tài khoản ngân hàng" description="Tài khoản do chủ cơ sở gửi sẽ hiện ở đây để xác minh." /></Card>;
  return <div className="space-y-3">{data.accounts.map((account) => <AccountRow key={account.id} account={account}>{account.status === 'PENDING' && <Button size="sm" loading={busy === account.id} onClick={() => verify(account)}>Xác minh</Button>}</AccountRow>)}</div>;
}

function AdminPayouts() {
  const [items, setItems] = useState(null);
  const [status, setStatus] = useState('PENDING_APPROVAL');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const load = useCallback(async () => {
    setError('');
    try { setItems((await walletApi.adminWithdrawals(status)).items); }
    catch (requestError) { setError(requestError.message || 'Không thể tải yêu cầu rút tiền'); }
  }, [status]);
  useEffect(() => { void load(); }, [load]);
  const decide = async (item, action) => {
    let reason;
    if (action === 'reject') { reason = window.prompt('Lý do từ chối?'); if (!reason) return; }
    setBusy(item.id);
    try { await walletApi.decide(item.id, action, { version: item.version, ...(reason ? { reason } : {}) }); toast.success(action === 'reject' ? 'Đã từ chối' : 'Đã xử lý bằng nhà cung cấp MOCK'); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  return <div className="space-y-3">
    <Card className="p-4"><Field label="Trạng thái"><Select value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Tất cả</option>{Object.entries(withdrawalText).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select></Field></Card>
    {error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !items ? <Skeleton rows={4} /> : !items.length ? <Card><EmptyState title="Không có yêu cầu" description="Chưa có yêu cầu rút tiền ở trạng thái này." /></Card> : items.map((item) => <WithdrawalCard key={item.id} item={item} actions={<>
      {item.status === 'PENDING_APPROVAL' && <><Button size="sm" loading={busy === item.id} onClick={() => decide(item, 'approve')}>Duyệt và xử lý</Button><Button size="sm" variant="secondary" onClick={() => decide(item, 'reject')}>Từ chối</Button></>}
      {item.status === 'FAILED' && <Button size="sm" loading={busy === item.id} onClick={() => decide(item, 'retry')}>Thử lại</Button>}</>} />)}
  </div>;
}

function SettlementCard({ item, actions }) {
  return <Card className="p-5"><div className="flex flex-col gap-3 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><h3 className="font-bold">{money(item.amountVnd)} <span className="text-sm font-normal text-[var(--bb-muted)]">· đợt #{item.id}{item.businessName ? ` · ${item.businessName}` : ''}</span></h3>
    <p className="text-xs text-[var(--bb-muted)]">Kỳ {day(item.periodStart)} – {day(item.periodEnd)} (không gồm ngày cuối) · {item.bankSnapshot?.bankName} · …{String(item.bankSnapshot?.accountNumber || '').slice(-4)} · {item.bankSnapshot?.accountName}{item.completedAt ? ` · hoàn tất ${when(item.completedAt)}` : ''}</p></div>
    <Badge tone={settlementTone[item.status] || 'neutral'}>{settlementText[item.status] || item.status}</Badge>{actions}</div></Card>;
}

function AdminSettlements() {
  const { data, error, load } = useAdmin();
  const [form, setForm] = useState({ businessId: '', start: isoDay(-30), end: isoDay(1) });
  const [busy, setBusy] = useState('');
  const [attempt, setAttempt] = useState(null);
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={3} />;
  const ready = data.accounts.filter((account) => account.status === 'VERIFIED_DEMO' && account.isDefault);
  const account = ready.find((row) => row.businessId === form.businessId);
  const create = async (event) => {
    event.preventDefault();
    if (!account) return;
    const body = { businessId: account.businessId, accountId: account.id, periodStart: new Date(`${form.start}T00:00:00`).toISOString(), periodEnd: new Date(`${form.end}T00:00:00`).toISOString() };
    const signature = JSON.stringify(body);
    // One key per distinct request: a retry after a lost response repeats it, a changed request gets a new one.
    const next = attempt?.sig === signature ? attempt : { sig: signature, key: `st-${createIdempotencyKey()}`.replace(/[^A-Za-z0-9._:-]/g, '').padEnd(16, '0') };
    setAttempt(next); setBusy('create');
    try { await walletApi.createSettlement(body, next.key); toast.success('Đã tạo đợt đối soát (mô phỏng)'); setAttempt(null); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  const complete = async (item) => {
    setBusy(item.id);
    try { await walletApi.completeSettlement(item.id); toast.success('Đã đánh dấu hoàn tất (không có chuyển khoản thật)'); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  return <div className="space-y-3">
    <Card className="p-5"><h3 className="mb-3 font-bold">Tạo đợt đối soát lô</h3>
      <form onSubmit={create} className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Field label="Doanh nghiệp (có tài khoản mặc định đã xác minh)" required><Select required value={form.businessId} onChange={(event) => setForm({ ...form, businessId: event.target.value })}><option value="">{ready.length ? 'Chọn doanh nghiệp' : 'Chưa có tài khoản đã xác minh'}</option>{ready.map((row) => <option key={row.businessId} value={row.businessId}>{row.businessName} · {row.bankName} {row.accountNumberMasked}</option>)}</Select></Field>
        <Field label="Từ ngày" required><Input required type="date" value={form.start} onChange={(event) => setForm({ ...form, start: event.target.value })} /></Field>
        <Field label="Đến ngày (không gồm)" required><Input required type="date" value={form.end} onChange={(event) => setForm({ ...form, end: event.target.value })} /></Field>
        <div className="flex items-end"><Button type="submit" loading={busy === 'create'} disabled={!account || !form.start || !form.end}>Tạo đợt đối soát</Button></div>
      </form>
      <p className="mt-3 text-xs text-[var(--bb-muted)]">Gom các khoản phải trả chưa rút, chưa bị hoàn của kỳ đã chọn. Hoàn tất chỉ ghi sổ, không chuyển tiền thật.</p></Card>
    {!data.settlements.length ? <Card><EmptyState title="Chưa có đợt đối soát" description="Đợt đối soát tạo ở đây sẽ hiện kèm nút hoàn tất." /></Card> : data.settlements.map((item) => <SettlementCard key={item.id} item={item} actions={item.status === 'PENDING' && <Button size="sm" loading={busy === item.id} onClick={() => complete(item)}>Hoàn tất (mô phỏng)</Button>} />)}
  </div>;
}

function OwnerSettlements({ businessId }) {
  const { data, error, load } = useBusiness(businessId);
  if (!businessId) return <NoBusiness />;
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={3} />;
  return <div className="space-y-3">
    {!data.settlements?.length ? <Card><EmptyState title="Chưa có đợt đối soát" description="Quản trị viên tạo đợt đối soát lô cho các khoản phải trả của doanh nghiệp. Đợt đã tạo sẽ hiện ở đây." /></Card> : data.settlements.map((item) => <SettlementCard key={item.id} item={item} />)}
  </div>;
}

const connectionTone = { SETUP: 'warning', READY: 'success' };
const connectionText = { SETUP: 'Chờ xác minh', READY: 'Đã xác minh' };
const emptyConnection = { bankCode: 'ACB', accountNumber: '', accountName: '', environment: 'test' };

/** The owner's receiving accounts for direct deposits: create, verify by a signed transfer, enable, and see stray receipts. */
function OwnerDirectDeposits({ businessId }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [form, setForm] = useState(emptyConnection);
  const [secret, setSecret] = useState(null);
  const [busy, setBusy] = useState('');
  const load = useCallback(async () => {
    if (!businessId) return;
    setError('');
    try { setData(await paymentsApi.connections(businessId)); } catch (requestError) { setError(requestError.message || 'Không thể tải tài khoản nhận cọc'); }
  }, [businessId]);
  useEffect(() => { void load(); }, [load]);
  if (!businessId) return <NoBusiness />;
  if (error) return <Card><ErrorState message={error} onRetry={load} /></Card>;
  if (!data) return <Skeleton rows={3} />;
  const create = async (event) => {
    event.preventDefault(); setBusy('create');
    try { setSecret(await paymentsApi.createConnection(businessId, data.allowTest === false ? { ...form, environment: 'live' } : form)); setForm(emptyConnection); toast.success('Đã tạo kết nối. Hãy sao chép secret ngay, secret không hiện lại.'); await load(); }
    catch (requestError) { toast.error(requestError.message); } finally { setBusy(''); }
  };
  const toggle = async (connection) => {
    setBusy(connection.id);
    try { await paymentsApi.enableConnection(businessId, connection.id, { enabled: !connection.enabled, version: connection.version }); toast.success(connection.enabled ? 'Đã tắt nhận cọc mới' : 'Đã bật nhận cọc'); await load(); }
    catch (requestError) { toast.error(requestError.message); await load(); } finally { setBusy(''); }
  };
  const copy = async (value) => { try { await navigator.clipboard.writeText(value); toast.success('Đã sao chép'); } catch { toast.error('Không sao chép được'); } };
  return <div className="space-y-4">
    {!data.enabled && <Card className="p-4 text-sm text-[var(--bb-muted)]">Nhận cọc trực tiếp đang tắt trên máy chủ. Bạn vẫn xem được kết nối và biên nhận cũ; chưa tạo hoặc bật kết nối mới được.</Card>}
    {secret && <Card className="space-y-2 border-amber-300 bg-amber-50 p-5 text-sm"><h3 className="font-bold">Kết nối mới: lưu secret ngay</h3>
      <p>Secret chỉ hiện một lần. Dán vào phần HMAC-SHA256 của webhook SePay tiền vào (JSON, không dùng JWT hay API key).</p>
      <p>URL webhook: <code className="break-all">{secret.webhookUrl || `${window.location.origin}${secret.webhookPath}`}</code> <button type="button" className="font-semibold underline" onClick={() => copy(secret.webhookUrl || `${window.location.origin}${secret.webhookPath}`)}>Sao chép</button></p>
      <p>Secret: <code className="break-all">{secret.secret}</code> <button type="button" className="font-semibold underline" onClick={() => copy(secret.secret)}>Sao chép</button></p>
      <p>Mã xác minh: <code>{secret.verificationCode}</code>. Chuyển một khoản vào tài khoản này với nội dung chứa mã trên; khi nhận được webhook hợp lệ, kết nối chuyển sang “Đã xác minh”.</p>
      {secret.environment === 'live' && <p className="rounded-md border border-amber-400 bg-white p-2 text-xs">Kết nối <b>Live</b> nhận tiền thật. Trong SePay: Webhooks → thêm webhook, URL ở trên (bắt buộc https), kiểu xác thực <b>HMAC-SHA256</b> với secret này, kiểu dữ liệu <b>JSON</b>, sự kiện <b>tiền vào</b>, đúng tài khoản ngân hàng đã khai ở đây. Chỉ bật nhận cọc sau khi kết nối chuyển sang “Đã xác minh”.</p>}
      <Button size="sm" variant="secondary" onClick={() => setSecret(null)}>Tôi đã lưu secret</Button></Card>}
    <Card className="p-5"><h3 className="mb-3 font-bold">Thêm tài khoản nhận cọc</h3>
      <form onSubmit={create} className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <Field label="Ngân hàng" required><Select value={form.bankCode} onChange={(event) => setForm({ ...form, bankCode: event.target.value })}>{data.banks.map((bank) => <option key={bank} value={bank}>{bank}</option>)}</Select></Field>
        <Field label="Số tài khoản" required><Input required value={form.accountNumber} onChange={(event) => setForm({ ...form, accountNumber: event.target.value })} /></Field>
        <Field label="Tên chủ tài khoản (không dấu)" required><Input required value={form.accountName} onChange={(event) => setForm({ ...form, accountName: event.target.value })} /></Field>
        <Field label="Môi trường" required><Select value={data.allowTest === false ? 'live' : form.environment} onChange={(event) => setForm({ ...form, environment: event.target.value })}>{data.allowTest !== false && <option value="test">Test (không chuyển tiền thật)</option>}<option value="live">Live (tiền thật)</option></Select></Field>
        <div className="flex items-end"><Button type="submit" loading={busy === 'create'} disabled={!data.enabled}>Tạo kết nối</Button></div></form></Card>
    {!data.connections.length ? <Card><EmptyState title="Chưa có tài khoản nhận cọc" description="Tạo kết nối, xác minh bằng một khoản chuyển có chữ ký rồi bật để khách chuyển cọc thẳng vào tài khoản của bạn." /></Card> :
      data.connections.map((connection) => <Card key={connection.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="font-bold">{connection.bankCode} · {connection.accountNumber} · {connection.accountName}</p>
        <p className="text-xs text-[var(--bb-muted)]">{connection.environment === 'live' ? 'Live' : 'Test'} · mã xác minh {connection.verificationCode}{connection.verifiedAt ? ` · xác minh ${when(connection.verifiedAt)}` : ''}</p></div>
        <div className="flex flex-wrap items-center gap-2"><Badge tone={connectionTone[connection.status] || 'neutral'}>{connectionText[connection.status] || connection.status}</Badge>{connection.enabled && <Badge tone="info">Đang nhận cọc</Badge>}
          <Button size="sm" variant={connection.enabled ? 'secondary' : 'primary'} loading={busy === connection.id} disabled={!connection.enabled && (connection.status !== 'READY' || !data.enabled)} onClick={() => toggle(connection)}>{connection.enabled ? 'Tắt' : 'Bật nhận cọc'}</Button></div></Card>)}
    <Card className="p-4 text-sm"><b>Biên nhận cần đối soát</b>
      {!data.receipts.length ? <p className="mt-2 text-[var(--bb-muted)]">Không có khoản chuyển nào chưa khớp.</p> : <ul className="mt-2 space-y-2">{data.receipts.map((receipt) => <li key={receipt.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--bb-border)] pt-2"><span className="font-mono text-xs">{receipt.providerTransactionId}</span><span>{money(receipt.amountVnd)}</span><span className="min-w-0 flex-1 truncate text-xs text-[var(--bb-muted)]">{receipt.content}</span><Badge tone={receipt.processing === 'REFUND_REQUIRED' ? 'warning' : 'neutral'}>{receipt.processing === 'REFUND_REQUIRED' ? 'Cần hoàn lại' : 'Chưa khớp mã'}</Badge>{receipt.bookingId && <span className="text-xs">Lịch #{receipt.bookingId}</span>}</li>)}</ul>}
      <p className="mt-3 text-xs text-[var(--bb-muted)]">Khoản không có mã, sai mã hoặc sai kết nối được giữ ở đây và không tự ghép vào lịch nào; hãy đối soát và hoàn lại thủ công ngoài hệ thống.</p></Card>
  </div>;
}

function Pager({ result, onPage }) {
  const info = pageInfo(result);
  return <div className="flex flex-wrap items-center justify-between gap-2 text-sm"><span className="text-[var(--bb-muted)]">{info.total ? `Hiển thị ${info.from}–${info.to} trong ${info.total}` : 'Không có bản ghi'} · trang {info.page}/{info.pageCount}</span>
    <span className="flex gap-2"><Button size="sm" variant="secondary" disabled={!info.hasPrev} onClick={() => onPage(info.page - 1)}>Trước</Button><Button size="sm" variant="secondary" disabled={!info.hasNext} onClick={() => onPage(info.page + 1)}>Sau</Button></span></div>;
}

/** Platform admin: refunds owed back to customers and transfers no deposit could take, paged, with the admin's review note per transfer. */
function AdminDirectDeposits() {
  const [refundQuery, setRefundQuery] = useState({ page: 1, status: '', reason: '' });
  const [receiptQuery, setReceiptQuery] = useState({ page: 1, processing: '' });
  const [refunds, setRefunds] = useState(null);
  const [receipts, setReceipts] = useState(null);
  const [error, setError] = useState('');
  const [notes, setNotes] = useState({});
  const [busy, setBusy] = useState('');
  const loadRefunds = useCallback(async () => { try { setRefunds(await paymentsApi.adminDepositRefunds({ ...refundQuery, pageSize: 20 })); setError(''); } catch (requestError) { setError(requestError.message || 'Không tải được danh sách hoàn cọc'); } }, [refundQuery]);
  const loadReceipts = useCallback(async () => { try { setReceipts(await paymentsApi.adminDepositReceipts({ ...receiptQuery, pageSize: 20 })); setError(''); } catch (requestError) { setError(requestError.message || 'Không tải được danh sách chuyển khoản'); } }, [receiptQuery]);
  useEffect(() => { void loadRefunds(); }, [loadRefunds]);
  useEffect(() => { void loadReceipts(); }, [loadReceipts]);
  const review = async (item) => {
    const note = (notes[item.id] || '').trim();
    if (!note) return;
    setBusy(item.id);
    try { await paymentsApi.reviewDepositReceipt(item.id, note); toast.success('Đã ghi nhận xem xét'); setNotes({ ...notes, [item.id]: '' }); await loadReceipts(); }
    catch (requestError) { toast.error(requestError.message || 'Không thể ghi nhận'); } finally { setBusy(''); }
  };
  if (error && !refunds && !receipts) return <Card><ErrorState message={error} onRetry={() => { void loadRefunds(); void loadReceipts(); }} /></Card>;
  return <div className="space-y-4">
    <Card className="space-y-3 p-5"><h3 className="font-bold">Hoàn cọc cho khách (cọc trực tiếp)</h3>
      <div className="flex flex-wrap gap-3"><Field label="Trạng thái"><Select value={refundQuery.status} onChange={(event) => setRefundQuery({ ...refundQuery, page: 1, status: event.target.value })}><option value="">Tất cả</option>{Object.entries(REFUND_STATUS_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select></Field>
        <Field label="Lý do"><Select value={refundQuery.reason} onChange={(event) => setRefundQuery({ ...refundQuery, page: 1, reason: event.target.value })}><option value="">Tất cả</option>{Object.entries(REFUND_REASON).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Select></Field></div>
      {!refunds ? <Skeleton rows={3} /> : refunds.items.length === 0 ? <EmptyState title="Không có khoản hoàn nào" description="Các khoản chuyển khoản cần hoàn lại cho khách sẽ hiện ở đây." /> : <ul className="space-y-2" aria-label="Danh sách hoàn cọc">{refunds.items.map((item) => <li key={item.id} className="rounded-lg border border-[var(--bb-border)] p-3 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold">{money(item.amountVnd)} · {REFUND_REASON[item.reason] || item.reason}</span><Badge tone={item.status === 'CONFIRMED' ? 'success' : item.status === 'REPORTED' ? 'info' : 'warning'}>{REFUND_STATUS_LABEL[item.status] || item.status}</Badge></div>
        <p className="mt-1 text-xs text-[var(--bb-muted)]">{item.businessName} · lịch {item.bookingCode} · cọc {item.depositStatus} · {when(item.createdAt)}</p>
        {item.destination && <p className="mt-1 text-xs">Chuyển trả về: {item.destination.bankCode} · {item.destination.accountNumber} · {item.destination.accountName}</p>}
        {item.reference && <p className="mt-1 text-xs">Mã chuyển trả: {item.reference}</p>}</li>)}</ul>}
      {refunds && <Pager result={refunds} onPage={(page) => setRefundQuery({ ...refundQuery, page })} />}</Card>
    <Card className="space-y-3 p-5"><h3 className="font-bold">Chuyển khoản vào tài khoản nhận cọc</h3>
      <Field label="Trạng thái xử lý"><Select value={receiptQuery.processing} onChange={(event) => setReceiptQuery({ page: 1, processing: event.target.value })}><option value="">Cần xử lý (không khớp, cần hoàn)</option>{Object.entries(RECEIPT_PROCESSING).map(([value, meta]) => <option key={value} value={value}>{meta.label}</option>)}</Select></Field>
      {!receipts ? <Skeleton rows={3} /> : receipts.items.length === 0 ? <EmptyState title="Không có chuyển khoản nào" description="Khoản chuyển chưa xác định hoặc cần hoàn sẽ hiện ở đây." /> : <ul className="space-y-2" aria-label="Danh sách chuyển khoản">{receipts.items.map((item) => {
        const meta = RECEIPT_PROCESSING[item.processing] || { label: item.processing, tone: 'neutral' };
        return <li key={item.id} className="rounded-lg border border-[var(--bb-border)] p-3 text-sm">
          <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold">{money(item.amountVnd)} · giao dịch {item.providerTransactionId}</span><Badge tone={meta.tone}>{meta.label}</Badge></div>
          <p className="mt-1 break-words text-xs text-[var(--bb-muted)]">{item.businessName} · {item.environment} · {when(item.transactionAt)}{item.bookingId ? ` · lịch #${item.bookingId}` : ''} · nội dung: {item.content}</p>
          {item.reviews.map((entry, index) => <p key={index} className="mt-1 text-xs">Đã xem xét {when(entry.at)}: {entry.note}</p>)}
          <form className="mt-2 flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); void review(item); }}><Field label="Ghi chú xem xét"><Input value={notes[item.id] || ''} onChange={(event) => setNotes({ ...notes, [item.id]: event.target.value })} maxLength={500} /></Field><Button type="submit" size="sm" variant="secondary" loading={busy === item.id} disabled={!(notes[item.id] || '').trim()}>Ghi nhận đã xem</Button></form></li>;
      })}</ul>}
      {receipts && <Pager result={receipts} onPage={(page) => setReceiptQuery({ ...receiptQuery, page })} />}</Card>
  </div>;
}
