@extends('layouts.app')
@section('title', 'Cài đặt nền tảng · BeautyBook')
@section('content')
<section class="page-shell"><h1>Cài đặt nền tảng</h1>
<form class="panel" method="POST" action="{{ route('platform.settings.update') }}">@csrf @method('PUT')
    @foreach (['maxAdvanceBookingDays' => 'Số ngày được đặt trước tối đa', 'minBookingLeadTimeHours' => 'Số giờ đặt trước tối thiểu', 'pendingHoldMinutes' => 'Thời gian giữ chỗ (phút)', 'maxRescheduleCountPerBooking' => 'Số lần đổi lịch tối đa', 'reviewMinLength' => 'Độ dài đánh giá tối thiểu', 'autoHideReviewReportThreshold' => 'Ngưỡng báo cáo ẩn đánh giá'] as $key => $label)
        <div class="form-field"><label for="{{ $key }}">{{ $label }}</label><input type="number" id="{{ $key }}" name="settings[{{ $key }}]" value="{{ old('settings.'.$key, $settings[$key]) }}" required>@error('settings.'.$key)<p role="alert">{{ $message }}</p>@enderror</div>
    @endforeach
    @foreach (['allowAnonymousReview' => 'Cho phép đánh giá ẩn danh', 'allowRescheduleRequests' => 'Cho phép yêu cầu đổi lịch'] as $key => $label)
        <div class="form-field"><label>{{ $label }}<select name="settings[{{ $key }}]"><option value="0" @selected(!$settings[$key])>Không</option><option value="1" @selected($settings[$key])>Có</option></select></label></div>
    @endforeach
    <p>Khách được hủy trực tiếp trước ít nhất 4 giờ. Chính sách này được giữ cố định.</p>
    <button class="button button-primary" type="submit">Lưu cài đặt</button>
</form></section>
@endsection
