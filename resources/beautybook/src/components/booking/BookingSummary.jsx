import {DepositPreview} from './BookingDeposit';
import React from 'react';
import {CalendarDays, Clock3, Gift, MapPin, ReceiptText, Scissors, UserRound} from 'lucide-react';

const money=value=>Number(value).toLocaleString('vi-VN',{maximumFractionDigits:2})+' ₫';
export function BookingPriceBreakdown({quote,updating,error,onRetry,disabled,showDeposit=true}) {
  return <div className="bb-booking-bill__pricing" aria-busy={updating}>{showDeposit&&<DepositPreview deposit={quote?.walletDeposit||quote?.deposit}/>}
    <dl className="bb-booking-price"><div><dt>Giá gốc</dt><dd>{quote?money(quote.totalOriginalAmount):'—'}</dd></div><div><dt>Ưu đãi áp dụng</dt><dd className={quote&&Number(quote.totalDiscountAmount)>0?'bb-booking-bill__discount':''}>{quote?(Number(quote.totalDiscountAmount)>0?'−'+money(quote.totalDiscountAmount):'0 ₫'):'—'}</dd></div><div className="bb-booking-price__total"><dt>Tổng tạm tính</dt><dd>{quote?money(quote.totalEstimatedAmount):'—'}</dd></div></dl>
    <div className="bb-booking-bill__status">{error?<div role="alert"><span>{error}</span>{onRetry&&<button type="button" disabled={disabled} onClick={onRetry}>Kiểm tra lại</button>}</div>:updating?<p role="status">Đang cập nhật… Vui lòng chờ trước khi xác nhận.</p>:null}</div>
  </div>;
}

export default function BookingSummary({kind,item,branch,quantity,quote,updating,error,selected,variantName,staffName,formatTime,onRetry,disabled}) {
  const Icon=kind==='combo'?Gift:Scissors;
  return <div className="bb-booking-summary bb-booking-bill"><header className="bb-booking-bill__header"><span><ReceiptText size={18} aria-hidden="true"/>Tóm tắt lịch hẹn</span><small>Chưa thanh toán</small></header>
    <div className="bb-booking-bill__service"><span className="bb-booking-bill__icon" aria-hidden="true"><Icon size={21} strokeWidth={1.6}/></span><div><h3>{item.name}</h3><p>{variantName||'Tiêu chuẩn'}</p></div></div>
    <div className="bb-booking-bill__branch"><MapPin size={15} aria-hidden="true"/><div><strong>{branch.name}</strong><p>{branch.address}</p></div></div>
    <dl className="bb-booking-bill__facts"><div><dt>Số lượng</dt><dd>{quantity} {kind==='combo'?'combo':'lượt'}</dd></div><div><dt><Clock3 size={13} aria-hidden="true"/>Thời lượng</dt><dd>{quote?`${quote.durationMinutes} phút`:'Đang tính'}</dd></div></dl>
    <div className="bb-booking-bill__appointment"><div><CalendarDays size={15} aria-hidden="true"/><span><small>Ngày, giờ hẹn</small><strong>{selected?formatTime(selected.start):'Chưa chọn thời gian'}</strong></span></div><div><UserRound size={15} aria-hidden="true"/><span><small>Nhân viên thực hiện</small><strong>{selected?[...new Set(selected.assignments.map(a=>a.staffName))].join(' → '):staffName||'Cơ sở tự phân công'}</strong></span></div></div>
    <BookingPriceBreakdown quote={quote} updating={updating} error={error} onRetry={onRetry} disabled={disabled} showDeposit={false}/>
  </div>;
}
