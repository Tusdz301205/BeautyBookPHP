import { useEffect, useMemo, useState } from 'react';
import { Eye, EyeOff, Flag, Search, Star } from 'lucide-react';
import toast from 'react-hot-toast';
import { reviewsApi } from '../../api/apiClient';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Input, MetricCard, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';

const statusMeta = {
  APPROVED: ['Đang hiển thị', 'success'],
  REPORTED: ['Đã bị báo cáo', 'warning'],
  HIDDEN: ['Đã ẩn', 'danger'],
};

function Stars({ value }) {
  return <span className="inline-flex" aria-label={`${value || 0} trên 5 sao`}>{[1, 2, 3, 4, 5].map((star) => <Star key={star} size={13} className={star <= Number(value || 0) ? 'fill-amber-400 text-amber-400' : 'text-zinc-300'} />)}</span>;
}

function ReviewRow({ review, onAction, onAppeal }) {
  const [label, tone] = statusMeta[review.status] || [review.status || '—', 'neutral'];
  const latestReport = review.reports?.[0];
  const pendingAppeal = review.appeals?.find((item) => item.status === 'PENDING');
  return <article className="border-b border-[var(--bb-border)] p-5 last:border-b-0">
    <div className="flex flex-col gap-4 md:flex-row md:items-start">
      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-pink-50 font-bold text-pink-700">{review.customer?.user?.fullName?.slice(0, 1).toUpperCase() || '?'}</span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2"><strong>{review.customer?.user?.fullName || 'Khách hàng'}</strong><Stars value={review.overallRating} /><Badge tone={tone}>{label}</Badge><span className="text-xs text-[var(--bb-muted)]">{review.createdAt ? new Date(review.createdAt).toLocaleDateString('vi-VN') : '—'}</span></div>
        <p className="mt-2 text-sm leading-6 text-[var(--bb-ink-soft)]">{review.comment || <span className="italic text-[var(--bb-muted)]">Không có bình luận</span>}</p>
        {review.booking?.branch?.name && <p className="mt-2 text-xs text-[var(--bb-muted)]">{review.booking.branch.name} · {review.booking.bookingCode || ''}</p>}
        {latestReport && <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950"><strong>Lý do báo cáo: </strong>{latestReport.reason}<span className="ml-2 text-xs text-amber-700">({review.reports.length} báo cáo)</span></div>}
        {pendingAppeal && <div className="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950"><strong>Khiếu nại đang chờ: </strong>{pendingAppeal.reason}</div>}
        {review.businessReply?.content && <div className="mt-3 rounded-lg border-l-4 border-pink-400 bg-pink-50 p-3 text-sm"><strong className="text-pink-800">Phản hồi cơ sở: </strong>{review.businessReply.content}</div>}
      </div>
      <div className="flex shrink-0 flex-wrap gap-2">
        {review.status === 'REPORTED' && <><Button size="sm" variant="secondary" onClick={() => onAction(review, 'APPROVED')}><Eye size={14} />Giữ hiển thị</Button><Button size="sm" variant="danger" onClick={() => onAction(review, 'HIDDEN')}><EyeOff size={14} />Ẩn vi phạm</Button></>}
        {review.status === 'HIDDEN' && <Button size="sm" variant="secondary" onClick={() => onAction(review, 'APPROVED')}><Eye size={14} />Khôi phục</Button>}
        {pendingAppeal && <Button size="sm" onClick={() => onAppeal(review, pendingAppeal)}>Xử lý khiếu nại</Button>}
      </div>
    </div>
  </article>;
}

export function AdminReviewsModeration() {
  const [reviews, setReviews] = useState([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [confirm, setConfirm] = useState(null);
  const [decision, setDecision] = useState({ reasonCode: '', reason: '' });
  const [appealDecision, setAppealDecision] = useState(null);
  const [appealResolution, setAppealResolution] = useState('');
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setLoading(true); setError('');
    try {
      const response = await reviewsApi.getForManagement();
      setReviews(Array.isArray(response) ? response : response?.data || []);
    } catch (loadError) {
      setError(loadError.message || 'Không thể tải đánh giá cần hậu kiểm.');
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const queue = useMemo(() => reviews.filter((review) => ['REPORTED', 'HIDDEN'].includes(review.status) && (!search.trim() || `${review.customer?.user?.fullName || ''} ${review.comment || ''} ${review.booking?.branch?.name || ''} ${review.reports?.map((item) => item.reason).join(' ') || ''}`.toLocaleLowerCase('vi').includes(search.trim().toLocaleLowerCase('vi')))), [reviews, search]);
  const reported = reviews.filter((item) => item.status === 'REPORTED').length;
  const hidden = reviews.filter((item) => item.status === 'HIDDEN').length;

  const moderate = async () => {
    if (!confirm) return;
    setBusy(true);
    try {
      if (!decision.reasonCode || !decision.reason.trim()) { toast.error('Chọn mã lý do và nhập giải thích'); return; }
      await reviewsApi.moderate(confirm.review.id, { status: confirm.status, reasonCode: decision.reasonCode, reason: decision.reason.trim() });
      toast.success(confirm.status === 'HIDDEN' ? 'Đã ẩn đánh giá vi phạm' : 'Đã giữ/khôi phục đánh giá');
      setConfirm(null);
      await load();
    } catch (actionError) {
      toast.error(actionError.message || 'Không thể xử lý đánh giá');
    } finally { setBusy(false); }
  };
  const resolveAppeal = async (approve) => {
    if (!appealDecision || !appealResolution.trim()) return;
    setBusy(true);
    try {
      await reviewsApi.resolveAppeal(appealDecision.appeal.id, { approve, resolution: appealResolution.trim() });
      toast.success(approve ? 'Đã chấp nhận và khôi phục đánh giá' : 'Đã bác khiếu nại');
      setAppealDecision(null); setAppealResolution(''); await load();
    } catch (actionError) { toast.error(actionError.message || 'Không thể xử lý khiếu nại'); }
    finally { setBusy(false); }
  };

  return <Page>
    <PageHeader eyebrow="AN TOÀN NỀN TẢNG" title="Hậu kiểm đánh giá" description="Đánh giá hợp lệ được đăng ngay. Danh sách này chỉ chứa nội dung bị báo cáo và quyết định ẩn hoặc khôi phục của quản trị viên." />
    <div className="grid gap-3 sm:grid-cols-3"><MetricCard icon={Flag} label="Chờ xử lý" value={reported} tone="warning" /><MetricCard icon={EyeOff} label="Đã ẩn" value={hidden} tone="neutral" /><MetricCard icon={Star} label="Tổng trong hàng hậu kiểm" value={reported + hidden} /></div>
    <Card className="p-4"><Field label="Tìm trong hàng hậu kiểm"><div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" size={16} /><Input className="pl-9" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Khách hàng, cơ sở, nội dung hoặc lý do report" /></div></Field></Card>
    {loading ? <Card className="p-5"><Skeleton rows={7} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : queue.length === 0 ? <Card><EmptyState icon={Flag} title="Không có đánh giá cần hậu kiểm" description="Các đánh giá đang hiển thị bình thường không cần quản trị viên duyệt trước." /></Card> : <Card className="overflow-hidden">{queue.map((review) => <ReviewRow key={review.id} review={review} onAction={(item, status) => { setDecision({ reasonCode: '', reason: '' }); setConfirm({ review: item, status }); }} onAppeal={(item, appeal) => { setAppealResolution(''); setAppealDecision({ review: item, appeal }); }} />)}</Card>}
    <Dialog open={Boolean(confirm)} onClose={() => setConfirm(null)} title={confirm?.status === 'HIDDEN' ? 'Ẩn đánh giá vi phạm?' : 'Giữ đánh giá hiển thị?'} description={confirm?.review?.booking?.branch?.name || 'Toàn nền tảng'} footer={<><Button variant="secondary" onClick={() => setConfirm(null)}>Hủy</Button><Button variant={confirm?.status === 'HIDDEN' ? 'danger' : 'primary'} loading={busy} disabled={!decision.reasonCode || !decision.reason.trim()} onClick={moderate}>Xác nhận</Button></>}><div className="space-y-3"><p className="text-sm leading-6 text-[var(--bb-muted)]">Quyết định và lịch sử thay đổi sẽ được lưu bất biến.</p><Field label="Mã lý do" required><Select value={decision.reasonCode} onChange={(event) => setDecision((current) => ({ ...current, reasonCode: event.target.value }))}><option value="">Chọn lý do</option><option value="PII">Thông tin cá nhân</option><option value="ABUSE">Nội dung xúc phạm</option><option value="SPAM">Spam / giả mạo</option><option value="NO_VIOLATION">Không phát hiện vi phạm</option><option value="APPEAL_RESTORE">Khôi phục sau khiếu nại</option></Select></Field><Field label="Giải thích quyết định" required><Textarea value={decision.reason} onChange={(event) => setDecision((current) => ({ ...current, reason: event.target.value }))} /></Field></div></Dialog>
    <Dialog open={Boolean(appealDecision)} onClose={() => setAppealDecision(null)} title="Xử lý khiếu nại" description={appealDecision?.review?.booking?.branch?.name || 'Quyết định của Platform'} footer={<><Button variant="danger" loading={busy} disabled={!appealResolution.trim()} onClick={() => resolveAppeal(false)}>Bác khiếu nại</Button><Button loading={busy} disabled={!appealResolution.trim()} onClick={() => resolveAppeal(true)}>Chấp nhận & khôi phục</Button></>}><div className="space-y-3"><p className="rounded-lg bg-[var(--bb-surface-subtle)] p-3 text-sm">{appealDecision?.appeal?.reason}</p><Field label="Kết luận" required><Textarea value={appealResolution} onChange={(event) => setAppealResolution(event.target.value)} /></Field></div></Dialog>
  </Page>;
}

export default AdminReviewsModeration;
