import React from 'react';
import { Clock } from 'lucide-react';

/**
 * Banner cảnh báo policy hủy/đổi lịch cho khách hàng.
 * Hiển thị trong tab "Sắp tới" để khách biết trước khi thao tác.
 */
export function CancellationPolicyCard({ bookings }) {
  if (!bookings?.length) return null;
  const nearest = bookings[0];
  const hoursBefore = (new Date(nearest.appointmentStartTime) - new Date()) / (60 * 60 * 1000);

  if (hoursBefore > 2) return null;

  return (
    <div className="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-2 text-xs">
      <Clock size={16} className="mt-0.5 shrink-0 text-amber-600" />
      <div>
        <p className="font-semibold text-amber-800 mb-0.5">
          Lưu ý chính sách hủy/đổi lịch
        </p>
        <p className="text-amber-700">
          Bạn có lịch sắp diễn ra trong vòng {Math.max(0, Math.round(hoursBefore))} giờ. Hủy trong
          2 giờ trước giờ hẹn có thể phát sinh phí. Đổi lịch phải trước tối thiểu 1 giờ.
        </p>
      </div>
    </div>
  );
}
