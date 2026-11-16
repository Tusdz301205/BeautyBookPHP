import { useCallback, useEffect, useRef, useState } from 'react';
import { ChevronDown, RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { walletApi } from '../../api/apiClient';
import { createIdempotencyKey } from '../../utils/idempotencyKey';
import StableSurface from '../../components/ui/StableSurface';
import { Badge, Button, Card, ErrorState, Field, Input, Page, PageHeader, Select, Skeleton } from '../../components/ui';
import '../../styles/owner-finance.css';

const money = (value) => `${BigInt(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => value ? new Date(value).toLocaleString('vi-VN') : '—';
const date = (value) => value ? new Date(value).toLocaleDateString('vi-VN') : '—';
const withdrawalLabels = { PENDING_APPROVAL: 'Chờ duyệt', PROCESSING: 'Đang xử lý', FAILED: 'Cần xử lý lại', REJECTED: 'Đã từ chối', CANCELLED: 'Đã hủy', SIMULATED_COMPLETED: 'Đã chi trả (mô phỏng)' };
const tones = { PENDING_APPROVAL: 'warning', PROCESSING: 'info', FAILED: 'danger', REJECTED: 'neutral', CANCELLED: 'neutral', SIMULATED_COMPLETED: 'success' };
const bankLabels = { PENDING: 'Chờ duyệt', VERIFIED_DEMO: 'Đã duyệt', DISABLED: 'Đã tắt' };
const emptyBank = { bankCode: 'ACB', accountNumber: '', accountName: '' };

// Mount secondary workspaces on first opening, keeping form state on subsequent toggles.
export function FinanceDisclosure({ title, children, defaultOpen = false }) {
  const [open, setOpen] = useState(defaultOpen);
  const [visited, setVisited] = useState(defaultOpen);
  return <details className="bb-finance-disclosure" open={open} onToggle={(event) => {
    if (event.target !== event.currentTarget) return;
    setOpen(event.currentTarget.open);
    if (event.currentTarget.open) setVisited(true);
  }}>
    <summary><span>{title}</span><ChevronDown size={18} aria-hidden="true" /></summary>
    {visited && <div className="bb-finance-disclosure__body" hidden={!open}>{children}</div>}
  </details>;
}

function RequestCard({ item, disabled, onCancel }) {
  const [reason, setReason] = useState('');
  return <article className="bb-finance-request" aria-label={`Yêu cầu rút tiền ${item.id}`}>
    <header><div><p className="bb-finance-muted">Yêu cầu rút tiền</p><h3>{money(item.amountVnd)}</h3></div><Badge tone={tones[item.status]}>{withdrawalLabels[item.status] || 'Đang xử lý'}</Badge></header>
    <p>{item.bankSnapshot?.bankName} · …{String(item.bankSnapshot?.accountNumber || '').slice(-4)}</p>
    <p className="bb-finance-muted">{item.bankSnapshot?.accountName} · {when(item.requestedAt)}</p>
    {item.status === 'FAILED' && <p role="status">Số tiền vẫn được giữ để xử lý lại hoặc hủy yêu cầu.</p>}
    {['REJECTED', 'CANCELLED'].includes(item.status) && item.reason && <p>Lý do: {item.reason}</p>}
    <details className="bb-finance-history"><summary>Chi tiết và lịch sử xử lý</summary>
      <dl><div><dt>Mã yêu cầu</dt><dd>#{item.id}</dd></div><div><dt>Ngày yêu cầu</dt><dd>{when(item.requestedAt)}</dd></div>{item.completedAt && <div><dt>Ngày hoàn tất</dt><dd>{when(item.completedAt)}</dd></div>}</dl>
      {!!item.events?.length && <ol>{item.events.map((event, index) => <li key={index}><strong>{withdrawalLabels[event.to_status] || 'Cập nhật yêu cầu'}</strong><span>{when(event.created_at)}</span>{event.reason && !/^[A-Z_]+$/.test(event.reason) && <p>{event.reason}</p>}</li>)}</ol>}
      {!!item.attempts?.length && <p className="bb-finance-muted">Số lần xử lý: {item.attempts.length}</p>}
    </details>
    {['PENDING_APPROVAL', 'FAILED'].includes(item.status) && <form className="bb-finance-cancel" onSubmit={(event) => { event.preventDefault(); if (reason.trim()) onCancel(item, reason.trim()); }}>
      <Field label="Lý do hủy yêu cầu" required><Input required maxLength={500} disabled={disabled} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Nhập lý do hủy yêu cầu" /></Field>
      <Button type="submit" variant="secondary" disabled={disabled || !reason.trim()}>Hủy yêu cầu</Button>
    </form>}
  </article>;
}

function BankAccount({ account, disabled, onEdit }) {
  return <article className="bb-finance-bank"><div><strong>{account.bankName} · {account.accountNumberMasked}</strong><p className="bb-finance-muted">{account.accountName}</p></div><div className="bb-finance-bank-actions"><Badge tone={account.status === 'VERIFIED_DEMO' ? 'success' : 'neutral'}>{bankLabels[account.status] || 'Chờ duyệt'}</Badge>{account.isDefault && <Badge>Mặc định</Badge>}{onEdit && account.status === 'VERIFIED_DEMO' && <Button size="sm" variant="secondary" disabled={disabled} onClick={() => onEdit(account)}>Thay đổi tài khoản</Button>}</div></article>;
}

export default function OwnerFinanceWorkspace({ businessId, children }) {
  const [scope, setScope] = useState('LIVE_LAB');
  const [snapshot, setSnapshot] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [amount, setAmount] = useState('');
  const [accountId, setAccountId] = useState('');
  const [attempt, setAttempt] = useState(null);
  const [page, setPage] = useState(1);
  const [bank, setBank] = useState(emptyBank);
  const [editing, setEditing] = useState(null);
  const sequence = useRef(0);
  const contextKey = `${businessId}:${scope}`;
  const data = snapshot?.key === contextKey ? snapshot : null;

  const load = useCallback(async () => {
    if (!businessId) return;
    const request = ++sequence.current;
    setLoading(true); setError('');
    try {
      const [business, withdrawals] = await Promise.all([walletApi.business(businessId, scope), walletApi.withdrawals(businessId, scope)]);
      if (request === sequence.current) setSnapshot({ key: `${businessId}:${scope}`, business, withdrawals });
    } catch (problem) { if (request === sequence.current) setError(problem.message || 'Không thể tải dữ liệu tài chính'); }
    finally { if (request === sequence.current) setLoading(false); }
  }, [businessId, scope]);
  useEffect(() => { void load(); return () => { sequence.current++; }; }, [load]);

  const locked = !!busy || !!attempt;
  const accounts = data?.business.accounts || [];
  const approved = accounts.filter((account) => account.status === 'VERIFIED_DEMO');
  const selected = approved.find((account) => account.id === accountId) || approved.find((account) => account.isDefault) || approved[0];
  const balances = data?.withdrawals.balances;
  const validAmount = /^[1-9][0-9]{0,14}$/.test(amount) && BigInt(amount) <= BigInt(balances?.available || 0);
  const items = data?.withdrawals.items || [];
  const pageCount = Math.max(1, Math.ceil(items.length / 5));
  const activePage = Math.min(page, pageCount);
  const disabled = locked || loading || !data;

  const withdraw = async (event) => {
    event.preventDefault();
    if (busy || (!attempt && (!validAmount || !selected || loading))) return;
    const next = attempt || { key: `wd-${createIdempotencyKey()}`.replace(/[^A-Za-z0-9._:-]/g, '').padEnd(16, '0'), body: { amountVnd: amount, accountId: Number(selected.id), walletScope: scope } };
    setAttempt(next); setBusy('withdraw');
    try {
      await walletApi.requestWithdrawal(businessId, next.body, next.key);
      setAttempt(null); setAmount(''); setPage(1); toast.success('Đã gửi yêu cầu rút tiền');
    } catch (problem) {
      // A missing/5xx response can follow a committed command. Freeze its body and key until replay resolves it.
      if (problem.status >= 400 && problem.status < 500) { setAttempt(null); toast.error(problem.message); }
      else toast.error('Chưa xác định được kết quả. Bấm kiểm tra lại yêu cầu.');
    } finally { await load(); setBusy(''); }
  };
  const cancel = async (item, reason) => {
    setBusy(`cancel-${item.id}`);
    try { await walletApi.cancelWithdrawal(businessId, item.id, { version: item.version, reason }); toast.success('Đã hủy yêu cầu'); }
    catch (problem) { toast.error(problem.message); }
    finally { await load(); setBusy(''); }
  };
  const saveBank = async (event) => {
    event.preventDefault(); if (locked) return;
    setBusy('bank');
    const code = bank.bankCode.trim().toUpperCase();
    const body = { bankCode: code, bankName: code, accountNumber: bank.accountNumber.trim(), accountName: bank.accountName.trim() };
    try {
      if (editing) await walletApi.replaceAccount(businessId, editing.id, { ...body, version: editing.version });
      else await walletApi.addAccount(businessId, body);
      setEditing(null); setBank(emptyBank); toast.success('Đã gửi tài khoản, chờ quản trị viên duyệt');
    } catch (problem) { toast.error(problem.message); }
    finally { await load(); setBusy(''); }
  };

  return <Page className="bb-owner-finance">
    <PageHeader title="Số dư và rút tiền" actions={<Button variant="secondary" disabled={loading || !!busy} onClick={load}><RefreshCw size={16} aria-hidden="true" />Làm mới</Button>} />
    {!businessId ? <Card className="p-5"><p>Cần chọn doanh nghiệp để xem tài chính.</p></Card> : <StableSurface className="bb-finance-content" aria-busy={loading || !!busy}>
      {error && <Card><ErrorState message={error} onRetry={load} /></Card>}
      {!data && !error && <Card className="p-5"><Skeleton rows={4} /></Card>}
      {data && <>
        <dl className="bb-finance-balances" aria-label="Số dư doanh nghiệp">
          {[['Có thể rút', balances.available], ['Cọc đang giữ', data.business.heldVnd], ['Đang giữ để chi trả', balances.locked], ['Đã chi trả (mô phỏng)', balances.completed]].map(([label, value], index) => <div key={label} className={index === 0 ? 'bb-finance-balance-main' : ''}><dt>{label}</dt><dd>{money(value)}</dd></div>)}
        </dl>
        {!data.business.walletMode && <p role="status" className="bb-finance-muted">Ví đặt cọc chưa được bật.</p>}
        <div className="bb-finance-primary">
        <section className="bb-finance-form" aria-labelledby="withdrawal-title">
          <div><h2 id="withdrawal-title">Yêu cầu rút tiền</h2><p className="bb-finance-muted">Số tiền được giữ khi gửi yêu cầu và trả lại số dư nếu bị từ chối hoặc hủy.</p></div>
          <form onSubmit={withdraw} aria-label="Yêu cầu rút tiền">
            <fieldset disabled={disabled} className="bb-finance-fields">
              <Field label="Số tiền muốn rút" required><Input required inputMode="numeric" pattern="[1-9][0-9]*" maxLength={15} value={amount} onChange={(event) => setAmount(event.target.value.replace(/[^0-9]/g, ''))} placeholder="Nhập số tiền (đ)" /></Field>
              <Field label="Tài khoản nhận" required><Select required value={selected?.id || ''} onChange={(event) => setAccountId(event.target.value)}><option value="" disabled>Chọn tài khoản đã duyệt</option>{approved.map((account) => <option key={account.id} value={account.id}>{account.bankName} · {account.accountNumberMasked} · {account.accountName}</option>)}</Select></Field>
            </fieldset>
            {!approved.length && <p className="bb-finance-muted">Đăng ký tài khoản ngân hàng bên dưới và chờ duyệt để rút tiền.</p>}
            {amount && !validAmount && !attempt && <p role="alert" className="bb-finance-error">Nhập số tiền lớn hơn 0 và không vượt số dư có thể rút.</p>}
            {attempt && !busy && <p role="status">Chưa xác định kết quả. Kiểm tra lại để tiếp tục với yêu cầu vừa gửi.</p>}
            <Button type="submit" loading={busy === 'withdraw'} disabled={!!busy || (!attempt && (loading || !validAmount || !selected))}>{attempt && !busy ? 'Kiểm tra lại yêu cầu' : 'Gửi yêu cầu rút tiền'}</Button>
          </form>
          <p className="bb-finance-muted bb-finance-simulation">Việc chi trả hiện được mô phỏng, chưa chuyển tiền qua ngân hàng.</p>
        </section>
        <section className="bb-finance-requests" aria-labelledby="withdrawal-history-title"><h2 id="withdrawal-history-title">Lịch sử rút tiền</h2>
          {!items.length ? <p className="bb-finance-empty">Chưa có yêu cầu rút tiền.</p> : items.slice((activePage - 1) * 5, activePage * 5).map((item) => <RequestCard key={`${contextKey}:${item.id}`} item={item} disabled={disabled} onCancel={cancel} />)}
          {!!items.length && <nav aria-label="Phân trang rút tiền"><Button size="sm" variant="secondary" disabled={activePage <= 1 || locked} onClick={() => setPage(activePage - 1)}>Trang trước</Button><span>Trang {activePage} / {pageCount}</span><Button size="sm" variant="secondary" disabled={activePage >= pageCount || locked} onClick={() => setPage(activePage + 1)}>Trang sau</Button></nav>}
          {items.length === 50 && <p className="bb-finance-muted">Đang hiển thị 50 yêu cầu gần nhất.</p>}
        </section>
        </div>
        <FinanceDisclosure title="Tài khoản ngân hàng nhận tiền" defaultOpen={!accounts.some((account) => account.status !== 'DISABLED')}>
          {editing && <p className="bb-finance-muted">Tài khoản hiện tại vẫn dùng được đến khi tài khoản thay thế được duyệt.</p>}
          <form onSubmit={saveBank} aria-label={editing ? 'Thay thế tài khoản ngân hàng' : 'Đăng ký tài khoản ngân hàng'}>
            <fieldset disabled={disabled} className="bb-finance-fields bb-finance-fields--bank">
              <Field label="Ngân hàng" required><Input required maxLength={32} value={bank.bankCode} onChange={(event) => setBank({ ...bank, bankCode: event.target.value })} placeholder="ACB" /></Field>
              <Field label="Số tài khoản" required><Input required inputMode="numeric" pattern="[A-Za-z0-9_-]{3,64}" maxLength={64} value={bank.accountNumber} onChange={(event) => setBank({ ...bank, accountNumber: event.target.value })} /></Field>
              <Field label="Tên chủ tài khoản" required><Input required maxLength={120} value={bank.accountName} onChange={(event) => setBank({ ...bank, accountName: event.target.value })} /></Field>
            </fieldset>
            <div className="bb-finance-bank-actions"><Button type="submit" loading={busy === 'bank'} disabled={disabled || !bank.bankCode.trim() || !bank.accountNumber.trim() || !bank.accountName.trim()}>{editing ? 'Gửi tài khoản thay thế' : 'Đăng ký tài khoản nhận tiền'}</Button>{editing && <Button variant="secondary" disabled={disabled} onClick={() => { setEditing(null); setBank(emptyBank); }}>Hủy thay đổi</Button>}</div>
          </form>
          {!accounts.length && <p className="bb-finance-muted">Chưa có tài khoản ngân hàng.</p>}
          {accounts.filter((account) => account.status !== 'DISABLED').map((account) => <BankAccount key={account.id} account={account} disabled={disabled} onEdit={(value) => { setEditing(value); setBank({ bankCode: value.bankCode, accountName: value.accountName, accountNumber: '' }); }} />)}
          {accounts.some((account) => account.status === 'DISABLED') && <FinanceDisclosure title={`Tài khoản trước đây (${accounts.filter((account) => account.status === 'DISABLED').length})`}>{accounts.filter((account) => account.status === 'DISABLED').map((account) => <BankAccount key={account.id} account={account} />)}</FinanceDisclosure>}
        </FinanceDisclosure>
        <FinanceDisclosure title="Chi tiết và công cụ">
        <FinanceDisclosure title="Các khoản cọc được hưởng">
          {!data.business.payables.length ? <p className="bb-finance-muted">Chưa có khoản cọc đủ điều kiện.</p> : data.business.payables.map((item) => <article className="bb-finance-payable" key={item.id}><strong>{money(item.amountVnd)} · {item.reason === 'CUSTOMER_FORFEITURE' ? 'Mất cọc theo chính sách' : 'Dịch vụ hoàn tất'}</strong><p className="bb-finance-muted">{item.bookingCode} · {item.settlementId ? 'Đã đưa vào đợt chi trả' : BigInt(item.allocatedVnd) > 0n ? `Đã phân bổ ${money(item.allocatedVnd)}` : 'Có thể rút'} · {when(item.createdAt)}</p></article>)}
        </FinanceDisclosure>
        {!!data.business.settlements.length && <FinanceDisclosure title="Lịch sử chi trả theo đợt">{data.business.settlements.map((item) => <article className="bb-finance-payable" key={item.id}><div className="bb-finance-bank"><strong>{money(item.amountVnd)}</strong><Badge tone={item.status === 'SIMULATED_PAID' ? 'success' : 'warning'}>{item.status === 'SIMULATED_PAID' ? 'Đã chi trả (mô phỏng)' : 'Chờ chi trả'}</Badge></div><p className="bb-finance-muted">{item.bankSnapshot?.bankName} · …{String(item.bankSnapshot?.accountNumber || '').slice(-4)} · {date(item.periodStart)} – {date(item.periodEnd)}</p></article>)}</FinanceDisclosure>}
        <FinanceDisclosure title="Dữ liệu trước đây"><Field label="Nguồn số dư"><Select disabled={locked || loading} value={scope} onChange={(event) => { setScope(event.target.value); setAmount(''); setAccountId(''); setPage(1); }}><option value="LIVE_LAB">Đang sử dụng</option><option value="DEMO">Lưu trữ trước đây</option></Select></Field></FinanceDisclosure>
        {children}
        </FinanceDisclosure>
      </>}
    </StableSurface>}
  </Page>;
}
