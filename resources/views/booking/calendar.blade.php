@extends('layouts.app')
@section('title', 'Lịch ngày và tuần · BeautyBook')
@section('content')
@php
    $parameters = array_filter(['date' => $date->toDateString(), 'view' => $mode, 'branch_id' => $selectedBranch, 'business_id' => $selectedBusiness]);
    $statuses = ['PENDING' => 'Chờ xác nhận', 'CONFIRMED' => 'Đã xác nhận', 'CHECKED_IN' => 'Đã đến', 'IN_PROGRESS' => 'Đang thực hiện', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy', 'REJECTED' => 'Đã từ chối', 'EXPIRED' => 'Hết hạn', 'NO_SHOW' => 'Không đến'];
    $dayNames = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
@endphp
<section class="page-shell booking-calendar">
    <div class="page-heading">
        <div><p class="eyebrow">{{ $kind === 'CUSTOMER' ? 'Lịch của tôi' : ($kind === 'ADMIN' ? 'Lịch toàn nền tảng' : 'Lịch làm việc') }}</p><h1>Lịch hẹn</h1><p>{{ $from->format('d/m/Y') }}{{ $mode === 'week' ? ' – '.$to->subDay()->format('d/m/Y') : '' }} · Giờ Việt Nam (UTC+7)</p></div>
    </div>
    <nav class="section-tabs" aria-label="Chế độ xem lịch">
        <a href="{{ route('bookings.index', array_filter(['date' => $mode === 'day' ? $date->toDateString() : null, 'branch_id' => $selectedBranch, 'business_id' => $selectedBusiness])) }}">Danh sách</a>
        @foreach (['day' => 'Ngày', 'week' => 'Tuần'] as $value => $label)<a href="{{ route('bookings.calendar', array_replace($parameters, ['view' => $value])) }}" @if ($mode === $value) aria-current="page" @endif>{{ $label }}</a>@endforeach
    </nav>
    <form class="calendar-controls panel" method="GET">
        <input type="hidden" name="view" value="{{ $mode }}">
        <label>Ngày<input type="date" name="date" value="{{ $date->toDateString() }}" required></label>
        @if ($kind === 'ADMIN')<label>Doanh nghiệp<select name="business_id"><option value="">Tất cả doanh nghiệp</option>@foreach ($accessibleBranches->pluck('business')->unique('id') as $business)<option value="{{ $business->id }}" @selected((int) $selectedBusiness === $business->id)>{{ $business->name }}</option>@endforeach</select></label>@endif
        @if (in_array($kind, ['OWNER', 'ADMIN'], true))<label>Chi nhánh<select name="branch_id"><option value="">Tất cả chi nhánh</option>@foreach ($accessibleBranches as $branch)<option value="{{ $branch->id }}" @selected((int) $selectedBranch === $branch->id)>{{ $branch->public_name }}</option>@endforeach</select></label>@endif
        <button class="button button-primary" type="submit">Xem lịch</button>
        <div class="calendar-pagination">
            <a class="button" aria-label="{{ $mode === 'week' ? 'Tuần trước' : 'Ngày trước' }}" href="{{ route('bookings.calendar', array_replace($parameters, ['date' => $date->subDays($mode === 'week' ? 7 : 1)->toDateString()])) }}">←</a>
            <a class="button" href="{{ route('bookings.calendar', array_replace($parameters, ['date' => now($timezone)->toDateString()])) }}">Hôm nay</a>
            <a class="button" aria-label="{{ $mode === 'week' ? 'Tuần sau' : 'Ngày sau' }}" href="{{ route('bookings.calendar', array_replace($parameters, ['date' => $date->addDays($mode === 'week' ? 7 : 1)->toDateString()])) }}">→</a>
        </div>
    </form>
    <p class="help-text">{{ $bookings->total() }} lịch trong khoảng này. Chọn lịch hẹn để xem chi tiết và các thao tác được phép.</p>
    @if ($bookings->isEmpty())<div class="empty-state"><h2>Chưa có lịch hẹn</h2><p>Chọn ngày hoặc chi nhánh khác để xem lịch.</p></div>@endif
    <div class="table-scroll calendar-scroll" role="region" aria-label="Lịch {{ $mode === 'week' ? 'tuần' : 'ngày' }}" tabindex="0">
        <div class="calendar-grid" style="--calendar-days: {{ $days->count() }}; --calendar-hours: {{ $lastHour - $firstHour }}">
            <div class="calendar-time-header">Giờ</div>
            @foreach ($days as $day)<div class="calendar-day-header {{ $day->isToday() ? 'is-today' : '' }}"><span>{{ $dayNames[$day->dayOfWeek] }}</span><strong>{{ $day->format('d/m') }}</strong></div>@endforeach
            <div class="calendar-time-axis">@for ($hour = $firstHour; $hour < $lastHour; $hour++)<span style="top: {{ ($hour - $firstHour) * 60 }}px">{{ sprintf('%02d:00', $hour) }}</span>@endfor</div>
            @foreach ($days as $day)
            <div class="calendar-day" aria-label="{{ $dayNames[$day->dayOfWeek] }} {{ $day->format('d/m/Y') }}">
                @foreach ($groups[$day->toDateString()] as $event)
                @php
                    $booking = $event['booking'];
                    $top = max(0, ($event['visible_start']->timestamp - $day->addHours($firstHour)->timestamp) / 60);
                    $height = max(24, ($event['visible_end']->timestamp - $event['visible_start']->timestamp) / 60);
                @endphp
                <a class="calendar-event" data-calendar-booking="{{ $booking->id }}" data-status="{{ $booking->status }}" href="{{ route('bookings.index', ['booking' => $booking->id]) }}" style="top: {{ $top }}px; height: {{ $height }}px; left: calc({{ $event['track'] }} * 100% / {{ $event['tracks'] }} + 3px); width: calc(100% / {{ $event['tracks'] }} - 6px)" title="{{ $booking->booking_code }} · {{ $event['start']->format('H:i') }}–{{ $event['end']->format('H:i') }} · {{ $booking->branch->public_name }} · {{ $event['services'] }} · {{ $statuses[$booking->status] ?? $booking->status }}">
                    <strong>{{ $event['start']->format('H:i') }}–{{ $event['end']->format('H:i') }}</strong>
                    <span>{{ $kind === 'CUSTOMER' ? $booking->branch->public_name : ($booking->customerProfile?->user?->name ?? $booking->contact?->full_name ?? 'Khách không liên kết') }}</span>
                    <span>{{ $event['services'] }}</span><small>{{ $statuses[$booking->status] ?? $booking->status }}</small>
                </a>
                @endforeach
            </div>
            @endforeach
        </div>
    </div>
    @if ($bookings->hasPages())<p class="help-text">Lịch được chia trang để hiển thị đầy đủ. Đang xem {{ $bookings->firstItem() }}–{{ $bookings->lastItem() }} / {{ $bookings->total() }}.</p>{{ $bookings->links() }}@endif
</section>
@endsection
