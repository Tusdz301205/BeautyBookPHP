import React from 'react';
import { InlineNotice } from '../ui';

export function BookingPolicyNotice({ policy, acknowledged = false, onAcknowledge, salon = false }) {
  if (!policy) return null;
  const name = policy.businessName || 'doanh nghiệp này';
  const active = policy.restriction?.active;
  return <div className="space-y-3" data-testid="booking-policy-notice">
    {(policy.score >= 2 || active || salon) && <InlineNotice tone={active ? 'danger' : policy.score >= 2 ? 'warning' : 'info'}>
      <p>Tại {name} trong 90 ngày gần nhất: {policy.lateCancellations} lần hủy sát giờ, {policy.noShows} lần không đến · {policy.score} điểm.</p>
      {active ? <p className="mt-2">Hạn chế tự đặt lịch đến {new Date(policy.restriction.endsAt).toLocaleString('vi-VN')}. Khách vẫn có thể liên hệ cơ sở để được hỗ trợ tạo lịch và sử dụng doanh nghiệp khác.</p>
        : policy.score === 2 ? <p className="mt-2">Đây là cảnh báo; bạn vẫn có thể đặt lịch bình thường.</p>
        : policy.acknowledgmentRequired ? <p className="mt-2">Vui lòng xác nhận đã hiểu cảnh báo trước khi tiếp tục đặt lịch.</p>
        : policy.score >= 4 ? <p className="mt-2">Hiện không có hạn chế tự đặt đang hiệu lực. Vi phạm mới có thể kích hoạt hạn chế theo chính sách.</p> : null}
    </InlineNotice>}
    {!salon && policy.acknowledgmentRequired && onAcknowledge && <label className="flex items-start gap-3 rounded-xl border border-amber-300 p-4 text-sm">
      <input type="checkbox" className="mt-1 h-4 w-4" checked={acknowledged} onChange={event => onAcknowledge(event.target.checked)} />
      <span>Tôi hiểu và tiếp tục đặt lịch</span>
    </label>}
  </div>;
}
