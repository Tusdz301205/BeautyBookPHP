import React from 'react';
import { AlertCircle, CalendarDays, RefreshCw } from 'lucide-react';
import { Button } from '../../ui';

export function CalendarLoadingSkeleton() {
  return (
    <div className="h-full animate-pulse bg-white p-4" aria-busy="true" aria-label="Đang tải lịch hẹn">
      <div className="mb-4 flex gap-3">
        <div className="h-10 w-40 rounded-lg bg-zinc-100" />
        <div className="h-10 w-32 rounded-lg bg-zinc-100" />
        <div className="h-10 w-32 rounded-lg bg-zinc-100" />
      </div>
      <div className="grid h-[560px] grid-cols-[72px_repeat(4,minmax(180px,1fr))] overflow-hidden rounded-xl border border-zinc-200">
        {Array.from({ length: 25 }, (_, index) => (
          <div key={index} className="border-b border-r border-zinc-100 bg-zinc-50/70" />
        ))}
      </div>
    </div>
  );
}

export function CalendarEmptyState() {
  return (
    <div className="flex min-h-[360px] flex-col items-center justify-center px-6 text-center">
      <span className="mb-4 grid h-12 w-12 place-items-center rounded-xl bg-pink-50 text-pink-700">
        <CalendarDays size={22} />
      </span>
      <p className="font-semibold text-zinc-900">Không có lịch hẹn trong khoảng thời gian này</p>
      <p className="mt-1 max-w-md text-sm text-zinc-500">Thử đổi ngày, chi nhánh hoặc bộ lọc để xem lịch khác.</p>
    </div>
  );
}

export function CalendarErrorState({ error, onRetry }) {
  return (
    <div className="flex min-h-[360px] flex-col items-center justify-center px-6 text-center" role="alert">
      <span className="mb-4 grid h-12 w-12 place-items-center rounded-xl bg-red-50 text-red-700">
        <AlertCircle size={22} />
      </span>
      <p className="font-semibold text-zinc-900">{error?.title || 'Không thể tải dữ liệu lịch hẹn'}</p>
      <p className="mt-1 max-w-md text-sm text-zinc-500">{error?.message || 'Vui lòng kiểm tra kết nối và thử lại.'}</p>
      {error?.retryable !== false && (
        <Button type="button" variant="secondary" onClick={onRetry} className="mt-4"><RefreshCw size={16} /> Thử lại</Button>
      )}
    </div>
  );
}
