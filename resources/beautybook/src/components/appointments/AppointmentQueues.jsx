import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarCheck, RefreshCw, ShieldAlert, UserRoundX } from 'lucide-react';
import { format } from 'date-fns';
import toast from 'react-hot-toast';
import { bookingsApi } from '../../api/apiClient';
import { normalizeBooking } from '../../utils/bookingCalendar.adapter';
import { ChangeRequestCard } from '../salon/ChangeRequestCard';
import { useAuthStore } from '../../store/authStore';
import { bookingCapabilities } from '../../utils/authScope';
import { rowsInBranches } from '../../utils/bookingAffordances';
import {
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  ErrorState,
  Field,
  Skeleton,
  Textarea,
  cx,
} from '../ui';

export default function AppointmentQueues({ branchIds = [], branches = [], onCountsChange, onChanged }) {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const canApproveChanges = can('change_request:approve:branch') || can('change_request:approve:tenant');
  const [queue, setQueue] = useState([]);
  const [requests, setRequests] = useState([]);
  const [tab, setTab] = useState('pending');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reject, setReject] = useState(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [queueResult, requestResult] = await Promise.all([
        bookingsApi.salonQueue(),
        canApproveChanges ? bookingsApi.pendingChangeRequests() : [],
      ]);
      setQueue(queueResult?.data ?? queueResult ?? []);
      setRequests(Array.isArray(requestResult) ? requestResult : requestResult?.data ?? []);
    } catch (loadError) {
      setError(loadError.message || 'Không thể tải các lịch cần xử lý.');
    } finally {
      setLoading(false);
    }
  }, [canApproveChanges]);

  useEffect(() => { load(); }, [load]);

  const scopedQueue = useMemo(
    () => rowsInBranches(queue, branchIds),
    [branchIds, queue],
  );
  const scopedRequests = useMemo(
    () => rowsInBranches(requests, branchIds),
    [branchIds, requests],
  );
  const unassigned = useMemo(
    () => scopedQueue.filter((booking) =>
      !(booking.services ?? []).some((service) => service.staff || service.staffId),
    ),
    [scopedQueue],
  );

  useEffect(() => {
    onCountsChange?.({
      pending: scopedQueue.length,
      changes: scopedRequests.length,
      unassigned: unassigned.length,
    });
  }, [onCountsChange, scopedQueue.length, scopedRequests.length, unassigned.length]);

  const refreshAll = async () => {
    await load();
    onChanged?.();
  };

  useEffect(() => { setReject(null); }, [branchIds]);
  const rightsFor = (raw) => {
    const normalized = normalizeBooking(raw);
    const branchId = normalized.branchId || raw.branch_id;
    return bookingCapabilities(user, { ...normalized, branchId,
      businessId: normalized.businessId || branches.find((branch) => branch.id === branchId)?.businessId });
  };

  const approve = async (id) => {
    const booking = scopedQueue.find((item) => (item.bookingId || item.id) === id);
    if (!booking || !rightsFor(booking).canUpdate) return;
    setBusy(id);
    try {
      await bookingsApi.updateStatus(id, 'CONFIRMED');
      toast.success('Đã xác nhận lịch');
      await refreshAll();
    } catch (updateError) {
      toast.error(updateError.message);
    } finally {
      setBusy('');
    }
  };

  const submitReject = async () => {
    if (!reject || !rightsFor(reject).canCancel || reason.trim().length < 3) return;
    setBusy(reject.id);
    try {
      await bookingsApi.updateStatus(reject.id, 'CANCELLED', undefined, reason.trim());
      toast.success('Đã từ chối lịch');
      setReject(null);
      await refreshAll();
    } catch (updateError) {
      toast.error(updateError.message);
    } finally {
      setBusy('');
    }
  };

  const visible = tab === 'unassigned' ? unassigned : scopedQueue;

  return (
    <div className="h-full overflow-y-auto bg-[var(--bb-canvas)] p-4 sm:p-5">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-bold text-[var(--bb-ink)]">Trung tâm hành động</h2>
          <p className="mt-1 text-sm text-[var(--bb-muted)]">Xác nhận lịch mới, xử lý yêu cầu thay đổi và phân công nhân viên trong phạm vi được cấp.</p>
        </div>
        <Button variant="secondary" size="sm" onClick={refreshAll}>
          <RefreshCw size={15} /> Tải lại
        </Button>
      </div>

      <div role="tablist" aria-label="Lịch hẹn cần xử lý" className="flex flex-wrap gap-1 border-b border-[var(--bb-border)] sm:flex-nowrap sm:overflow-x-auto">
        <QueueTab active={tab === 'pending'} onClick={() => setTab('pending')} icon={CalendarCheck}>Cần xác nhận ({scopedQueue.length})</QueueTab>
        {canApproveChanges && <QueueTab active={tab === 'requests'} onClick={() => setTab('requests')} icon={ShieldAlert}>Yêu cầu thay đổi ({scopedRequests.length})</QueueTab>}
        <QueueTab active={tab === 'unassigned'} onClick={() => setTab('unassigned')} icon={UserRoundX}>Chưa phân công ({unassigned.length})</QueueTab>
      </div>

      <div className="mt-4">
        {loading ? <Skeleton rows={6} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : tab === 'requests' ? (
          scopedRequests.length === 0
            ? <Card><EmptyState icon={ShieldAlert} title="Không có yêu cầu thay đổi" /></Card>
            : <div className="grid gap-4 xl:grid-cols-2">{scopedRequests.map((request) => { const { ctx } = rightsFor(request.booking || {}); return <ChangeRequestCard key={request.id} request={request} canApprove={can('change_request:approve:branch', ctx) || can('change_request:approve:tenant', ctx)} onUpdated={refreshAll} />; })}</div>
        ) : visible.length === 0 ? (
          <Card><EmptyState icon={tab === 'unassigned' ? UserRoundX : CalendarCheck} title={tab === 'unassigned' ? 'Không có lịch chưa phân công' : 'Không có lịch chờ xác nhận'} /></Card>
        ) : (
          <div className="grid gap-4 xl:grid-cols-2">
            {visible.map((booking) => <PendingCard
              key={booking.bookingId || booking.id}
              booking={booking}
              busy={busy}
              canUpdate={rightsFor(booking).canUpdate}
              canCancel={rightsFor(booking).canCancel}
              onApprove={approve}
              onReject={() => {
                setReject({ ...booking, id: booking.bookingId || booking.id });
                setReason('');
              }}
            />)}
          </div>
        )}
      </div>

      <Dialog
        open={Boolean(reject)}
        onClose={() => setReject(null)}
        title="Từ chối lịch hẹn"
        description={`Lịch ${reject?.bookingCode || reject?.id || '—'} sẽ được hủy. Khách hàng sẽ nhận được lý do từ chối.`}
        footer={<><Button variant="secondary" onClick={() => setReject(null)}>Hủy</Button><Button variant="danger" loading={busy === reject?.id} disabled={reason.trim().length < 3} onClick={submitReject}>Xác nhận từ chối</Button></>}
      >
        <Field label="Lý do" required><Textarea value={reason} onChange={(event) => setReason(event.target.value)} /></Field>
      </Dialog>
    </div>
  );
}

function QueueTab({ active, icon: Icon, children, ...props }) {
  return <button type="button" role="tab" aria-selected={active} className={cx('flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 text-sm font-semibold', active ? 'border-[var(--bb-brand)] text-[var(--bb-brand-strong)]' : 'border-transparent text-[var(--bb-muted)]')} {...props}><Icon size={16} />{children}</button>;
}

function PendingCard({ booking, busy, canUpdate, canCancel, onApprove, onReject }) {
  const id = booking.bookingId || booking.id;
  const normalized = normalizeBooking(booking);
  return (
    <Card as="article" className="overflow-hidden">
      <header className="flex items-start gap-3 border-b border-[var(--bb-border)] p-5">
        <div className="min-w-0 flex-1"><h3 className="font-bold">{booking.customer_name || 'Khách hàng'}</h3><p className="mt-1 text-xs text-[var(--bb-muted)]">{booking.services?.map((service) => service.name).filter(Boolean).join(', ') || '—'}</p></div>
        <Badge tone="warning">PENDING</Badge>
      </header>
      <div className="p-5"><p className="text-sm font-bold">{normalized.startAt ? format(normalized.startAt, 'dd/MM/yyyy · HH:mm') : '—'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">{booking.branch_name || booking.salon_name || '—'}</p><p className="bb-mono mt-3 text-xs text-[var(--bb-muted)]">{booking.bookingCode || booking.id}</p></div>
      {(canUpdate || canCancel) && <footer className="flex justify-end gap-2 border-t border-[var(--bb-border)] p-4">{canCancel && <Button variant="secondary" disabled={busy === id} onClick={onReject}>Từ chối</Button>}{canUpdate && <Button loading={busy === id} onClick={() => onApprove(id)}>Xác nhận</Button>}</footer>}
    </Card>
  );
}
