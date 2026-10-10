import { useCallback, useEffect, useRef, useState } from 'react';
import { bookingsApi } from '../../../api/apiClient';
import { formatActualServiceTime } from '../../../utils/bookingActualTime';
import { Button } from '../../ui';

// Only mounted for an item with the authoritative operational capability.
// Actors/reasons are read from the protected endpoint, never the public projection.
export default function ActualTimeCorrectionHistory({ bookingId, itemId, revision, timezone }) {
  const [open, setOpen] = useState(false);
  const [entries, setEntries] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const sequence = useRef(0);
  const load = useCallback(async () => {
    const request = ++sequence.current;
    setLoading(true); setError(''); setEntries([]);
    try {
      const payload = await bookingsApi.actualTimeCorrectionHistory(bookingId, itemId);
      if (request === sequence.current) setEntries(Array.isArray(payload?.data) ? payload.data : []);
    } catch (historyError) {
      if (request === sequence.current) setError(historyError.message || 'Không thể tải lịch sử hiệu chỉnh.');
    } finally { if (request === sequence.current) setLoading(false); }
  }, [bookingId, itemId]);
  useEffect(() => {
    if (open) void load();
    return () => { sequence.current += 1; };
  }, [open, load, revision]);
  return <section className="mt-3">
    <Button size="sm" variant="ghost" className="h-auto !whitespace-normal text-left" aria-expanded={open} onClick={() => setOpen((current) => !current)}>Lịch sử bổ sung / hiệu chỉnh thời gian thực tế</Button>
    {open && <div className="mt-2 space-y-2">
      {loading ? <p role="status" className="text-xs text-zinc-600">Đang tải lịch sử hiệu chỉnh...</p> : error ? <div role="alert" className="text-xs text-red-700">{error} <button type="button" onClick={load} className="font-semibold underline">Thử lại</button></div> : entries.length === 0 ? <p className="text-xs text-zinc-600">Chưa có hiệu chỉnh thời gian thực tế.</p> : <ol className="space-y-2">{entries.map((entry) => <li key={entry.id || entry.version} className="rounded-lg border border-zinc-200 p-3 text-xs text-zinc-700">
        <p className="font-semibold">Hiệu chỉnh phiên bản {entry.version}</p>
        <p className="mt-1 break-words">Người hiệu chỉnh: {entry.actor?.fullName || entry.actorId || 'Chưa có dữ liệu'}</p>
        <p>Ghi nhận lúc: {formatActualServiceTime(entry.correctedAt, timezone) || 'Chưa có dữ liệu'}</p>
        <p className="mt-1">Trước hiệu chỉnh: {formatActualServiceTime(entry.oldActualStartedAt, timezone) || 'Chưa xác định'} → {formatActualServiceTime(entry.oldActualCompletedAt, timezone) || 'Chưa xác định'}</p>
        <p className="mt-1">Bắt đầu thực tế: {formatActualServiceTime(entry.actualStartedAt, timezone) || 'Chưa xác định'}</p>
        <p>Kết thúc thực tế: {formatActualServiceTime(entry.actualCompletedAt, timezone) || 'Chưa xác định'}</p>
        <p className="mt-1 whitespace-pre-wrap break-words">Lý do: {entry.reason || 'Chưa có dữ liệu'}</p>
      </li>)}</ol>}
    </div>}
  </section>;
}
