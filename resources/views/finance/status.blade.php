@php
    $statusLabels = [
        'PENDING' => 'Đang chờ', 'ACTIVE' => 'Đang hoạt động', 'INACTIVE' => 'Chưa kích hoạt',
        'PAUSED' => 'Tạm dừng', 'EXPIRED' => 'Hết hạn', 'REVOKED' => 'Đã thu hồi',
        'VERIFIED' => 'Đã xác minh', 'REJECTED' => 'Đã từ chối', 'APPROVED' => 'Đã duyệt',
        'PROCESSING' => 'Đang xử lý', 'COMPLETED' => 'Đã hoàn tất', 'CANCELLED' => 'Đã hủy',
        'FAILED' => 'Chưa thành công', 'DUE' => 'Chưa thanh toán', 'PAID' => 'Đã thanh toán',
        'AVAILABLE' => 'Có thể sử dụng', 'RESERVED' => 'Đã giữ lượt', 'REDEEMED' => 'Đã sử dụng',
        'DRAFT' => 'Bản nháp', 'REVIEW' => 'Đang đối soát', 'ISSUED' => 'Đã phát hành',
        'OVERDUE' => 'Quá hạn', 'WAITING' => 'Đang chờ lịch', 'OFFERED' => 'Đã có đề nghị',
        'ACCEPTED' => 'Đã tiếp nhận', 'RESOLVED' => 'Đã xử lý', 'ESCALATED' => 'Cần xử lý ưu tiên',
        'PENDING_NEW_OWNER_ACCEPTANCE' => 'Chờ chủ mới tiếp nhận', 'UNDER_REVIEW' => 'Đang thẩm định',
        'NEED_MORE_INFO' => 'Cần bổ sung hồ sơ', 'SCHEDULED' => 'Đã lên lịch',
        'RECEIVED' => 'Đã tiếp nhận', 'IDENTITY_VERIFICATION' => 'Đang xác minh danh tính',
        'IN_PROGRESS' => 'Đang xử lý', 'HIDDEN' => 'Đã ẩn', 'REPORTED' => 'Đang được xem xét',
        'CONFIRMED' => 'Đã xác nhận', 'CHECKED_IN' => 'Đã đến', 'NO_SHOW' => 'Không đến',
    ];
@endphp
<span class="badge">{{ $statusLabels[$value] ?? $value }}</span>
