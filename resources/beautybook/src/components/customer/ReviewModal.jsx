import React, { useState } from 'react';
import { Star } from 'lucide-react';
import toast from 'react-hot-toast';
import { reviewsApi } from '../../api/apiClient';
import { Button, Card, Dialog, Field, Select, Textarea, cx } from '../ui';

function Stars({ value, onChange, label }) {
  return <div className="mt-2 flex gap-1" role="radiogroup" aria-label={label}>{[1, 2, 3, 4, 5].map((score) => <button key={score} type="button" role="radio" aria-checked={value === score} aria-label={`${label}: ${score} sao`} onClick={() => onChange(score)} className={cx('grid h-10 w-10 place-items-center rounded-lg', value === score && 'bg-amber-50')}><Star size={23} fill={score <= value ? '#f59e0b' : 'transparent'} color={score <= value ? '#f59e0b' : '#a1a1aa'} /></button>)}</div>;
}

export function ReviewModal({ booking, policy = {}, onClose, onSuccess }) {
  const services = booking.bookingServices || [];
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  const [serviceRatings, setServiceRatings] = useState(() => Object.fromEntries(services.map((item) => [item.id, { rating: 5, comment: '' }])));
  const [submitting, setSubmitting] = useState(false);
  const [isAnonymous, setIsAnonymous] = useState(false);

  const updateService = (id, patch) => setServiceRatings((current) => ({ ...current, [id]: { ...current[id], ...patch } }));
  const submit = async () => {
    if (comment.trim().length < (policy.reviewMinLength || 0)) return toast.error(`Nhận xét cần ít nhất ${policy.reviewMinLength} ký tự`);
    setSubmitting(true);
    try {
      await reviewsApi.create({
        bookingId: booking.id,
        overallRating: rating,
        comment,
        isAnonymous,
        serviceRatings: services.map((item) => ({
          bookingServiceId: item.id,
          staffId: item.staffId || item.staff?.id || undefined,
          rating: serviceRatings[item.id]?.rating || 5,
          comment: serviceRatings[item.id]?.comment || undefined,
        })),
      });
      toast.success('Cảm ơn bạn đã đánh giá!');
      onSuccess();
    } catch (error) {
      toast.error(error.message || 'Gửi đánh giá thất bại');
    } finally { setSubmitting(false); }
  };

  return <Dialog open onClose={onClose} title="Đánh giá cơ sở, dịch vụ và nhân viên" description={`Lịch hẹn ${booking.bookingCode || ''} đã hoàn thành. Mỗi booking chỉ được gửi đánh giá một lần.`} footer={<><Button variant="secondary" onClick={onClose}>Để sau</Button><Button loading={submitting} onClick={submit}>Gửi đánh giá</Button></>}>
    <fieldset><legend className="text-sm font-bold">Trải nghiệm tổng quan tại cơ sở</legend><Stars value={rating} onChange={setRating} label="Đánh giá tổng quan" /></fieldset>
    <Field label="Nhận xét chung" hint={`${comment.length}/500 ký tự`} className="mt-4"><Textarea maxLength={500} value={comment} onChange={(event) => setComment(event.target.value)} placeholder="Chia sẻ điều bạn hài lòng hoặc mong muốn cải thiện" /></Field>
    {policy.reviewMinLength > 0 && <p className="mt-1 text-xs text-[var(--bb-muted)]">Tối thiểu {policy.reviewMinLength} ký tự.</p>}
    {policy.allowAnonymousReview && <Field label="Hiển thị người đánh giá" className="mt-4"><Select value={isAnonymous ? 'anonymous' : 'named'} onChange={(event) => setIsAnonymous(event.target.value === 'anonymous')}><option value="named">Hiển thị tên</option><option value="anonymous">Ẩn danh</option></Select></Field>}
    {services.length > 0 && <section className="mt-5"><h3 className="text-sm font-bold">Đánh giá từng dịch vụ / nhân viên</h3><div className="mt-3 space-y-3">{services.map((item) => { const staffName = item.staff?.fullName || item.staff?.user?.fullName; return <Card key={item.id} className="p-4"><p className="font-bold">{item.service?.name || 'Dịch vụ'}</p><p className="text-xs text-[var(--bb-muted)]">{staffName ? `Thực hiện bởi ${staffName}` : 'Chưa ghi nhận nhân viên thực hiện'}</p><Stars value={serviceRatings[item.id]?.rating || 5} onChange={(value) => updateService(item.id, { rating: value })} label={`Đánh giá ${item.service?.name || 'dịch vụ'}`} /><Textarea className="mt-2" maxLength={300} value={serviceRatings[item.id]?.comment || ''} onChange={(event) => updateService(item.id, { comment: event.target.value })} placeholder="Nhận xét riêng cho dịch vụ/nhân viên" /></Card>; })}</div></section>}
  </Dialog>;
}
