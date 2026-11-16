import React, { useEffect, useRef, useState } from 'react';
import { ImagePlus, Star, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { bookingsApi, reviewsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
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
  const [photos, setPhotos] = useState([]);
  const [photoError, setPhotoError] = useState('');
  const [reviewError, setReviewError] = useState('');
  const [progress, setProgress] = useState('');
  const [uncertain, setUncertain] = useState(false);
  const picker = useRef(null);
  const pending = useRef(false);
  const urls = useRef(new Set());
  const uploaded = useRef(new Map());
  const mounted = useRef(false);

  useEffect(() => {
    mounted.current = true;
    const previews = urls.current;
    return () => {
      mounted.current = false;
      previews.forEach((url) => URL.revokeObjectURL(url));
      previews.clear();
    };
  }, []);

  const updateService = (id, patch) => setServiceRatings((current) => ({ ...current, [id]: { ...current[id], ...patch } }));
  const choosePhotos = (event) => {
    const files = Array.from(event.target.files || []);
    event.target.value = '';
    if (!files.length || pending.current || uncertain) return;
    if (photos.length + files.length > 5) return setPhotoError('Chọn tối đa 5 ảnh trải nghiệm.');
    if (files.some((file) => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
      return setPhotoError('Hãy chọn ảnh JPG, PNG hoặc WebP.');
    }
    if (files.some((file) => !file.size || file.size > 8 * 1024 * 1024)) {
      return setPhotoError('Mỗi ảnh phải có nội dung và không vượt quá 8 MB.');
    }
    const added = files.map((file) => {
      const url = URL.createObjectURL(file);
      urls.current.add(url);
      return { file, url };
    });
    setPhotoError('');
    setPhotos([...photos, ...added]);
  };
  const removePhoto = (url) => {
    URL.revokeObjectURL(url);
    urls.current.delete(url);
    uploaded.current.delete(url);
    setPhotos((current) => current.filter((photo) => photo.url !== url));
    setPhotoError('');
  };
  const success = () => {
    toast.success('Cảm ơn bạn đã đánh giá!');
    onSuccess();
  };
  const reconcile = async (current, message) => {
    try {
      const latest = await bookingsApi.getById(booking.id);
      if (!current()) return;
      if (latest.review) return success();
      setUncertain(false);
      setReviewError(message || 'Chưa ghi nhận đánh giá. Bạn có thể thử gửi lại.');
    } catch {
      if (current()) {
        setUncertain(true);
        setReviewError('Chưa xác minh được đánh giá đã lưu hay chưa. Hãy kiểm tra lại trước khi gửi tiếp.');
      }
    }
  };
  const submit = async () => {
    if (pending.current) return;
    if (comment.trim().length < (policy.reviewMinLength || 0)) return toast.error(`Nhận xét cần ít nhất ${policy.reviewMinLength} ký tự`);
    pending.current = true;
    setSubmitting(true);
    setPhotoError('');
    setReviewError('');
    const actor = useAuthStore.getState().user?.id;
    const current = () => mounted.current && useAuthStore.getState().user?.id === actor;
    try {
      if (uncertain) return await reconcile(current);
      const photoIds = [];
      for (const [index, photo] of photos.entries()) {
        if (!current()) return;
        if (!uploaded.current.has(photo.url)) {
          setProgress(`Đang tải ảnh ${index + 1}/${photos.length}…`);
          try {
            const result = await reviewsApi.uploadPhoto(booking.id, photo.file);
            uploaded.current.set(photo.url, result.id);
          } catch (error) {
            if (current()) setPhotoError(`Ảnh ${index + 1} chưa tải được. ${error.message || 'Bạn có thể thử lại.'}`);
            return;
          }
        }
        photoIds.push(uploaded.current.get(photo.url));
      }
      if (!current()) return;
      setProgress('Đang gửi đánh giá…');
      await reviewsApi.create({
        bookingId: booking.id,
        overallRating: rating,
        comment,
        isAnonymous,
        ...(photoIds.length ? { photoIds } : {}),
        serviceRatings: services.map((item) => ({
          bookingServiceId: item.id,
          staffId: item.staffId || item.staff?.id || undefined,
          rating: serviceRatings[item.id]?.rating || 5,
          comment: serviceRatings[item.id]?.comment || undefined,
        })),
      });
      if (current()) success();
    } catch (error) {
      if (current()) await reconcile(current, error.message);
    } finally {
      pending.current = false;
      if (current()) {
        setSubmitting(false);
        setProgress('');
      }
    }
  };

  const close = () => { if (!pending.current) onClose(); };
  return <Dialog open onClose={close} title="Đánh giá cơ sở, dịch vụ và nhân viên" description={`Lịch hẹn ${booking.bookingCode || ''} đã hoàn thành. Mỗi lịch hẹn chỉ được gửi đánh giá một lần.`} footer={<><Button variant="secondary" onClick={close} disabled={submitting}>Để sau</Button><Button loading={submitting} onClick={submit}>{uncertain ? 'Kiểm tra đánh giá đã gửi' : 'Gửi đánh giá'}</Button></>}>
    <fieldset disabled={submitting || uncertain}>
    <fieldset><legend className="text-sm font-bold">Trải nghiệm tổng quan tại cơ sở</legend><Stars value={rating} onChange={setRating} label="Đánh giá tổng quan" /></fieldset>
    <Field label="Nhận xét chung" hint={`${comment.length}/500 ký tự`} className="mt-4"><Textarea maxLength={500} value={comment} onChange={(event) => setComment(event.target.value)} placeholder="Chia sẻ điều bạn hài lòng hoặc mong muốn cải thiện" /></Field>
    {policy.reviewMinLength > 0 && <p className="mt-1 text-xs text-[var(--bb-muted)]">Tối thiểu {policy.reviewMinLength} ký tự.</p>}
    {policy.allowAnonymousReview && <Field label="Hiển thị người đánh giá" className="mt-4"><Select value={isAnonymous ? 'anonymous' : 'named'} onChange={(event) => setIsAnonymous(event.target.value === 'anonymous')}><option value="named">Hiển thị tên</option><option value="anonymous">Ẩn danh</option></Select></Field>}
    <fieldset className="mt-5">
      <legend className="text-sm font-bold">Ảnh trải nghiệm (không bắt buộc)</legend>
      <p className="mt-1 text-sm text-[var(--bb-muted)]">Tối đa 5 ảnh JPG, PNG hoặc WebP, mỗi ảnh tối đa 8 MB.</p>
      <input ref={picker} type="file" accept="image/jpeg,image/png,image/webp" multiple hidden aria-label="Chọn ảnh trải nghiệm" onChange={choosePhotos} />
      <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
        {photos.map(({ url }, index) => (
          <div key={url} className="relative overflow-hidden rounded-lg bg-[var(--bb-surface-subtle)]">
            <img src={url} alt={`Ảnh đã chọn ${index + 1}`} className="aspect-square w-full object-cover" />
            <button type="button" onClick={() => removePhoto(url)} aria-label={`Gỡ ảnh ${index + 1}`} className="absolute right-0 top-0 grid size-11 place-items-center rounded-bl-lg bg-white text-[var(--bb-ink)] focus-visible:outline focus-visible:outline-2">
              <X size={17} aria-hidden="true" />
            </button>
          </div>
        ))}
        {photos.length < 5 && <button type="button" onClick={() => picker.current?.click()} className="flex aspect-square flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-[var(--bb-border)] text-sm focus-visible:outline focus-visible:outline-2">
          <ImagePlus size={20} aria-hidden="true" /> Thêm ảnh
        </button>}
      </div>
      {photoError && <p role="alert" className="mt-2 text-sm text-[var(--bb-danger)]">{photoError}</p>}
    </fieldset>
    {services.length > 0 && <section className="mt-5"><h3 className="text-sm font-bold">Đánh giá từng dịch vụ / nhân viên</h3><div className="mt-3 space-y-3">{services.map((item) => { const staffName = item.staff?.fullName || item.staff?.user?.fullName; return <Card key={item.id} className="p-4"><p className="font-bold">{item.service?.name || 'Dịch vụ'}</p><p className="text-xs text-[var(--bb-muted)]">{staffName ? `Thực hiện bởi ${staffName}` : 'Chưa ghi nhận nhân viên thực hiện'}</p><Stars value={serviceRatings[item.id]?.rating || 5} onChange={(value) => updateService(item.id, { rating: value })} label={`Đánh giá ${item.service?.name || 'dịch vụ'}`} /><Textarea className="mt-2" maxLength={300} value={serviceRatings[item.id]?.comment || ''} onChange={(event) => updateService(item.id, { comment: event.target.value })} placeholder="Nhận xét riêng cho dịch vụ/nhân viên" /></Card>; })}</div></section>}
    </fieldset>
    {progress && <p role="status" className="mt-3 text-sm text-[var(--bb-muted)]">{progress}</p>}
    {reviewError && <p role="alert" className="mt-3 text-sm text-[var(--bb-danger)]">{reviewError}</p>}
  </Dialog>;
}
