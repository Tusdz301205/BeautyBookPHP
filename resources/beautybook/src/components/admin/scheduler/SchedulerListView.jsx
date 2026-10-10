import { schedulerSocketUrl } from '../../../utils/socketEndpoint';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { io } from 'socket.io-client';
import { CalendarDays, Clock3, MapPin, Search, UserRound } from 'lucide-react';
import { bookingsApi, refreshSession, servicesApi } from '../../../api/apiClient';
import { bindSchedulerSocketEvents } from '../../../utils/schedulerSocketEvents';
import { formatBookingWallDate as asDate, formatBookingWallTime as asTime } from '../../../utils/bookingWallTime';
import { BOOKING_STATUSES, BOOKING_STATUS_LIST, labelToEnum } from '../../../constants/status';
import { useAuthStore } from '../../../store/authStore';
import { Badge, Button, Card, EmptyState, ErrorState, Field, Input, Select, Skeleton } from '../../ui';

import OperationalTiming, { OperationalClock } from './OperationalTiming';
import BookingDetailDrawer from './BookingDetailDrawer';
import { normalizeBooking } from '../../../utils/bookingCalendar.adapter';
import { operationalListRows } from '../../../utils/operationalListRows';
import { useOperationalRefresh } from '../../../hooks/useOperationalRefresh';

const toneByStatus = { PENDING: 'warning', CONFIRMED: 'info', CHECKED_IN: 'info', IN_PROGRESS: 'brand', COMPLETED: 'success', CANCELLED: 'danger', NO_SHOW: 'neutral' };
const statusInfo = (value) => { const code = BOOKING_STATUSES[value] ? value : labelToEnum(value); return { code, label: BOOKING_STATUSES[code]?.label || value || '—', tone: toneByStatus[code] || 'neutral' }; };

export default function SchedulerListView({ branchId, branchIds = [], zone = 'salon' }) {
  const accessToken = useAuthStore((state) => state.accessToken);
  const requestSequence = useRef(0);
  const authorizationEpoch = useRef(0);
  const branchKey = branchId || [...branchIds].sort().join(',');
  const emptyFilters = { search: '', customerQuery: '', status: '', categoryId: '', serviceId: '', source: '', dateFrom: '', dateTo: '' };
  const [bookings, setBookings] = useState([]); const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [pagination, setPagination] = useState({ page: 1, totalPages: 1, total: 0 }); const [draft, setDraft] = useState(emptyFilters); const [filters, setFilters] = useState(emptyFilters); const [selected, setSelected] = useState(null); const [categories, setCategories] = useState([]); const [services, setServices] = useState([]);
  const selectedBooking = useMemo(() => selected ? normalizeBooking(selected) : null, [selected]);
  const load = useCallback(async () => {
    const requestId = ++requestSequence.current;
    if (!accessToken || !branchKey) { setBookings([]); setSelected(null); setLoading(false); return; }
    setLoading(true); setError('');
    try {
      const response = await bookingsApi.getAll({ ...filters, branchId, branchIds: branchId ? undefined : branchKey, page: pagination.page, limit: 20 });
      if (requestId !== requestSequence.current) return;
      const rows = await operationalListRows(response.data || [], bookingsApi.getById, () => requestId === requestSequence.current);
      if (requestId !== requestSequence.current) return;
      setBookings(rows);
      setSelected((current) => current ? rows.find((row) => (row.bookingId || row.id) === (current.bookingId || current.id)) || null : null);
      setPagination((current) => ({ ...current, page: response.meta?.page || current.page, totalPages: response.meta?.totalPages || 1, total: response.meta?.total || 0 }));
    } catch (loadError) {
      if (requestId === requestSequence.current) setError(loadError.message || 'Không thể tải danh sách lịch hẹn.');
    } finally { if (requestId === requestSequence.current) setLoading(false); }
  }, [branchId, branchKey, accessToken, filters, pagination.page]);
  useEffect(() => {
    authorizationEpoch.current += 1;
    requestSequence.current += 1;
    setBookings([]); setSelected(null);
    return () => { authorizationEpoch.current += 1; requestSequence.current += 1; };
  }, [branchKey, accessToken]);
  useEffect(() => { load(); }, [load]);
  useOperationalRefresh(load);
  useEffect(() => {
    if (!accessToken || !branchKey) return;
    const socket = io(schedulerSocketUrl(), {
      transports: ['websocket', 'polling'],
      auth: (callback) => callback({ token: useAuthStore.getState().accessToken }),
    });
    const clearScopedData = () => {
      authorizationEpoch.current += 1; requestSequence.current += 1;
      setBookings([]); setSelected(null); setLoading(false);
    };
    const authFailed = () => { clearScopedData(); setError('Phiên hoặc quyền truy cập lịch đã thay đổi. Vui lòng tải lại hoặc đăng nhập lại.'); };
    const authorizationChanged = async () => {
      clearScopedData();
      const epoch = authorizationEpoch.current;
      try {
        const result = await refreshSession();
        if (epoch !== authorizationEpoch.current) return;
        if (!useAuthStore.getState().setSession(result)) authFailed();
      } catch {
        if (epoch !== authorizationEpoch.current) return;
        authFailed(); useAuthStore.getState().clearSession();
      }
    };
    const unbind = bindSchedulerSocketEvents(socket, { refresh: load, clearScopedData, authFailed, authorizationChanged, coalesceRefresh: true });
    return () => { unbind(); socket.disconnect(); };
  }, [accessToken, branchKey, load]);
  useEffect(() => { if (zone !== 'admin') return; Promise.all([servicesApi.getCategories(), servicesApi.getAll(branchId)]).then(([categoryRows, serviceRows]) => { setCategories(Array.isArray(categoryRows) ? categoryRows : []); setServices(Array.isArray(serviceRows) ? serviceRows : serviceRows?.data || []); }).catch(() => { setCategories([]); setServices([]); }); }, [branchId, zone]);
  const apply = (event) => { event.preventDefault(); setPagination((value) => ({ ...value, page: 1 })); setFilters(draft); };
  return <OperationalClock><div className="h-full overflow-y-auto bg-[var(--bb-canvas)] p-4 sm:p-5">
    <Card className="p-4"><form onSubmit={apply} className="grid gap-3 md:grid-cols-2 xl:grid-cols-4"><Field label="Mã lịch"><div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" size={16} /><Input className="pl-9" value={draft.search} onChange={(event) => setDraft({ ...draft, search: event.target.value })} placeholder="Mã lịch" /></div></Field>{zone === 'admin' && <Field label="Khách hàng / người dùng"><Input value={draft.customerQuery} onChange={(event) => setDraft({ ...draft, customerQuery: event.target.value })} placeholder="Tên, email hoặc điện thoại" /></Field>}<Field label="Trạng thái"><Select value={draft.status} onChange={(event) => setDraft({ ...draft, status: event.target.value })}><option value="">Tất cả</option>{BOOKING_STATUS_LIST.map((item) => <option key={item.enum} value={item.enum}>{item.label}</option>)}</Select></Field>{zone === 'admin' && <Field label="Nhóm dịch vụ"><Select value={draft.categoryId} onChange={(event) => setDraft({ ...draft, categoryId: event.target.value, serviceId: '' })}><option value="">Tất cả nhóm</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>}{zone === 'admin' && <Field label="Dịch vụ"><Select value={draft.serviceId} onChange={(event) => setDraft({ ...draft, serviceId: event.target.value })}><option value="">Tất cả dịch vụ</option>{services.filter((item) => !draft.categoryId || item.categoryId === draft.categoryId).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>}{zone === 'admin' && <Field label="Nguồn đặt"><Select value={draft.source} onChange={(event) => setDraft({ ...draft, source: event.target.value })}><option value="">Tất cả nguồn</option>{['ONLINE_WEB','WALK_IN','PHONE','STAFF_CREATED','ADMIN_CREATED'].map((value) => <option key={value} value={value}>{value}</option>)}</Select></Field>}<Field label="Từ ngày"><Input type="date" value={draft.dateFrom} onChange={(event) => setDraft({ ...draft, dateFrom: event.target.value })} /></Field><Field label="Đến ngày"><Input type="date" value={draft.dateTo} onChange={(event) => setDraft({ ...draft, dateTo: event.target.value })} /></Field><div className="flex items-end gap-2"><Button type="submit" variant="secondary">Áp dụng</Button><Button type="button" variant="ghost" onClick={() => { setDraft(emptyFilters); setFilters(emptyFilters); }}>Xóa lọc</Button></div></form></Card>
    {zone === 'admin' && !loading && bookings.length > 0 && <div className="mt-4 hidden overflow-x-auto rounded-xl border border-[var(--bb-border)] bg-white md:block"><table className="w-full min-w-[1100px] text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-left text-xs text-[var(--bb-muted)]"><tr><th className="p-3">Mã lịch / khách</th><th className="p-3">Cơ sở / chi nhánh</th><th className="p-3">Nhóm / dịch vụ / nhân viên</th><th className="p-3">Thời gian</th><th className="p-3">Nguồn</th><th className="p-3">Tổng tiền</th><th className="p-3">Trạng thái</th></tr></thead><tbody>{bookings.map((booking) => { const info = statusInfo(booking.statusEnum || booking.status); return <tr key={`admin-${booking.bookingId || booking.id}`} className="cursor-pointer border-t border-[var(--bb-border)] hover:bg-pink-50/40" onClick={() => setSelected(booking)}><td className="p-3"><code className="font-bold">{booking.id}</code><strong className="block">{booking.customer_name}</strong><span className="text-xs text-[var(--bb-muted)]">{booking.customer_phone}</span></td><td className="p-3">{booking.salon_name}<span className="block text-xs text-[var(--bb-muted)]">{booking.branch_name}</span></td><td className="p-3">{booking.service_category}<span className="block text-xs text-[var(--bb-muted)]">{booking.services?.map((item) => `${item.name}${item.staff ? ` · ${item.staff}` : ''}`).join(', ')}</span></td><td className="p-3">{asDate(booking.appointment_time)}<span className="block text-xs">{asTime(booking.appointment_start)}–{asTime(booking.appointment_end)}</span></td><td className="p-3"><code>{booking.source || '—'}</code></td><td className="p-3 font-bold">{Number(booking.total_amount || 0).toLocaleString('vi-VN')}₫</td><td className="p-3"><Badge tone={info.tone}>{info.label}</Badge><OperationalTiming booking={booking} /></td></tr>; })}</tbody></table></div>}
    <div className="mt-4">{loading ? <Card className="p-5"><Skeleton rows={8} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : bookings.length === 0 ? <Card><EmptyState icon={CalendarDays} title="Không có lịch hẹn phù hợp" /></Card> : <><div className={`${zone === 'admin' ? 'hidden' : 'hidden md:block'} overflow-hidden rounded-[var(--bb-radius-card)] border border-[var(--bb-border)] bg-white`}><table className="w-full text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs text-[var(--bb-muted)]"><tr><th className="p-3 text-left">Mã lịch</th><th className="p-3 text-left">Khách hàng</th><th className="p-3 text-left">Dịch vụ</th><th className="p-3 text-left">Ngày giờ</th><th className="p-3 text-left">Trạng thái</th></tr></thead><tbody>{bookings.map((booking) => { const info = statusInfo(booking.statusEnum || booking.status); return <tr key={booking.bookingId || booking.id} tabIndex="0" role="button" onClick={() => setSelected(booking)} onKeyDown={(event) => (event.key === 'Enter' || event.key === ' ') && setSelected(booking)} className="cursor-pointer border-t border-[var(--bb-border)] hover:bg-pink-50/40 focus:bg-pink-50"><td className="p-3"><code className="font-bold">{booking.id}</code></td><td className="p-3"><strong>{booking.customer_name}</strong><span className="block text-xs text-[var(--bb-muted)]">{booking.customer_phone}</span></td><td className="p-3">{booking.services?.[0]?.name || booking.service_category || '—'}{booking.services?.length > 1 && <span className="block text-xs text-[var(--bb-muted)]">+{booking.services.length - 1} dịch vụ</span>}</td><td className="p-3">{asDate(booking.appointment_time)}<span className="block text-xs text-[var(--bb-muted)]">{asTime(booking.appointment_start)}–{asTime(booking.appointment_end)}</span></td><td className="p-3"><Badge tone={info.tone}>{info.label}</Badge><OperationalTiming booking={booking} /></td></tr>; })}</tbody></table></div><div className="space-y-3 md:hidden">{bookings.map((booking) => { const info = statusInfo(booking.statusEnum || booking.status); return <button key={booking.bookingId || booking.id} onClick={() => setSelected(booking)} className="w-full rounded-[var(--bb-radius-card)] border border-[var(--bb-border)] bg-white p-4 text-left"><div className="flex items-start justify-between gap-2"><code className="font-bold">{booking.id}</code><Badge tone={info.tone}>{info.label}</Badge><OperationalTiming booking={booking} /></div><p className="mt-3 flex items-center gap-2 text-sm font-semibold"><UserRound size={15} />{booking.customer_name}</p><p className="mt-2 flex items-center gap-2 text-xs text-[var(--bb-muted)]"><Clock3 size={14} />{asDate(booking.appointment_time)} · {asTime(booking.appointment_start)}</p><p className="mt-2 flex items-center gap-2 text-xs text-[var(--bb-muted)]"><MapPin size={14} />{booking.branch_name || '—'}</p></button>; })}</div></>}</div>
    {!loading && !error && pagination.totalPages > 1 && <nav className="mt-4 flex items-center justify-between" aria-label="Phân trang lịch hẹn"><span className="text-sm text-[var(--bb-muted)]">{bookings.length}/{pagination.total} lịch</span><div className="flex items-center gap-2"><Button size="sm" variant="secondary" disabled={pagination.page <= 1} onClick={() => setPagination((value) => ({ ...value, page: value.page - 1 }))}>Trang trước</Button><span className="text-sm">{pagination.page}/{pagination.totalPages}</span><Button size="sm" variant="secondary" disabled={pagination.page >= pagination.totalPages} onClick={() => setPagination((value) => ({ ...value, page: value.page + 1 }))}>Trang sau</Button></div></nav>}
    <BookingDetailDrawer booking={selectedBooking} onClose={() => setSelected(null)} onUpdated={async () => { setSelected(null); await load(); }} />
  </div></OperationalClock>;
}
