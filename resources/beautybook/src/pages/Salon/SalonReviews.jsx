import { ReviewPhotoGallery } from '../../components/customer/ReviewPhotoGallery';
import { useEffect, useMemo, useState } from 'react';
import { Flag, MessageSquareReply, Search, Star } from 'lucide-react';
import toast from 'react-hot-toast';
import { reviewsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { roleAt } from '../../utils/authScope';
import AbsaWorkspace from '../../components/reviews/AbsaWorkspace';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Input, MetricCard, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';

const statusMeta = { APPROVED: ['Đang hiển thị', 'success'], REPORTED: ['Đã báo cáo', 'warning'], HIDDEN: ['Đã ẩn bởi nền tảng', 'danger'] };
const formatDate = (value) => value ? new Date(value).toLocaleDateString('vi-VN') : '—';

function Stars({ value, size = 15 }) {
  const rating = Number(value || 0);
  return <span className="inline-flex" aria-label={`${rating} trên 5 sao`}>{[1, 2, 3, 4, 5].map((star) => <Star key={star} size={size} className={star <= rating ? 'fill-amber-400 text-amber-400' : 'text-zinc-300'} />)}</span>;
}

function Distribution({ distribution, total }) {
  return <Card className="p-4"><p className="text-sm font-bold">Phân bổ điểm</p><div className="mt-3 space-y-2">{[5, 4, 3, 2, 1].map((star) => { const count = Number(distribution?.[star] || 0); const width = total ? Math.round(count / total * 100) : 0; return <div key={star} className="grid grid-cols-[30px_1fr_38px] items-center gap-2 text-xs"><span>{star} ★</span><span className="h-2 overflow-hidden rounded-full bg-zinc-100"><span className="block h-full rounded-full bg-amber-400" style={{ width: `${width}%` }} /></span><span className="text-right tabular-nums text-[var(--bb-muted)]">{count}</span></div>; })}</div></Card>;
}

function ReviewCard({ review, canReply, canReport, onReply, onReport }) {
  const [label, tone] = statusMeta[review.status] || [review.status || '—', 'neutral'];
  const name = review.customer?.user?.fullName || 'Khách hàng';
  return <Card className="p-5">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div className="flex min-w-0 items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-pink-50 font-bold text-pink-700">{name.slice(0, 1).toUpperCase()}</span><div><h2 className="font-bold text-[var(--bb-ink)]">{name}</h2><div className="mt-1 flex flex-wrap items-center gap-2"><Stars value={review.overallRating} /><span className="text-xs text-[var(--bb-muted)]">{formatDate(review.createdAt)}</span></div></div></div><Badge tone={tone}>{label}</Badge></div>
    <p className="mt-4 text-sm leading-6 text-[var(--bb-ink-soft)]">{review.comment || <span className="italic text-[var(--bb-muted)]">Không có bình luận</span>}</p><ReviewPhotoGallery photos={review.photos} />
    {review.serviceRatings?.length > 0 && <div className="mt-4 flex flex-wrap gap-2">{review.serviceRatings.map((rating, index) => <span key={rating.id || index} className="inline-flex items-center gap-2 rounded-lg bg-[var(--bb-surface-subtle)] px-3 py-2 text-xs"><span>{rating.bookingService?.service?.name || 'Dịch vụ'}</span><Stars value={rating.rating} size={11} />{rating.staff?.fullName && <span className="text-[var(--bb-muted)]">· {rating.staff.fullName}</span>}</span>)}</div>}
    {review.booking && <p className="mt-3 text-xs text-[var(--bb-muted)]">{review.booking.branch?.name || 'Chi nhánh'} · Lịch hẹn {formatDate(review.booking.appointmentDate)}</p>}
    {review.businessReply?.content ? <div className="mt-4 rounded-lg border-l-4 border-pink-400 bg-pink-50 p-4"><p className="text-xs font-bold text-pink-800">Phản hồi từ cơ sở</p><p className="mt-1 text-sm leading-6 text-pink-950">{review.businessReply.content}</p></div> : canReply && <Button className="mt-4" size="sm" variant="secondary" onClick={() => onReply(review)}><MessageSquareReply size={15} />Trả lời</Button>}
    {canReport && review.status === 'APPROVED' && <Button className="mt-3" size="sm" variant="ghost" onClick={() => onReport(review)}><Flag size={15} />Báo cáo vi phạm</Button>}
  </Card>;
}

function ReplyDialog({ review, onClose, onSent }) {
  const [content, setContent] = useState('');
  const [saving, setSaving] = useState(false);
  useEffect(() => { if (review) setContent(''); }, [review]);
  const submit = async () => {
    if (!content.trim()) return;
    setSaving(true);
    try { await reviewsApi.reply(review.id, content.trim()); toast.success('Đã gửi phản hồi'); onSent(); }
    catch (error) { toast.error(error.message || 'Không thể gửi phản hồi'); }
    finally { setSaving(false); }
  };
  return <Dialog open={Boolean(review)} onClose={onClose} title="Trả lời đánh giá" description={review?.customer?.user?.fullName || 'Khách hàng'} footer={<><Button variant="secondary" onClick={onClose}>Hủy</Button><Button loading={saving} disabled={!content.trim()} onClick={submit}>Gửi phản hồi</Button></>}><Field label="Nội dung phản hồi" required hint="Phản hồi sẽ hiển thị công khai cùng đánh giá."><Textarea autoFocus value={content} onChange={(event) => setContent(event.target.value)} maxLength={2000} /></Field></Dialog>;
}

function ReportDialog({ review, onClose, onSent }) {
  const [form, setForm] = useState({ reason: '', category: 'OTHER', severity: 'MEDIUM' });
  const [saving, setSaving] = useState(false);
  useEffect(() => { if (review) setForm({ reason: '', category: 'OTHER', severity: 'MEDIUM' }); }, [review]);
  const submit = async () => {
    if (!form.reason.trim()) return;
    setSaving(true);
    try { await reviewsApi.report(review.id, { ...form, reason: form.reason.trim() }); toast.success('Đã gửi đánh giá để nền tảng kiểm tra'); onSent(); }
    catch (error) { toast.error(error.message || 'Không thể báo cáo đánh giá'); }
    finally { setSaving(false); }
  };
  return <Dialog open={Boolean(review)} onClose={onClose} title="Báo cáo đánh giá vi phạm" description="Nội dung nguy cơ cao sẽ được tạm ẩn ngay để nền tảng kiểm tra." footer={<><Button variant="secondary" onClick={onClose}>Hủy</Button><Button variant="danger" loading={saving} disabled={!form.reason.trim()} onClick={submit}>Gửi báo cáo</Button></>}><div className="grid gap-3 sm:grid-cols-2"><Field label="Phân loại" required><Select value={form.category} onChange={(event) => setForm((current) => ({ ...current, category: event.target.value }))}><option value="OTHER">Khác</option><option value="PII">Thông tin cá nhân</option><option value="ABUSE">Xúc phạm</option><option value="THREAT">Đe dọa</option><option value="HATE">Thù ghét</option><option value="SPAM">Spam / giả mạo</option></Select></Field><Field label="Mức độ" required><Select value={form.severity} onChange={(event) => setForm((current) => ({ ...current, severity: event.target.value }))}><option value="LOW">Thấp</option><option value="MEDIUM">Trung bình</option><option value="HIGH">Cao — tạm ẩn</option><option value="CRITICAL">Nghiêm trọng — tạm ẩn</option></Select></Field><Field className="sm:col-span-2" label="Lý do" required><Textarea autoFocus value={form.reason} onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))} maxLength={1000} placeholder="Mô tả nội dung và tác động cần nền tảng kiểm tra..." /></Field></div></Dialog>;
}

function OrdinaryReviews() {
  const can = useAuthStore((state) => state.can);
  const [allReviews, setAllReviews] = useState([]);
  const [filter, setFilter] = useState('ALL');
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reply, setReply] = useState(null);
  const [report, setReport] = useState(null);
  const canReply = can('review:moderate:tenant');
  const canReport = can('review:report:tenant');
  const load = async () => {
    setLoading(true); setError('');
    try { const response = await reviewsApi.getForManagement(); setAllReviews(Array.isArray(response) ? response : response?.data || []); }
    catch (loadError) { setError(loadError.message || 'Không thể tải đánh giá.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);
  const stats = useMemo(() => { const total = allReviews.length; const sum = allReviews.reduce((value, review) => value + Number(review.overallRating || 0), 0); const distribution = allReviews.reduce((value, review) => ({ ...value, [review.overallRating]: (value[review.overallRating] || 0) + 1 }), {}); return { total, avg: total ? sum / total : 0, distribution }; }, [allReviews]);
  const reviews = useMemo(() => allReviews.filter((review) => { const text = `${review.customer?.user?.fullName || ''} ${review.comment || ''}`.toLocaleLowerCase('vi'); return (filter === 'ALL' || review.status === filter) && (!query.trim() || text.includes(query.trim().toLocaleLowerCase('vi'))); }), [allReviews, filter, query]);
  return <Page>
    <PageHeader eyebrow="Chất lượng dịch vụ" title="Đánh giá khách hàng" description="Đánh giá được đăng sau lịch hẹn hợp lệ. Cơ sở có thể phản hồi hoặc báo cáo; chỉ nền tảng mới có quyền ẩn nội dung vi phạm." />
    <div className="grid gap-3 md:grid-cols-3"><MetricCard icon={Star} label="Điểm trung bình" value={stats.avg ? stats.avg.toFixed(1) : '—'} tone="warning" /><MetricCard icon={MessageSquareReply} label="Tổng đánh giá" value={stats.total} /><Distribution distribution={stats.distribution} total={stats.total} /></div>
    <Card className="grid gap-3 p-4 md:grid-cols-[1fr_220px]"><Field label="Tìm đánh giá"><div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" size={16} /><Input className="pl-9" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Khách hàng hoặc nội dung" /></div></Field><Field label="Trạng thái"><Select value={filter} onChange={(event) => setFilter(event.target.value)}><option value="ALL">Tất cả</option><option value="APPROVED">Đang hiển thị</option><option value="REPORTED">Đã báo cáo</option><option value="HIDDEN">Đã ẩn bởi nền tảng</option></Select></Field></Card>
    {loading ? <Card className="p-5"><Skeleton rows={6} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : reviews.length === 0 ? <Card><EmptyState icon={Star} title="Không có đánh giá phù hợp" /></Card> : <div className="space-y-3">{reviews.map((review) => <ReviewCard key={review.id} review={review} canReply={canReply} canReport={canReport} onReply={setReply} onReport={setReport} />)}</div>}
    <ReplyDialog review={reply} onClose={() => setReply(null)} onSent={async () => { setReply(null); await load(); }} />
    <ReportDialog review={report} onClose={() => setReport(null)} onSent={async () => { setReport(null); await load(); }} />
  </Page>;
}

function OwnerReviewWorkspace({ businessId }) {
  const [tab, setTab] = useState('analysis');
  return <Page>
    <div className="flex flex-wrap gap-2" role="tablist" aria-label="Đánh giá khách hàng" onKeyDown={event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=event.key==='Home'?'analysis':event.key==='End'?'reviews':tab==='analysis'?'reviews':'analysis';setTab(next);document.getElementById(next==='analysis'?'reviews-analysis-tab':'reviews-list-tab')?.focus();}}>
      <Button role="tab" id="reviews-analysis-tab" tabIndex={tab === 'analysis' ? 0 : -1} aria-controls="reviews-analysis-panel" aria-selected={tab === 'analysis'} variant={tab === 'analysis' ? 'primary' : 'secondary'} onClick={() => setTab('analysis')}>Phân tích đánh giá</Button>
      <Button role="tab" id="reviews-list-tab" tabIndex={tab === 'reviews' ? 0 : -1} aria-controls="reviews-list-panel" aria-selected={tab === 'reviews'} variant={tab === 'reviews' ? 'primary' : 'secondary'} onClick={() => setTab('reviews')}>Đánh giá & phản hồi</Button>
    </div>
    {tab === 'analysis' ? <div role="tabpanel" id="reviews-analysis-panel" aria-labelledby="reviews-analysis-tab"><AbsaWorkspace businessId={businessId}/></div>
      : <div role="tabpanel" id="reviews-list-panel" aria-labelledby="reviews-list-tab"><OrdinaryReviews/></div>}
  </Page>;
}

export function SalonReviews() {
  const user = useAuthStore(s => s.user), token = useAuthStore(s => s.accessToken);
  const businessId = useAuthStore(s => s.activeContext().tenantId);
  if (!businessId || !roleAt(user, 'BUSINESS_OWNER', businessId)) return <Page><EmptyState title="Chọn doanh nghiệp thuộc quyền Chủ doanh nghiệp để xem đánh giá"/></Page>;
  return <OwnerReviewWorkspace key={JSON.stringify([user?.id, token, businessId])} businessId={businessId}/>;
}

export default SalonReviews;
