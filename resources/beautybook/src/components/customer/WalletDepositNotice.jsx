import React from 'react';
import { Link } from 'react-router-dom';
import { Banknote, Wallet } from 'lucide-react';
import { InlineNotice } from '../ui';
import { depositSummary } from '../../utils/walletDeposit';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' }) : '');

/** One line per occurrence when each is priced differently: its own date, price and deposit. */
function SeriesRows({ rows }) {
  return <ul className="mt-3 space-y-1 rounded-lg border border-[var(--bb-border)] px-3 py-2 text-sm" aria-label="Cọc từng lần hẹn">
    {rows.map((row) => <li key={row.index} className="flex justify-between gap-3"><span>Lần {row.index + 1} · {when(row.startAt)} <span className="text-[var(--bb-muted)]">(giá {money(row.totalVnd)})</span></span><span className="font-semibold tabular-nums">{money(row.depositVnd)}</span></li>)}
  </ul>;
}

const blocked = {
  NOT_IN_LAB: 'Tài khoản này chưa nằm trong danh sách thử nghiệm ví nên chưa thể đặt lịch có cọc.',
  TERMS_UNAVAILABLE: 'Không tải được điều khoản cọc. Vui lòng thử lại.',
};

/** The deposit the booking will hold from the wallet, and why a booking is blocked when it cannot. */
export default function WalletDepositNotice({ readiness, terms, wallet, loading, onRetry }) {
  if (!readiness?.required && !loading) return null;
  if (loading) return <p role="status" className="mb-4 text-sm">Đang kiểm tra cọc và số dư ví…</p>;
  const summary = depositSummary(terms, wallet);
  if (readiness.direct) return <section className="mb-5 rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface-subtle)] p-4" aria-label="Cọc chuyển khoản trực tiếp">
    <h2 className="flex items-center gap-2 text-sm font-bold"><Banknote size={16} aria-hidden="true" />Cọc 30% chuyển khoản trực tiếp cho cơ sở</h2>
    {terms?.count > 0 && !terms.uniform && <SeriesRows rows={terms.rows} />}
    {terms && <dl className="mt-3 space-y-1 text-sm">
      {terms.count > 0 && terms.uniform && <div className="flex justify-between"><dt>Mỗi lần hẹn ({terms.count} lần)</dt><dd className="tabular-nums">{money(terms.perVnd)}</dd></div>}
      <div className="flex justify-between"><dt>{terms.count > 0 ? 'Tổng cọc cho cả chuỗi' : 'Cọc chuyển khoản sau khi đặt lịch'}</dt><dd className="font-bold tabular-nums">{money(summary.deposit)}</dd></div>
      <div className="flex justify-between"><dt>Còn lại trả tại cơ sở</dt><dd className="tabular-nums">{money(summary.remaining)}</dd></div></dl>}
    <p className="mt-3 text-xs text-[var(--bb-muted)]">Sau khi gửi yêu cầu, bạn sẽ nhận thông tin tài khoản và nội dung chuyển khoản của cơ sở. Lịch chỉ được xác nhận sau khi cơ sở nhận cọc; quá hạn giữ chỗ mà chưa thanh toán thì lịch tự hết hạn. Cọc là một phần giá dịch vụ, được hoàn lại nếu cơ sở từ chối, lịch hết hạn hoặc bạn hủy trước hạn {summary.cancellationHours} giờ.</p>
    {readiness.reason === 'TERMS_UNAVAILABLE' && <div className="mt-3"><InlineNotice tone="warning">Không tải được điều khoản cọc. Vui lòng thử lại.{onRetry && <button type="button" className="ml-2 font-semibold underline" onClick={onRetry}>Tải lại</button>}</InlineNotice></div>}
  </section>;
  return <section className="mb-5 rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface-subtle)] p-4" aria-label="Cọc đặt lịch từ ví">
    <h2 className="flex items-center gap-2 text-sm font-bold"><Wallet size={16} aria-hidden="true" />Cọc 30% từ ví Live lab</h2>
    {terms?.count > 0 && !terms.uniform && <SeriesRows rows={terms.rows} />}
    {terms && <dl className="mt-3 space-y-1 text-sm">
      {terms.count > 0 && terms.uniform && <div className="flex justify-between"><dt>Mỗi lần hẹn ({terms.count} lần)</dt><dd className="tabular-nums">{money(terms.perVnd)}</dd></div>}
      <div className="flex justify-between"><dt>{terms.count > 0 ? 'Tổng cọc giữ từ ví cho cả chuỗi' : 'Cọc giữ từ ví khi đặt lịch'}</dt><dd className="font-bold tabular-nums">{money(summary.deposit)}</dd></div>
      <div className="flex justify-between"><dt>Còn lại trả tại cơ sở</dt><dd className="tabular-nums">{money(summary.remaining)}</dd></div>
      <div className="flex justify-between"><dt>Số dư ví hiện có</dt><dd className="tabular-nums">{money(wallet?.availableVnd)}</dd></div>
      {readiness.ok && <div className="flex justify-between text-[var(--bb-muted)]"><dt>Số dư sau khi đặt</dt><dd className="tabular-nums">{money(summary.availableAfter)}</dd></div>}
    </dl>}
    <p className="mt-3 text-xs text-[var(--bb-muted)]">Cọc là một phần giá dịch vụ, không phải phụ phí. Hoàn về ví nếu cơ sở từ chối, lịch hết hạn hoặc bạn hủy trước hạn {summary.cancellationHours ?? 4} giờ; mất cọc nếu hủy sát giờ hoặc vắng mặt.{terms?.count > 0 && ' Mỗi lần hẹn có cọc riêng; hủy một lần chỉ xử lý cọc của lần đó. Nếu ví không đủ cho cả chuỗi, không lần nào được tạo.'}</p>
    {readiness.reason === 'INSUFFICIENT_BALANCE' && <div className="mt-3"><InlineNotice tone="warning">Ví còn thiếu {money(readiness.shortfall)}. <Link className="font-semibold underline" to="/customer/wallet">Nạp thêm vào ví</Link> rồi quay lại để đặt lịch.</InlineNotice></div>}
    {blocked[readiness.reason] && <div className="mt-3"><InlineNotice tone="warning">{blocked[readiness.reason]}{readiness.reason === 'TERMS_UNAVAILABLE' && onRetry && <button type="button" className="ml-2 font-semibold underline" onClick={onRetry}>Thử lại</button>}</InlineNotice></div>}
  </section>;
}
