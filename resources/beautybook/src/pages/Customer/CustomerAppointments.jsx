import React, { useCallback, useEffect, useState } from 'react';
import { differenceInHours, differenceInMinutes, format } from 'date-fns';
import { ArrowRight, ArrowRightLeft, Building2, CalendarCheck, CalendarPlus, CheckCircle2, Clock3, Compass, Eye, MapPin, Pause, Play, Repeat2, Star, XCircle } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { bookingsApi, branchesApi, platformPolicyApi, recurringApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { useBookingStore } from '../../store/bookingStore';
import { RescheduleModal } from '../../components/customer/RescheduleModal';
import { ReviewModal } from '../../components/customer/ReviewModal';
import { Badge, Button, Card, Dialog, ErrorState, Field, InlineNotice, Page, PageHeader, Skeleton, Textarea, cx } from '../../components/ui';
import { combineDateTime } from '../../utils/bookingCalendar.adapter';
import { customerCancellationMode, submitCustomerCancellation } from '../../utils/customerCancellation';

const TABS = [['upcoming', 'Sắp tới', CalendarCheck], ['completed', 'Hoàn thành', CheckCircle2], ['cancelled', 'Đã hủy', XCircle]];
const labels = { PENDING: 'Chờ xác nhận', CONFIRMED: 'Đã xác nhận', CHECKED_IN: 'Đã đến', IN_PROGRESS: 'Đang thực hiện', COMPLETED: 'Hoàn thành', CANCELLED: 'Đã hủy', NO_SHOW: 'Không đến', REJECTED: 'Đã từ chối', EXPIRED: 'Hết hạn giữ chỗ' };
const tones = { PENDING: 'warning', CONFIRMED: 'info', CHECKED_IN: 'info', IN_PROGRESS: 'brand', COMPLETED: 'success', CANCELLED: 'danger', NO_SHOW: 'neutral', REJECTED: 'danger', EXPIRED: 'neutral' };
const requestLabels = { PENDING: 'Đang chờ duyệt', APPROVED: 'Đã chấp nhận', REJECTED: 'Bị từ chối', EXPIRED: 'Đã hết hạn' };

export function CustomerAppointments() {
  const navigate = useNavigate();
  const accessToken = useAuthStore((state) => state.accessToken);
  const [tab, setTab] = useState('upcoming');
  const [bookings, setBookings] = useState([]);
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reschedule, setReschedule] = useState(null);
  const [review, setReview] = useState(null);
  const [cancel, setCancel] = useState(null);
  const [planCancel, setPlanCancel] = useState(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [recommendations, setRecommendations] = useState([]);
  const [recommendationsLoading, setRecommendationsLoading] = useState(true);
  const [recommendationsError, setRecommendationsError] = useState('');
  const [policy, setPolicy] = useState({ freeCancellationHours: 4, allowRescheduleRequests: true, maxRescheduleCountPerBooking: 2, reviewMinLength: 0, allowAnonymousReview: false });
  const setBranch = useBookingStore((state) => state.setBranch);
  const toggleService = useBookingStore((state) => state.toggleService);
  const load = useCallback(async () => {
    if (!accessToken) return;
    setLoading(true); setError('');
    try { const [result, platformPolicy, planRows] = await Promise.all([bookingsApi.myAppointments(tab), platformPolicyApi.getPublic(), recurringApi.mine()]); setBookings(result.data ?? result ?? []); setPolicy(platformPolicy); setPlans(planRows ?? []); }
    catch (requestError) { setError(requestError.message); }
    finally { setLoading(false); }
  }, [accessToken, tab]);
  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    const refreshWhenVisible = () => {
      if (document.visibilityState === 'visible') void load();
    };
    window.addEventListener('focus', refreshWhenVisible);
    document.addEventListener('visibilitychange', refreshWhenVisible);
    return () => {
      window.removeEventListener('focus', refreshWhenVisible);
      document.removeEventListener('visibilitychange', refreshWhenVisible);
    };
  }, [load]);
  useEffect(() => {
    let active = true;
    setRecommendationsLoading(true);
    setRecommendationsError('');
    branchesApi.getAll({ sort: 'popular', limit: 3 })
      .then((response) => {
        if (!active) return;
        const rows = Array.isArray(response) ? response : Array.isArray(response?.data) ? response.data : [];
        setRecommendations(rows);
      })
      .catch((requestError) => {
        if (active) setRecommendationsError(requestError.message || 'Không thể tải gợi ý cơ sở lúc này.');
      })
      .finally(() => { if (active) setRecommendationsLoading(false); });
    return () => { active = false; };
  }, []);
  const submitCancel = async () => {
    if (!cancel || !reason.trim()) return;
    setBusy(true);
    try { toast.success(await submitCustomerCancellation(bookingsApi, cancel, reason)); setCancel(null); setReason(''); await load(); }
    catch (requestError) { toast.error(requestError.message || 'Không thể hủy lịch'); }
    finally { setBusy(false); }
  };
  const changePlan = async (plan, action) => {
    setBusy(true);
    try { if (action === 'cancel') { await recurringApi.cancel(plan.id); setPlanCancel(null); } else await recurringApi.changeStatus(plan.id, action); toast.success(action === 'PAUSED' ? 'Đã tạm dừng chuỗi lịch' : action === 'ACTIVE' ? 'Đã tiếp tục chuỗi lịch' : 'Đã hủy chuỗi lịch'); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(false); }
  };
  return <Page className="max-w-6xl">
    <PageHeader eyebrow="Tài khoản khách hàng" title="Lịch hẹn của tôi" description="Theo dõi trạng thái thật của lịch và các yêu cầu thay đổi đang chờ cơ sở xử lý." />
    {plans.length > 0 && <section>
      <div className="mb-3 flex items-center gap-2"><Repeat2 size={18} className="text-[var(--bb-brand-strong)]" /><h2 className="font-bold">Chuỗi lịch lặp</h2></div>
      <div className="grid gap-3 lg:grid-cols-2">{plans.map((plan) => <Card key={plan.id} className="p-4">
        <div className="flex items-start justify-between gap-3">
          <div><p className="font-bold">{plan.combo?.name || plan.bookings?.[0]?.bookingServices?.map((item) => item.service?.name).filter(Boolean).join(', ') || 'Chuỗi dịch vụ'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{plan.branch?.business?.name} · {plan.branch?.name}</p></div>
          <Badge tone={plan.status === 'ACTIVE' ? 'success' : plan.status === 'FAILED' ? 'danger' : ['CREATING', 'PAUSED'].includes(plan.status) ? 'warning' : 'neutral'}>{{ ACTIVE: 'Đang chạy', PAUSED: 'Tạm dừng', CREATING: 'Đang tạo', FAILED: 'Tạo chưa hoàn tất', CANCELLED: 'Đã hủy', COMPLETED: 'Hoàn thành' }[plan.status] || 'Chưa xác định'}</Badge>
        </div>
        <div className="mt-3 flex flex-wrap gap-2 text-xs text-[var(--bb-muted)]"><Badge>{plan.frequency === 'WEEKLY' ? 'Mỗi tuần' : plan.frequency === 'BIWEEKLY' ? 'Mỗi 2 tuần' : 'Mỗi tháng'}</Badge><Badge>{plan.preferredTime}</Badge><Badge>{plan.bookings?.length || 0}/{plan.occurrenceCount} kỳ đã tạo</Badge></div>
        {plan.status === 'FAILED' && <div className="mt-3 space-y-2">
          <InlineNotice tone="warning">{plan.failureReason || 'Chuỗi lịch chưa được tạo hoàn tất. Hãy kiểm tra các kỳ đã có trước khi đặt thêm.'}</InlineNotice>
          {plan.bookings?.map((booking, index) => <Link key={booking.id} className="block py-2 text-sm font-semibold text-[var(--bb-brand-strong)] underline" to={`/customer/appointments/${booking.id}`}>Xem kỳ {index + 1} · {labels[booking.status] || 'Xem trạng thái'}</Link>)}
        </div>}
        {['ACTIVE', 'PAUSED'].includes(plan.status) && <div className="mt-4 flex flex-wrap gap-2 border-t border-[var(--bb-border)] pt-3"><Button size="sm" variant="secondary" disabled={busy} onClick={() => changePlan(plan, plan.status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE')}>{plan.status === 'ACTIVE' ? <Pause size={14} /> : <Play size={14} />}{plan.status === 'ACTIVE' ? 'Tạm dừng' : 'Tiếp tục'}</Button><Button size="sm" variant="ghost" className="text-[var(--bb-danger)]" disabled={busy} onClick={() => setPlanCancel(plan)}><XCircle size={14} />Hủy chuỗi</Button></div>}
      </Card>)}</div>
    </section>}
    <div role="tablist" aria-label="Nhóm lịch hẹn" className="bb-appointments-tabs flex gap-1 overflow-x-auto border-b border-[var(--bb-border)]">{TABS.map(([id, label, Icon]) => <button key={id} role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={cx('flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 text-sm font-semibold', tab === id ? 'border-[var(--bb-brand)] text-[var(--bb-brand-strong)]' : 'border-transparent text-[var(--bb-muted)]')}><Icon size={16} />{label}</button>)}</div>
    {tab === 'upcoming' && bookings.some((booking) => customerCancellationMode(booking) === 'request') && <InlineNotice tone="warning">Bạn có lịch còn dưới 4 giờ. Hãy gửi yêu cầu hủy sát giờ; lịch chỉ bị hủy khi cơ sở chấp nhận.</InlineNotice>}
    {loading ? <Skeleton rows={6} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !bookings.length ? <AppointmentEmpty tab={tab} recommendations={recommendations} recommendationsLoading={recommendationsLoading} recommendationsError={recommendationsError} onBook={() => navigate('/book')} onExplore={() => navigate('/explore')} /> : <div className="grid gap-4 lg:grid-cols-2">{bookings.map((booking) => <BookingCard key={booking.id} booking={booking} tab={tab} policy={policy} onCancel={() => { setCancel(booking); setReason(''); }} onReschedule={() => setReschedule(booking)} onReview={() => setReview(booking)} onRebook={() => { const serviceIds = (booking.bookingServices || []).map((item) => item.service?.id || item.serviceId).filter(Boolean); if (!booking.branchId || !serviceIds.length) return; setBranch(booking.branchId); serviceIds.forEach((serviceId) => toggleService(serviceId)); navigate('/book/staff'); }} />)}</div>}
    {reschedule && <RescheduleModal booking={reschedule} onClose={() => setReschedule(null)} onSuccess={() => { setReschedule(null); load(); }} />}
    {review && <ReviewModal booking={review} policy={policy} onClose={() => setReview(null)} onSuccess={() => { setReview(null); load(); }} />}
    <Dialog open={Boolean(cancel)} onClose={() => setCancel(null)} title="Hủy lịch hẹn" description={cancel ? `Mã lịch ${cancel.bookingCode || '—'}.` : ''} footer={<><Button variant="secondary" onClick={() => setCancel(null)}>Giữ lịch</Button><Button variant="danger" loading={busy} disabled={!reason.trim()} onClick={submitCancel}>{customerCancellationMode(cancel) === 'request' ? 'Gửi yêu cầu hủy' : 'Xác nhận hủy'}</Button></>}>
      {customerCancellationMode(cancel) === 'request' && <InlineNotice tone="warning">Còn dưới 4 giờ. Gửi yêu cầu hợp lệ sẽ ghi ngay +1 điểm tại doanh nghiệp này, kể cả khi yêu cầu hết hạn. Lịch và giờ đã đặt chỉ được giải phóng khi cơ sở xác nhận hủy.</InlineNotice>}
      <Field label="Lý do hủy" required className="mt-4"><Textarea value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Lý do này sẽ được gửi cho cơ sở" /></Field>
    </Dialog>
    <Dialog open={Boolean(planCancel)} onClose={() => setPlanCancel(null)} title="Hủy chuỗi lịch" description={planCancel ? `Chuỗi lịch tại ${planCancel.branch?.name || 'cơ sở này'}.` : ''} footer={<><Button variant="secondary" onClick={() => setPlanCancel(null)}>Giữ chuỗi lịch</Button><Button variant="danger" loading={busy} onClick={() => changePlan(planCancel, 'cancel')}>Xác nhận hủy chuỗi</Button></>}><InlineNotice tone="warning">Các kỳ sắp tới thuộc chuỗi sẽ bị hủy theo chính sách hiện tại. Các lịch đã hoàn thành không bị thay đổi.</InlineNotice></Dialog>
  </Page>;
}

function AppointmentEmpty({ tab, recommendations, recommendationsLoading, recommendationsError, onBook, onExplore }) {
  const content = tab === 'upcoming'
    ? { title: 'Dành chỗ cho lần chăm sóc tiếp theo', description: 'Bạn chưa có lịch sắp tới. Khám phá cơ sở phù hợp, chọn dịch vụ và thời gian thật trong luồng đặt lịch của BeautyBook.' }
    : tab === 'completed'
      ? { title: 'Hành trình làm đẹp của bạn sẽ hiện ở đây', description: 'Khi một lịch được cơ sở hoàn thành, bạn có thể xem lại chi tiết, đặt lại dịch vụ và gửi đánh giá tại trang này.' }
      : { title: 'Không có lịch hẹn bị hủy', description: 'Đây là một trạng thái tốt. Bạn có thể tiếp tục khám phá cơ sở hoặc bắt đầu một lịch hẹn mới.' };
  return <section className="bb-appointment-empty" aria-labelledby="appointment-empty-title">
    <div className="bb-appointment-empty__layout">
      <div className="bb-appointment-empty__art" aria-hidden="true"><span className="bb-appointment-empty__calendar"><CalendarCheck size={48} strokeWidth={1.5} /></span></div>
      <div className="bb-appointment-empty__copy">
        <h2 id="appointment-empty-title">{content.title}</h2>
        <p>{content.description}</p>
        <div className="bb-appointment-empty__actions">
          <Button onClick={onBook}><CalendarPlus size={17} />Đặt lịch mới</Button>
          <Button variant="secondary" onClick={onExplore}><Compass size={17} />Khám phá cơ sở</Button>
        </div>
      </div>
    </div>
    <div className="bb-appointment-empty__recommendations">
      <h3 className="text-sm font-bold">Cơ sở đang được quan tâm</h3>
      {recommendationsLoading ? <div className="bb-appointment-empty__rail" aria-label="Đang tải cơ sở gợi ý" aria-busy="true">{[0, 1, 2].map((item) => <div key={item} className="h-[4.5rem] animate-pulse rounded-[var(--bb-radius-control)] bg-[var(--bb-surface-subtle)] motion-reduce:animate-none" />)}</div> : recommendationsError ? <p role="alert" className="mt-2 text-sm text-[var(--bb-danger)]">{recommendationsError}</p> : recommendations.length ? <div className="bb-appointment-empty__rail">{recommendations.map((branch) => {
        const title = branch.branch_name || branch.name || 'Cơ sở làm đẹp';
        const address = [branch.address, branch.district].filter(Boolean).join(', ') || 'Địa chỉ đang được cập nhật';
        return <Link key={branch.id} className="bb-appointment-empty__salon" to={`/explore/branches/${branch.id}`}><span><Building2 size={19} /></span><span><strong>{title}</strong><small>{address}</small></span><ArrowRight size={16} aria-hidden="true" /></Link>;
      })}</div> : <p className="mt-2 text-sm text-[var(--bb-muted)]">Hiện chưa có cơ sở công khai phù hợp để đề xuất.</p>}
    </div>
  </section>;
}

function safeHours(booking) {
  const start = booking.appointmentStartTime ? combineDateTime(booking.appointmentDate, booking.appointmentStartTime) : null;
  return start ? differenceInHours(start, new Date()) : Infinity;
}

function BookingCard({ booking, tab, policy, onCancel, onReschedule, onReview, onRebook }) {
  const start = booking.appointmentStartTime ? combineDateTime(booking.appointmentDate, booking.appointmentStartTime) : null;
  const end = booking.appointmentEndTime ? combineDateTime(booking.appointmentDate, booking.appointmentEndTime) : null;
  const hours = safeHours(booking);
  const services = booking.bookingServices || [];
  const latestRequest = booking.changeRequests?.[0];
  const rescheduleCount = booking.changeRequests?.filter((request) => request.requestType === 'RESCHEDULE').length || 0;
  const rescheduleLimitReached = rescheduleCount >= policy.maxRescheduleCountPerBooking;
  const canReschedule = policy.allowRescheduleRequests && !rescheduleLimitReached && tab === 'upcoming' && hours >= 1 && latestRequest?.status !== 'PENDING';
  const cancellationMode = customerCancellationMode(booking);
  const canCancel = tab === 'upcoming' && ['direct', 'request'].includes(cancellationMode);
  const canReview = tab === 'completed' && booking.status === 'COMPLETED' && !booking.review;
  const duration = start && end ? Math.max(0, differenceInMinutes(end, start)) : services.reduce((sum, item) => sum + Number(item.service?.durationMinutes || 0), 0);
  return <Card as="article" className="bb-appointment-card flex flex-col overflow-hidden">
    <div className="flex items-start justify-between gap-3 border-b border-[var(--bb-border)] p-5"><div className="min-w-0"><h2 className="truncate font-bold">{booking.branch?.business?.name || booking.branch?.name || 'Cơ sở làm đẹp'}</h2><p className="mt-1 flex items-start gap-1 text-xs text-[var(--bb-muted)]"><MapPin size={13} className="mt-0.5 shrink-0" />{booking.branch?.addressLine || 'Chưa cập nhật địa chỉ'}</p></div><Badge tone={tones[booking.status] || 'neutral'}>{labels[booking.status] || booking.status || '—'}</Badge></div>
    <div className="flex-1 p-5"><div className="flex items-start gap-3 rounded-xl bg-[var(--bb-surface-subtle)] p-3"><CalendarCheck size={18} className="mt-0.5 text-[var(--bb-brand-strong)]" /><div><p className="text-sm font-bold">{start ? format(start, 'dd/MM/yyyy · HH:mm') : '—'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{end ? `Kết thúc ${format(end, 'HH:mm')}` : 'Chưa có giờ kết thúc'}</p></div></div>
      <div className="mt-4 divide-y divide-[var(--bb-border)]">{services.map((item) => <div key={item.id} className="flex justify-between gap-3 py-3 text-sm"><span>{item.service?.name || 'Dịch vụ'}{item.staff && <small className="block text-[var(--bb-muted)]">{item.staff.user?.fullName || item.staff.fullName}</small>}</span><strong>{Number(item.priceAtBooking || 0).toLocaleString('vi-VN')}₫</strong></div>)}</div>
      <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-[var(--bb-muted)]">{booking.bookingCode && <span>Mã lịch <span className="bb-mono font-semibold text-[var(--bb-ink)]">{booking.bookingCode}</span></span>}{duration > 0 && <Badge>{duration} phút</Badge>}</div>
      {latestRequest && <div className="mt-3"><InlineNotice tone={latestRequest.status === 'REJECTED' ? 'danger' : latestRequest.status === 'APPROVED' ? 'success' : 'warning'}><b>Yêu cầu thay đổi:</b> {requestLabels[latestRequest.status] || latestRequest.status}. {latestRequest.status === 'PENDING' && 'Lịch hiện tại chưa thay đổi.'}{latestRequest.reviewNote ? ` ${latestRequest.reviewNote}` : ''}</InlineNotice></div>}
      {rescheduleLimitReached && tab === 'upcoming' && <div className="mt-3"><InlineNotice tone="warning">Lịch hẹn này đã đạt số lần đổi lịch tối đa.</InlineNotice></div>}
      {booking.cancelReason && <div className="mt-3"><InlineNotice tone="danger"><b>Lý do hủy:</b> {booking.cancelReason}</InlineNotice></div>}
      {tab === 'upcoming' && hours < 4 && <div className="mt-3"><InlineNotice tone="warning"><span className="flex gap-2"><Clock3 size={15} />Lịch sắp diễn ra; vui lòng xem chính sách trước khi thay đổi.</span></InlineNotice></div>}
    </div>
    <footer className="flex flex-wrap gap-2 border-t border-[var(--bb-border)] p-4"><Link to={`/customer/appointments/${booking.id}`} className="inline-flex min-h-11 items-center gap-2 rounded-[var(--bb-radius-control)] border border-[var(--bb-border)] bg-white px-3 text-xs font-semibold hover:bg-[var(--bb-surface-subtle)]"><Eye size={14} />Chi tiết</Link>{canReschedule && <Button variant="secondary" size="sm" onClick={onReschedule}><ArrowRightLeft size={14} />Yêu cầu đổi lịch</Button>}{canCancel && <Button variant="secondary" size="sm" className="text-[var(--bb-danger)]" onClick={onCancel}><XCircle size={14} />{cancellationMode === 'request' ? 'Yêu cầu hủy sát giờ' : 'Hủy lịch'}</Button>}{canReview && <Button size="sm" onClick={onReview}><Star size={14} />Đánh giá</Button>}{tab !== 'upcoming' && services.length > 0 && <Button variant="secondary" size="sm" onClick={onRebook}><CalendarPlus size={14} />Đặt lại</Button>}</footer>
  </Card>;
}

export default CustomerAppointments;
