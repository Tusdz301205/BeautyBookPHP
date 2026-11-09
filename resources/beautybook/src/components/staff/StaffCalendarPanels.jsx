import { useCallback, useEffect, useRef, useState } from 'react';
import { staffApi } from '../../api/apiClient';
import { branchWallTimeToInstant } from '../../utils/branchSlotTime';
import { Button, Card, Dialog, EmptyState, ErrorState, Field, InlineNotice, Input, Skeleton, Textarea } from '../ui';

const days = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
const clock = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
const emptyShift = () => ({ startTime: '', endTime: '' });

function useCalendarRead(read) {
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState(null);
  useEffect(() => {
    let current = true;
    read().then(
      (value) => { if (current) setState({ read, attempt, value }); },
      (error) => { if (current) setState({ read, attempt, error: error.message || 'Không thể tải lịch nhân viên.' }); },
    );
    return () => { current = false; };
  }, [read, attempt]);
  const reload = () => setAttempt((value) => value + 1);
  return state?.read === read && state.attempt === attempt ? { ...state, reload } : { loading: true, reload };
}

function useCalendarWrite(onBusyChange) {
  const pending = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState(null);
  const run = async (operation) => {
    if (pending.current) return null;
    pending.current = true;
    setBusy(true);
    onBusyChange(true);
    setError('');
    try {
      const response = await operation();
      setResult(response);
      return response;
    } catch (failure) {
      setError(failure.message || 'Không thể lưu thay đổi.');
      return null;
    } finally {
      pending.current = false;
      setBusy(false);
      onBusyChange(false);
    }
  };
  return { busy, error, clearError: () => setError(''), result, run };
}

function formatDate(value, timezone) {
  return new Date(value).toLocaleString('vi-VN', { timeZone: timezone, dateStyle: 'short', timeStyle: 'short' });
}

function CalendarWarnings({ result, timezone }) {
  if (!result?.conflictCount) return null;
  return <div role="status" className="space-y-2">
    <InlineNotice tone="warning">
      <strong>{result.conflictCount} lượt dịch vụ đã đặt bị ảnh hưởng</strong>
      <p className="mt-1">Các lịch hẹn được giữ nguyên. Hãy kiểm tra từng lịch để xử lý với khách.</p>
      <ul className="mt-2 max-h-52 space-y-1 overflow-auto">
        {result.conflicts.map((item) => <li key={item.id}>Lịch #{item.bookingId} · {formatDate(item.startAt, timezone)}</li>)}
      </ul>
      {result.conflictsTruncated && <p className="mt-2">Hiện {result.conflicts.length} trong {result.conflictCount} lượt.</p>}
    </InlineNotice>
  </div>;
}

function weekDraft(value) {
  return days.map((_, dayOfWeek) => value.days.find((day) => day.dayOfWeek === dayOfWeek)
    || { dayOfWeek, isOff: true, intervals: [] });
}

function shiftErrors(day) {
  if (day.isOff) return [];
  const errors = day.intervals.map(({ startTime, endTime }) => {
    const start = clock.test(startTime) ? '' : 'Dùng giờ từ 00:00 đến 23:59.';
    let end = clock.test(endTime) || endTime === '24:00' ? '' : 'Dùng giờ từ 00:00 đến 24:00.';
    if (!start && !end && endTime <= startTime) end = 'Giờ kết thúc phải sau giờ bắt đầu.';
    return { start, end };
  });
  const sorted = day.intervals.map((shift, index) => ({ ...shift, index })).sort((a, b) => a.startTime.localeCompare(b.startTime));
  for (let index = 1; index < sorted.length; index++) {
    const item = sorted[index];
    if (!errors[item.index].start && item.startTime < sorted[index - 1].endTime) {
      errors[item.index].start = 'Ca làm bị chồng lấn.';
    }
  }
  return errors;
}

export function StaffHoursPanel({ staffId, scope, timezone, canEdit, onBusyChange }) {
  const read = useCallback(() => staffApi.getWorkingHours(staffId, scope), [staffId, scope]);
  const resource = useCalendarRead(read);
  const write = useCalendarWrite(onBusyChange);
  const [draft, setDraft] = useState(null);
  const [submitted, setSubmitted] = useState(false);
  if (resource.loading) return <Card className="p-5"><Skeleton rows={7} /></Card>;
  if (resource.error) return <ErrorState message={resource.error} onRetry={resource.reload} />;

  const week = draft || weekDraft(resource.value);
  const configured = write.result?.configured ?? resource.value.configured;
  const change = (dayOfWeek, update) => setDraft((previous) => (previous || weekDraft(resource.value)).map(
    (day) => day.dayOfWeek === dayOfWeek ? update(day) : day,
  ));
  const changeShift = (day, index, field, value) => change(day.dayOfWeek, (current) => ({
    ...current, intervals: current.intervals.map((shift, position) => position === index ? { ...shift, [field]: value } : shift),
  }));
  const save = async (event) => {
    event.preventDefault();
    if (!canEdit) return;
    setSubmitted(true);
    if (week.some((day) => shiftErrors(day).some(({ start, end }) => start || end))) return;
    const result = await write.run(() => staffApi.saveWorkingHours(staffId, week, scope));
    if (result) {
      setDraft(result.days);
      setSubmitted(false);
    }
  };
  return <Card className="space-y-4 p-4 sm:p-5">
    <div>
      <h2 className="font-bold">Giờ làm nhân viên</h2>
      <p className="mt-1 text-sm text-[var(--bb-muted)]">{configured ? 'Đã thiết lập ca riêng.' : 'Chưa có ca riêng. Nhân viên đang theo giờ chi nhánh.'}</p>
      {!configured && canEdit && <p className="mt-1 text-sm text-[var(--bb-muted)]">Các ngày bên dưới là bản nháp. Kiểm tra cả tuần trước khi lưu.</p>}
    </div>
    <CalendarWarnings result={write.result} timezone={timezone} />
    <form onSubmit={save} className="space-y-3" noValidate>
      {week.map((day) => {
        const name = days[day.dayOfWeek];
        const errors = submitted ? shiftErrors(day) : [];
        return <fieldset key={day.dayOfWeek} className="min-w-0 rounded-xl border border-[var(--bb-border)] p-3 sm:p-4">
          <legend className="px-1 text-sm font-semibold">{name}</legend>
          {canEdit ? <label className="mb-2 inline-flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" checked={day.isOff} disabled={write.busy} onChange={(event) => {
              const isOff = event.target.checked;
              change(day.dayOfWeek, (current) => ({ ...current, isOff, intervals: isOff ? [] : [emptyShift()] }));
            }} />
            Nghỉ cả ngày {name}
          </label> : day.isOff && <p className="text-sm">{configured ? 'Nghỉ cả ngày' : 'Theo giờ chi nhánh'}</p>}
          {!day.isOff && (canEdit ? <>
            {day.intervals.map((shift, index) => <div key={index} className="grid items-start gap-x-3 sm:grid-cols-[1fr_1fr_auto]">
              <Field label={'Bắt đầu ca ' + (index + 1) + ' · ' + name} required error={errors[index]?.start}>
                <Input type="time" disabled={write.busy} value={shift.startTime} onChange={(event) => changeShift(day, index, 'startTime', event.target.value)} />
              </Field>
              <Field label={'Kết thúc ca ' + (index + 1) + ' · ' + name} required error={errors[index]?.end}>
                <Input placeholder="HH:mm hoặc 24:00" maxLength={5} disabled={write.busy} value={shift.endTime} onChange={(event) => changeShift(day, index, 'endTime', event.target.value)} />
              </Field>
              <Button className="mb-3 sm:mt-6" variant="ghost" disabled={write.busy || day.intervals.length < 2}
                aria-label={'Xóa ca ' + (index + 1) + ' · ' + name}
                onClick={() => change(day.dayOfWeek, (current) => ({ ...current, intervals: current.intervals.filter((_, position) => position !== index) }))}>Xóa ca</Button>
            </div>)}
            <Button variant="secondary" disabled={write.busy || day.intervals.length >= 24}
              onClick={() => change(day.dayOfWeek, (current) => ({ ...current, intervals: [...current.intervals, emptyShift()] }))}>Thêm ca {name}</Button>
          </> : <ul className="space-y-1 text-sm">{day.intervals.map((shift) => <li key={shift.startTime}>{shift.startTime} – {shift.endTime}</li>)}</ul>)}
        </fieldset>;
      })}
      {write.error && <p role="alert" className="text-sm text-[var(--bb-danger)]">{write.error}</p>}
      {canEdit && <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" loading={write.busy}>Lưu giờ làm</Button>
        {write.result && <span role="status" className="text-sm">Đã lưu giờ làm.</span>}
      </div>}
    </form>
  </Card>;
}

function localInput(value, timezone) {
  const date = new Date(value);
  const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit',
    day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
  const parts = Object.fromEntries(formatter.formatToParts(date).map((part) => [part.type, part.value]));
  return [parts.year, parts.month, parts.day].join('-') + 'T' + [parts.hour, parts.minute, parts.second].join(':')
    + '.' + String(date.getMilliseconds()).padStart(3, '0');
}

function instant(value, timezone) {
  const parts = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::([0-5]\d)(?:\.(\d{1,3}))?)?$/.exec(value);
  const minute = parts && branchWallTimeToInstant(parts[1], timezone);
  if (!minute) return null;
  return new Date(+minute + Number(parts[2] || 0) * 1000 + Number((parts[3] || '').padEnd(3, '0')));
}

function period(from, to, timezone) {
  const start = instant(from, timezone);
  const end = instant(to, timezone);
  const errors = {};
  if (!start) errors.from = 'Chọn ngày và giờ bắt đầu hợp lệ.';
  if (!end) errors.to = 'Chọn ngày và giờ kết thúc hợp lệ.';
  if (start && end && (end <= start || end - start > 366 * 86400000)) errors.to = 'Giờ kết thúc phải sau giờ bắt đầu và cách tối đa 366 ngày.';
  return { errors, start: start?.toISOString(), end: end?.toISOString() };
}

export function StaffTimeOffPanel({ staffId, scope, timezone, canEdit, onBusyChange }) {
  const [filter, setFilter] = useState(() => {
    const day = localInput(Date.now(), timezone).slice(0, 10);
    const lastDay = new Date(Date.parse(day + 'T00:00:00Z') + 30 * 86400000).toISOString().slice(0, 10);
    return { from: day + 'T00:00', to: lastDay + 'T00:00' };
  });
  const [query, setQuery] = useState(() => {
    const { start, end } = period(filter.from, filter.to, timezone);
    return { from: start, to: end, page: 1 };
  });
  const [filterErrors, setFilterErrors] = useState({});
  const [editor, setEditor] = useState(null);
  const [formErrors, setFormErrors] = useState({});
  const [removing, setRemoving] = useState(null);
  const read = useCallback(() => staffApi.getTimeOff(staffId, query, scope), [staffId, query, scope]);
  const resource = useCalendarRead(read);
  const write = useCalendarWrite(onBusyChange);

  const applyFilter = (event) => {
    event.preventDefault();
    const { errors, start, end } = period(filter.from, filter.to, timezone);
    setFilterErrors(errors);
    if (!Object.keys(errors).length) setQuery({ from: start, to: end, page: 1 });
  };
  const edit = (item) => {
    write.clearError();
    setFormErrors({});
    setEditor(item ? { id: item.id, startsAt: localInput(item.startsAt, timezone), endsAt: localInput(item.endsAt, timezone), reason: item.reason ?? '' }
      : { startsAt: '', endsAt: '', reason: '' });
  };
  const change = (field) => (event) => setEditor((current) => ({ ...current, [field]: event.target.value }));
  const save = async (event) => {
    event.preventDefault();
    if (!canEdit) return;
    const { errors, start, end } = period(editor.startsAt, editor.endsAt, timezone);
    setFormErrors(errors);
    if (Object.keys(errors).length) return;
    const result = await write.run(() => staffApi.saveTimeOff(staffId, editor.id, { startsAt: start, endsAt: end, reason: editor.reason.trim() || null }, scope));
    if (result) {
      setEditor(null);
      resource.reload();
    }
  };
  const remove = async () => {
    if (!canEdit) return;
    const result = await write.run(() => staffApi.removeTimeOff(staffId, removing.id, scope));
    if (result) {
      setRemoving(null);
      if (query.page > 1 && resource.value?.items.length === 1) {
        setQuery((current) => ({ ...current, page: current.page - 1 }));
      } else {
        resource.reload();
      }
    }
  };
  const closeEditor = () => { if (!write.busy) setEditor(null); };
  const closeRemoval = () => { if (!write.busy) setRemoving(null); };
  const list = resource.value;
  return <Card className="space-y-4 p-4 sm:p-5">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><h2 className="font-bold">Lịch nghỉ nhân viên</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">Khoảng nghỉ chặn slot mới của nhân viên.</p></div>
      {canEdit && <Button disabled={write.busy} onClick={() => edit(null)}>Thêm lịch nghỉ</Button>}
    </div>
    <CalendarWarnings result={write.result} timezone={timezone} />
    {write.result && <p role="status" className="text-sm">{Object.hasOwn(write.result, 'removed') ? 'Đã cập nhật danh sách lịch nghỉ.' : 'Đã lưu lịch nghỉ.'}</p>}
    <form onSubmit={applyFilter} className="grid items-start gap-x-3 sm:grid-cols-[1fr_1fr_auto]" noValidate>
      <Field label="Xem từ ngày, giờ" error={filterErrors.from}><Input type="datetime-local" step="any" disabled={write.busy}
        value={filter.from} onChange={(event) => setFilter({ ...filter, from: event.target.value })} /></Field>
      <Field label="Xem đến ngày, giờ" error={filterErrors.to}><Input type="datetime-local" step="any" disabled={write.busy}
        value={filter.to} onChange={(event) => setFilter({ ...filter, to: event.target.value })} /></Field>
      <Button className="sm:mt-6" type="submit" variant="secondary" disabled={write.busy}>Lọc lịch nghỉ</Button>
    </form>
    {resource.loading ? <Skeleton rows={3} /> : resource.error ? <ErrorState message={resource.error} onRetry={resource.reload} />
      : list.items.length === 0 ? <EmptyState title="Chưa có lịch nghỉ trong khoảng này" />
        : <ul className="divide-y divide-[var(--bb-border)] rounded-xl border border-[var(--bb-border)]">
          {list.items.map((item) => <li key={item.id} className="flex flex-wrap items-start justify-between gap-3 p-3 sm:p-4">
            <div className="min-w-0 space-y-1 text-sm">
              <p><strong>Bắt đầu nghỉ</strong> · {formatDate(item.startsAt, timezone)}</p>
              <p><strong>Đi làm lại</strong> · {formatDate(item.endsAt, timezone)}</p>
              {item.reason && <p className="break-words text-[var(--bb-muted)]">{item.reason}</p>}
            </div>
            {canEdit && <div className="flex flex-wrap gap-2">
              <Button variant="secondary" disabled={write.busy} onClick={() => edit(item)}>Sửa lịch nghỉ</Button>
              <Button variant="ghost" disabled={write.busy} onClick={() => { write.clearError(); setRemoving(item); }}>Gỡ lịch nghỉ</Button>
            </div>}
          </li>)}
        </ul>}
    {list && list.total > list.limit && <div className="flex flex-wrap items-center gap-3">
      <Button variant="secondary" disabled={query.page <= 1 || write.busy} onClick={() => setQuery({ ...query, page: query.page - 1 })}>Trang trước</Button>
      <span className="text-sm">Trang {query.page} · {list.total} khoảng nghỉ</span>
      <Button variant="secondary" disabled={query.page * list.limit >= list.total || write.busy} onClick={() => setQuery({ ...query, page: query.page + 1 })}>Trang sau</Button>
    </div>}
    {canEdit && <Dialog open={Boolean(editor)} onClose={closeEditor} title={editor?.id ? 'Sửa lịch nghỉ' : 'Thêm lịch nghỉ'}
      description={'Nhập giờ địa phương của chi nhánh (' + timezone + ').'}
      footer={<><Button variant="secondary" disabled={write.busy} onClick={closeEditor}>Hủy</Button><Button type="submit" form="staff-time-off-form" loading={write.busy}>Lưu lịch nghỉ</Button></>}>
      {editor && <form id="staff-time-off-form" onSubmit={save} className="grid gap-3" noValidate>
        <Field label="Bắt đầu nghỉ" required error={formErrors.from}><Input type="datetime-local" step="any" disabled={write.busy} value={editor.startsAt} onChange={change('startsAt')} /></Field>
        <Field label="Đi làm lại" required error={formErrors.to}><Input type="datetime-local" step="any" disabled={write.busy} value={editor.endsAt} onChange={change('endsAt')} /></Field>
        <Field label="Lý do nghỉ"><Textarea maxLength={500} disabled={write.busy} value={editor.reason} onChange={change('reason')} /></Field>
        {write.error && <p role="alert" className="text-sm text-[var(--bb-danger)]">{write.error}</p>}
      </form>}
    </Dialog>}
    {canEdit && <Dialog open={Boolean(removing)} onClose={closeRemoval} title="Gỡ lịch nghỉ?"
      description={removing ? formatDate(removing.startsAt, timezone) + ' – ' + formatDate(removing.endsAt, timezone) : ''}
      footer={<><Button variant="secondary" disabled={write.busy} onClick={closeRemoval}>Giữ lại</Button><Button variant="danger" loading={write.busy} onClick={remove}>Xác nhận gỡ</Button></>}>
      {write.error && <p role="alert" className="text-sm text-[var(--bb-danger)]">{write.error}</p>}
    </Dialog>}
  </Card>;
}
