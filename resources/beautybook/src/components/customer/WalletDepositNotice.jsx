import React from 'react';
import { Link } from 'react-router-dom';
import { Wallet } from 'lucide-react';
import { InlineNotice } from '../ui';
import { depositSummary } from '../../utils/walletDeposit';
import '../../styles/wallet-deposit-notice.css';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' }) : '');

/** One line per occurrence when each is priced differently: its own date, price and deposit. */
function SeriesRows({ rows }) {
  return <ul className="mt-3 space-y-1 rounded-lg border border-[var(--bb-border)] px-3 py-2 text-sm" aria-label="Cọc từng lần hẹn">
    {rows.map((row) => <li key={row.index} className="flex justify-between gap-3"><span>Lần {row.index + 1} · {when(row.startAt)} <span className="text-[var(--bb-muted)]">(giá {money(row.totalVnd)})</span></span><span className="font-semibold tabular-nums">{money(row.depositVnd)}</span></li>)}
  </ul>;
}

const blocked = {
  NOT_IN_LAB: 'Chưa thể giữ cọc từ ví. Vui lòng thử lại sau.',
  TERMS_UNAVAILABLE: 'Không tải được điều khoản cọc. Vui lòng thử lại.',
};

/** The deposit the booking will hold from the wallet, and why a booking is blocked when it cannot. */
export default function WalletDepositNotice({ readiness, terms, wallet, loading, onRetry }) {
  if (!readiness?.required && !loading) return null;
  const summary = depositSummary(terms, wallet);
  if (readiness?.direct) return <section className="mb-5 rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface-subtle)] p-4" aria-label="Cọc trực tiếp cho cơ sở">
    <h2 className="text-sm font-bold">Cọc 30% chuyển thẳng cho cơ sở</h2>
    {terms?.count > 0 && !terms.uniform && <SeriesRows rows={terms.rows} />}
    {terms && <dl className="mt-3 space-y-1 text-sm"><div className="flex justify-between"><dt>{terms.count > 0 ? 'Tổng cọc của các lần hẹn' : 'Tiền cọc'}</dt><dd className="font-bold tabular-nums">{money(summary.deposit)}</dd></div><div className="flex justify-between"><dt>Còn lại trả tại cơ sở</dt><dd className="tabular-nums">{money(summary.remaining)}</dd></div>{terms.accountName && <div className="flex justify-between gap-3"><dt>Người nhận</dt><dd>{terms.accountName} · {terms.bankCode}</dd></div>}</dl>}
    <p className="mt-3 text-xs text-[var(--bb-muted)]">Sau khi tạo lịch, chuyển đúng số tiền và nội dung trên QR vào tài khoản cơ sở. Lịch chỉ được xác nhận sau khi nhận đủ cọc.</p>
    {readiness.ok === false && <InlineNotice tone="warning">{readiness.message || 'Cơ sở chưa sẵn sàng nhận cọc. Vui lòng chọn cơ sở khác hoặc liên hệ để được hỗ trợ.'}</InlineNotice>}
  </section>;
  if (loading) return <p role="status" className="mb-4 text-sm">Đang kiểm tra cọc và số dư ví…</p>;
  return <section className="wallet-deposit-notice" aria-label="Cọc đặt lịch từ ví">
    <h2 className="wallet-deposit-notice__heading"><Wallet size={17} aria-hidden="true" />Đặt cọc từ ví <span>30%</span></h2>
    {terms?.count > 0 && !terms.uniform && <SeriesRows rows={terms.rows} />}
    {terms && <>
      <dl className="wallet-deposit-notice__amounts">
        <div><dt>{terms.count > 0 ? `Tổng cọc · ${terms.count} lần hẹn` : 'Tiền cọc'}</dt><dd className="wallet-deposit-notice__deposit">{money(summary.deposit)}</dd></div>
        <div><dt>{terms.count > 0 ? 'Tổng còn lại trả tại cơ sở' : 'Trả tại cơ sở'}</dt><dd>{money(summary.remaining)}</dd></div>
      </dl>
      {terms.count > 0 && terms.uniform && <p className="wallet-deposit-notice__per">Cọc mỗi lần hẹn: {money(terms.perVnd)}</p>}
      <dl className="wallet-deposit-notice__balance">
        <div><dt>Ví hiện có</dt><dd>{money(wallet?.availableVnd)}</dd></div>
        {readiness.ok && <div><dt>Sau khi đặt</dt><dd>{money(summary.availableAfter)}</dd></div>}
      </dl>
    </>}
    <p className="wallet-deposit-notice__hint">Tiền cọc được tính vào tổng giá dịch vụ.</p>
    <details className="wallet-deposit-notice__policy">
      <summary>Điều kiện hoàn cọc</summary>
      <p>Hoàn về ví nếu cơ sở từ chối, lịch hết hạn hoặc bạn hủy trước giờ hẹn ít nhất {summary.cancellationHours ?? 4} giờ. Mất cọc nếu hủy muộn hoặc không đến.{terms?.count > 0 && ' Mỗi lần hẹn có cọc riêng. Hủy lần nào chỉ xử lý cọc của lần đó; ví phải đủ tiền cọc cho cả chuỗi.'}</p>
    </details>
    {readiness.reason === 'INSUFFICIENT_BALANCE' && <div className="mt-3"><InlineNotice tone="warning">Ví còn thiếu {money(readiness.shortfall)}. <Link className="font-semibold underline" to="/customer/wallet">Nạp thêm vào ví</Link> rồi quay lại để đặt lịch.</InlineNotice></div>}
    {blocked[readiness.reason] && <div className="mt-3"><InlineNotice tone="warning">{blocked[readiness.reason]}{readiness.reason === 'TERMS_UNAVAILABLE' && onRetry && <button type="button" className="ml-2 font-semibold underline" onClick={onRetry}>Thử lại</button>}</InlineNotice></div>}
  </section>;
}
