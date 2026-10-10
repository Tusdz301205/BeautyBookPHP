import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { io } from 'socket.io-client';
import { addDays, addMonths, addWeeks, subDays, subMonths, subWeeks } from 'date-fns';
import { CalendarDays, ChevronLeft, ChevronRight, Filter, LayoutGrid, RefreshCw, Rows3, Search } from 'lucide-react';
import toast from 'react-hot-toast';
import { useSearchParams } from 'react-router-dom';
import { bookingsApi, refreshSession } from '../../../api/apiClient';
import { BOOKING_STATUSES } from '../../../constants/status';
import { useAuthStore } from '../../../store/authStore';
import { normalizeBooking, normalizeSchedulerResponse } from '../../../utils/bookingCalendar.adapter';
import { toUserFacingRequestError } from '../../../utils/requestError';
import { bindSchedulerSocketEvents } from '../../../utils/schedulerSocketEvents';
import { bookingCapabilities } from '../../../utils/authScope';
import {
  computeBookingStats,
  filterBookings,
  formatCalendarPeriod,
  getCalendarRange,
} from '../../../utils/bookingCalendar.utils';
import BookingDetailDrawer from './BookingDetailDrawer';
import { CalendarErrorState, CalendarLoadingSkeleton } from './CalendarStates';
import DayBookingsDrawer from './DayBookingsDrawer';
import SchedulerDayView from './SchedulerDayView';
import SchedulerMonthView from './SchedulerMonthView';
import SchedulerWeekView from './SchedulerWeekView';
import { Select } from '../../ui';
import { OperationalClock } from './OperationalTiming';
import { useOperationalRefresh } from '../../../hooks/useOperationalRefresh';
import { schedulerSocketUrl } from '../../../utils/socketEndpoint';

const VIEW_OPTIONS = [
  { id: 'day', label: 'Ngày', icon: Rows3 },
  { id: 'week', label: 'Tuần', icon: LayoutGrid },
  { id: 'month', label: 'Tháng', icon: CalendarDays },
];

const initialFilters = { query: '', staffId: '', serviceId: '', status: '', unfinished: false };

export default function SchedulerView({
  branchId,
  branchIds,
  branches = [],
  refreshKey = 0,
  viewMode = 'day',
  setViewMode,
  selectedDate,
  setSelectedDate,
  onStatsChange,
}) {
  const user = useAuthStore((state) => state.user);
  const accessToken = useAuthStore((state) => state.accessToken);
  const can = useAuthStore((state) => state.can);
  const [currentDate, setCurrentDate] = useState(selectedDate || new Date());
  const [staffList, setStaffList] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [filters, setFilters] = useState(initialFilters);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedBooking, setSelectedBooking] = useState(null);
  const [selectedDay, setSelectedDay] = useState(null);
  const [searchParams] = useSearchParams();
  const requestSequence = useRef(0);
  const authorizationEpoch = useRef(0);
  const deepLinkHandled = useRef('');

  const activeBranchIds = useMemo(() => {
    const ids = branchIds?.length ? branchIds : branchId ? [branchId] : [];
    return [...new Set(ids)].sort();
  }, [branchId, branchIds]);
  const branchKey = activeBranchIds.join(',');
  const branchNames = useMemo(() => new Map(branches.map((branch) => [branch.id, branch.branch_name || branch.name || 'Chi nhánh'])), [branches]);
  const roleCodes = useMemo(() => new Set([
    ...(user?.roles || []),
    ...(user?.scopes || []).map((scope) => scope.code),
  ]), [user]);
  const staffOnly = roleCodes.has('STAFF') && !['PLATFORM_ADMIN', 'BUSINESS_OWNER', 'RECEPTIONIST'].some((role) => roleCodes.has(role));

  useEffect(() => {
    if (selectedDate) setCurrentDate(selectedDate);
  }, [selectedDate]);

  const range = useMemo(() => getCalendarRange(viewMode, currentDate), [currentDate, viewMode]);

  useEffect(() => {
    authorizationEpoch.current += 1;
    requestSequence.current += 1;
    deepLinkHandled.current = '';
    setBookings([]);
    setStaffList([]);
    setSelectedBooking(null);
    setSelectedDay(null);
  }, [accessToken, branchKey]);

  const fetchData = useCallback(async ({ silent = false } = {}) => {
    const scopedBranchIds = branchKey ? branchKey.split(',') : [];
    const requestId = ++requestSequence.current;
    if (scopedBranchIds.length === 0) {
      setStaffList([]);
      setBookings([]);
      setLoading(false);
      return;
    }
    if (!silent) setLoading(true);
    setError(null);
    try {
      const payloads = scopedBranchIds.length > 1
        ? await bookingsApi.getSchedulerBatch(scopedBranchIds, range.start.toISOString(), range.end.toISOString())
        : [{ branchId: scopedBranchIds[0], payload: await bookingsApi.getScheduler(scopedBranchIds[0], range.start.toISOString(), range.end.toISOString()) }];
      if (requestId !== requestSequence.current) return;
      const multiBranch = scopedBranchIds.length > 1;
      const staffById = new Map();
      const bookingById = new Map();
      payloads.forEach(({ branchId: scopedBranchId, payload }) => {
        const normalized = normalizeSchedulerResponse(payload);
        const branchName = branchNames.get(scopedBranchId) || 'Chi nhánh';
        normalized.staff.forEach((staff) => staffById.set(staff.id, {
          ...staff,
          branchId: scopedBranchId,
          name: multiBranch ? `${staff.name} · ${branchName}` : staff.name,
        }));
        normalized.bookings.forEach((booking) => bookingById.set(booking.id, {
          ...booking,
          branchId: booking.branchId || scopedBranchId,
          branchName: booking.branchName || branchName,
        }));
      });
      setStaffList([...staffById.values()]);
      setBookings([...bookingById.values()]);
      setSelectedBooking((current) => current ? bookingById.get(current.id) || current : null);
      setSelectedDay((current) => current ? { ...current, bookings: current.bookings.map((item) => bookingById.get(item.id)).filter(Boolean) } : null);
    } catch (requestError) {
      if (requestId !== requestSequence.current) return;
      setError(toUserFacingRequestError(requestError, 'Không thể tải dữ liệu lịch hẹn'));
    } finally {
      if (!silent && requestId === requestSequence.current) setLoading(false);
    }
  }, [accessToken, branchKey, branchNames, range.end, range.start, refreshKey]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  useEffect(() => {
    setSelectedBooking(null);
    setSelectedDay(null);
  }, [branchKey]);

  useEffect(() => {
    const bookingId = searchParams.get('bookingId');
    if (!bookingId || deepLinkHandled.current === bookingId) return;
    let active = true;
    const epoch = authorizationEpoch.current;
    bookingsApi.getById(bookingId)
      .then((payload) => {
        if (!active || epoch !== authorizationEpoch.current) return;
        deepLinkHandled.current = bookingId;
        const normalized = normalizeBooking(payload);
        setSelectedBooking(normalized);
        if (normalized.startAt && !Number.isNaN(normalized.startAt.getTime())) {
          setCurrentDate(normalized.startAt);
          setSelectedDate?.(normalized.startAt);
        }
      })
      .catch((requestError) => {
        if (active && epoch === authorizationEpoch.current) toast.error(requestError.message || 'Không thể mở lịch hẹn từ thông báo.');
      });
    return () => { active = false; };
  }, [searchParams, setSelectedDate, accessToken]);

  useEffect(() => {
    if (!staffOnly || filters.staffId || staffList.length === 0) return;
    const ownProfile = staffList.find((staff) => staff.userId === user.id);
    setFilters((current) => ({ ...current, staffId: ownProfile?.id || '__none__' }));
  }, [filters.staffId, staffList, staffOnly, user]);

  useEffect(() => {
    if (!accessToken) return;
    const socketUrl = schedulerSocketUrl();
    const socket = io(socketUrl, {
      transports: ['websocket', 'polling'],
      auth: (callback) => callback({ token: useAuthStore.getState().accessToken }),
    });
    const refresh = () => fetchData({ silent: true });
    const clearScopedData = () => {
      authorizationEpoch.current += 1;
      requestSequence.current += 1;
      setBookings([]);
      setStaffList([]);
      setSelectedBooking(null);
      setSelectedDay(null);
      setLoading(false);
    };
    const authFailed = () => {
      clearScopedData();
      setError('Phiên hoặc quyền truy cập lịch đã thay đổi. Vui lòng tải lại hoặc đăng nhập lại.');
    };
    const authorizationChanged = async () => {
      clearScopedData();
      try {
        const result = await refreshSession();
        if (!useAuthStore.getState().setSession(result)) authFailed();
      } catch { authFailed(); useAuthStore.getState().clearSession(); }
    };
    const unbind = bindSchedulerSocketEvents(socket, { refresh, clearScopedData, authFailed, authorizationChanged, coalesceRefresh: true });
    return () => {
      unbind();
      socket.disconnect();
    };
  }, [fetchData, accessToken]);

  const refreshVisible = useCallback(() => fetchData({ silent: true }), [fetchData]);
  useOperationalRefresh(refreshVisible);

  const serviceOptions = useMemo(() => {
    const services = new Map();
    bookings.forEach((booking) => booking.services.forEach((service) => {
      if (service.id) services.set(service.id, service.name);
    }));
    return [...services].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name, 'vi'));
  }, [bookings]);

  const filteredBookings = useMemo(() => {
    const ownIds = new Set(staffList.filter((staff) => staff.userId === user?.id).map((staff) => staff.id));
    const visible = staffOnly ? bookings.filter((booking) => booking.services.some((item) => item.staffUserId === user?.id || ownIds.has(item.staffId))) : bookings;
    return filterBookings(visible, filters).filter((booking) => !filters.unfinished || (!['COMPLETED', 'CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'].includes(booking.status) && booking.services.some((item) => ['SCHEDULED', 'IN_PROGRESS'].includes(item.status))));
  }, [bookings, filters, staffList, staffOnly, user]);
  const stats = useMemo(() => computeBookingStats(filteredBookings), [filteredBookings]);
  const platformWorkspace = user?.workspace === 'PLATFORM' || roleCodes.has('PLATFORM_ADMIN');
  const selectedBranch = branches.find((branch) => branch.id === activeBranchIds[0]);
  const selectedRights = bookingCapabilities(user, { branchId: activeBranchIds[0], businessId: selectedBranch?.businessId });
  const canMove = !platformWorkspace && activeBranchIds.length === 1 && selectedRights.canAssign
    && (selectedRights.owner || can('booking:reschedule:branch', selectedRights.ctx));
  const canResize = !platformWorkspace && activeBranchIds.length === 1 && selectedRights.owner && selectedRights.canUpdate;

  useEffect(() => {
    onStatsChange?.(stats, formatCalendarPeriod(viewMode, currentDate));
  }, [currentDate, onStatsChange, stats, viewMode]);

  const changeDate = (date) => {
    setCurrentDate(date);
    setSelectedDate?.(date);
  };

  const navigate = (direction) => {
    const operations = {
      day: direction < 0 ? subDays : addDays,
      week: direction < 0 ? subWeeks : addWeeks,
      month: direction < 0 ? subMonths : addMonths,
    };
    changeDate(operations[viewMode](currentDate, 1));
  };

  const moveBooking = async (booking, startAt, endAt, staffId) => {
    if (!canMove) return;
    const previous = bookings;
    setBookings((items) => items.map((item) => item.id === booking.id
      ? { ...item, startAt, endAt, primaryStaffId: staffId, primaryStaffName: staffList.find((staff) => staff.id === staffId)?.name }
      : item));
    try {
      await bookingsApi.moveBooking(booking.id, startAt.toISOString(), endAt.toISOString(), staffId);
      toast.success('Đã cập nhật lịch hẹn');
      fetchData({ silent: true });
    } catch (requestError) {
      setBookings(previous);
      toast.error(requestError.message || 'Không thể di chuyển lịch hẹn');
    }
  };

  const resizeBooking = async (booking, endAt) => {
    if (!canResize) return;
    const previous = bookings;
    setBookings((items) => items.map((item) => item.id === booking.id ? { ...item, endAt } : item));
    try {
      await bookingsApi.resizeBooking(booking.id, endAt.toISOString());
      toast.success('Đã cập nhật thời lượng');
      fetchData({ silent: true });
    } catch (requestError) {
      setBookings(previous);
      toast.error(requestError.message || 'Không thể cập nhật thời lượng');
    }
  };

  const openDay = (date, dayBookings = []) => setSelectedDay({ date, bookings: dayBookings });

  return (
    <OperationalClock><div className="flex h-full min-h-0 flex-col bg-white">
      <div className="shrink-0 border-b border-zinc-200 bg-white px-3 py-3 sm:px-5">
        <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex items-center rounded-xl border border-zinc-200 bg-white">
              <IconButton label="Khoảng trước" onClick={() => navigate(-1)}><ChevronLeft size={18} /></IconButton>
              <button type="button" onClick={() => changeDate(new Date())} className="min-h-11 border-x border-zinc-200 px-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Hôm nay</button>
              <IconButton label="Khoảng sau" onClick={() => navigate(1)}><ChevronRight size={18} /></IconButton>
            </div>
            <p className="min-w-[190px] text-base font-bold text-zinc-950 sm:text-lg">{formatCalendarPeriod(viewMode, currentDate)}</p>
            <IconButton label="Làm mới lịch" onClick={() => fetchData()}><RefreshCw size={17} className={loading ? 'animate-spin' : ''} /></IconButton>
          </div>

          <div className="flex w-fit items-center rounded-xl bg-zinc-100 p-1" role="group" aria-label="Chế độ xem lịch">
            {VIEW_OPTIONS.map(({ id, label, icon: Icon }) => (
              <button key={id} type="button" onClick={() => setViewMode?.(id)} aria-pressed={viewMode === id} className={`flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold transition ${viewMode === id ? 'bg-white text-pink-700 shadow-sm' : 'text-zinc-600 hover:text-zinc-950'}`}>
                <Icon size={15} /> {label}
              </button>
            ))}
          </div>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <span className="flex min-h-10 items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-zinc-500"><Filter size={14} /> Bộ lọc</span>
          <label className="relative min-w-[210px] flex-1 sm:max-w-[280px]">
            <span className="sr-only">Tìm khách hàng hoặc mã lịch</span>
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400" />
            <input value={filters.query} onChange={(event) => setFilters((current) => ({ ...current, query: event.target.value }))} placeholder="Khách hàng hoặc mã lịch" className="min-h-10 w-full rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm font-medium text-zinc-700 placeholder:text-zinc-400" />
          </label>
          <FilterSelect label={staffOnly ? 'Lịch cá nhân' : 'Tất cả nhân viên'} value={filters.staffId} disabled={staffOnly} onChange={(staffId) => setFilters((current) => ({ ...current, staffId }))} options={staffList.map((staff) => ({ id: staff.id, name: staff.name }))} />
          <FilterSelect label="Tất cả dịch vụ" value={filters.serviceId} onChange={(serviceId) => setFilters((current) => ({ ...current, serviceId }))} options={serviceOptions} />
          <FilterSelect label="Tất cả trạng thái" value={filters.status} onChange={(status) => setFilters((current) => ({ ...current, status }))} options={Object.entries(BOOKING_STATUSES).map(([id, status]) => ({ id, name: status.label }))} />
          <label className="flex min-h-10 items-center gap-2 text-sm text-zinc-700"><input type="checkbox" checked={filters.unfinished} onChange={(event) => setFilters((current) => ({ ...current, unfinished: event.target.checked }))} />Chỉ lịch chưa kết thúc</label>
          <label className="flex flex-wrap items-center gap-2 text-xs text-zinc-600">Ngày cần kiểm tra (kể cả ngày trước)<input aria-label="Ngày cần kiểm tra" type="date" value={`${currentDate.getFullYear()}-${String(currentDate.getMonth() + 1).padStart(2, '0')}-${String(currentDate.getDate()).padStart(2, '0')}`} onChange={(event) => { if (event.target.value) { changeDate(new Date(`${event.target.value}T12:00:00`)); setViewMode?.('day'); } }} className="min-h-10 rounded-lg border border-zinc-200 px-2" /></label>
          {(filters.unfinished || filters.query || filters.serviceId || filters.status || (!staffOnly && filters.staffId)) && <button type="button" onClick={() => setFilters((current) => ({ ...initialFilters, staffId: staffOnly ? current.staffId : '' }))} className="min-h-10 px-2 text-sm font-semibold text-pink-700 hover:underline">Xóa lọc</button>}
          <span className="ml-auto text-xs font-medium text-zinc-500">{filteredBookings.length} lịch trong phạm vi đang xem</span>
        </div>
      </div>

      <div className="min-h-0 flex-1">
        {loading ? <CalendarLoadingSkeleton /> : error ? <CalendarErrorState error={error} onRetry={() => fetchData()} /> : (
          <>
            {filteredBookings.length === 0 && <div role="status" className="border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-center text-xs font-medium text-zinc-600">Không có lịch hẹn phù hợp; khung thời gian vẫn được giữ để bạn xem lịch trống.</div>}
            {viewMode === 'day' && <SchedulerDayView currentDate={currentDate} staffList={staffList} bookings={filteredBookings} onBookingClick={setSelectedBooking} onMoveBooking={canMove ? moveBooking : undefined} onResizeBooking={canResize ? resizeBooking : undefined} />}
            {viewMode === 'week' && <SchedulerWeekView currentDate={currentDate} bookings={filteredBookings} onBookingClick={setSelectedBooking} />}
            {viewMode === 'month' && <SchedulerMonthView currentDate={currentDate} bookings={filteredBookings} onDateClick={openDay} />}
          </>
        )}
      </div>

      <DayBookingsDrawer
        date={selectedDay?.date}
        bookings={selectedDay?.bookings || []}
        onClose={() => setSelectedDay(null)}
        onOpenBooking={(booking) => { setSelectedDay(null); setSelectedBooking(booking); }}
        onOpenDay={() => { changeDate(selectedDay.date); setSelectedDay(null); setViewMode?.('day'); }}
      />
      <BookingDetailDrawer booking={selectedBooking} onClose={() => setSelectedBooking(null)} onUpdated={() => { setSelectedBooking(null); fetchData({ silent: true }); }} />
    </div></OperationalClock>
  );
}

function IconButton({ label, onClick, children }) {
  return <button type="button" onClick={onClick} className="grid h-11 w-11 place-items-center rounded-xl text-zinc-600 transition hover:bg-zinc-100" aria-label={label} title={label}>{children}</button>;
}

function FilterSelect({ label, value, onChange, options, disabled = false }) {
  return (
    <Select value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className="max-w-[220px]" aria-label={label} searchable={options.length >= 8}>
      <option value="">{label}</option>
      {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
    </Select>
  );
}
