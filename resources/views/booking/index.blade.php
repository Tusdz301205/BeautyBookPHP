@extends('layouts.app')

@section('title', 'Lịch hẹn · BeautyBook')

@section('content')
@php
    $kind = $bookingContext['kind'];
    $now = \Carbon\CarbonImmutable::now('UTC');
@endphp

<section class="page-shell booking-board" data-booking-board>
    <div class="page-heading booking-board-heading">
        <div>
            <p class="eyebrow">
                @if ($kind === 'CUSTOMER')
                    Lịch của tôi
                @elseif ($kind === 'RECEPTIONIST')
                    Hàng đợi chi nhánh
                @elseif ($kind === 'STAFF')
                    Dịch vụ được phân công
                @elseif ($kind === 'OWNER')
                    Vận hành doanh nghiệp
                @elseif ($kind === 'ADMIN')
                    Lịch toàn nền tảng
                @else
                    Lịch hẹn
                @endif
            </p>
            <h1>Lịch hẹn</h1>
        </div>

        <form class="booking-date-filter" method="GET" action="{{ route('bookings.index') }}">
            @if ($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
            @if ($kind === 'ADMIN')
                <label for="booking-business">Doanh nghiệp</label>
                <select id="booking-business" name="business_id"><option value="">Tất cả doanh nghiệp</option>@foreach ($accessibleBranches->pluck('business')->unique('id') as $business)<option value="{{ $business->id }}" @selected((int) $selectedBusiness === (int) $business->id)>{{ $business->name }}</option>@endforeach</select>
            @endif
            @if (in_array($kind, ['ADMIN', 'OWNER'], true))
                <label for="booking-branch">Chi nhánh</label>
                <select id="booking-branch" name="branch_id"><option value="">Tất cả chi nhánh</option>@foreach ($accessibleBranches as $branch)<option value="{{ $branch->id }}" data-business-id="{{ $branch->business_id }}" @selected((int) $selectedBranch === (int) $branch->id)>{{ $branch->public_name }}</option>@endforeach</select>
            @endif
            <label for="booking-date">Ngày</label>
            <input id="booking-date" type="date" name="date" value="{{ $date }}">
            <button class="button" type="submit">Lọc</button>
            @if ($date)
                <a class="button" href="{{ route('bookings.index') }}">Bỏ lọc</a>
            @endif
        </form>
    </div>

    <div class="booking-feedback" data-booking-board-feedback role="status" aria-live="polite"></div>

    <nav class="section-tabs" aria-label="Chế độ xem lịch">
        <a href="{{ route('bookings.index', request()->query()) }}" aria-current="page">Danh sách</a>
        <a href="{{ route('bookings.calendar', array_filter(['view' => 'day', 'date' => $date, 'branch_id' => $selectedBranch, 'business_id' => $selectedBusiness])) }}">Ngày</a>
        <a href="{{ route('bookings.calendar', array_filter(['view' => 'week', 'date' => $date, 'branch_id' => $selectedBranch, 'business_id' => $selectedBusiness])) }}">Tuần</a>
    </nav>

    @if ($kind !== 'CUSTOMER')
    <div class="booking-stat-grid">
        @foreach (['TOTAL' => 'Tổng lịch', 'PENDING' => 'Chờ xác nhận', 'CONFIRMED' => 'Đã xác nhận', 'CHECKED_IN' => 'Đã đến', 'IN_PROGRESS' => 'Đang thực hiện', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy', 'NO_SHOW' => 'Không đến'] as $status => $label)
        <div class="panel booking-stat"><span>{{ $label }}</span><strong>{{ $status === 'TOTAL' ? $bookingCounts->sum() : ($bookingCounts[$status] ?? 0) }}</strong></div>
        @endforeach
    </div>
    @endif

    @if ($kind === 'CUSTOMER')
    <nav class="booking-tabs" aria-label="Lọc lịch hẹn">
        @foreach (['' => 'Tất cả', 'upcoming' => 'Sắp tới', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy'] as $value => $label)
        <a class="button {{ ($filter ?? '') === $value ? 'button-primary' : '' }}" href="{{ route('bookings.index', array_filter(['filter' => $value, 'date' => $date])) }}" @if (($filter ?? '') === $value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @endif

    <div class="booking-card-list">
        @forelse ($bookings as $booking)
            @php
                $startUtc = \Carbon\CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
                $endUtc = \Carbon\CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
                $timezone = $booking->branch->timezone ?: 'Asia/Ho_Chi_Minh';
                $localStart = $startUtc->setTimezone($timezone);
                $localEnd = $endUtc->setTimezone($timezone);
                $customerName = $booking->customerProfile?->user?->name
                    ?? $booking->contact?->full_name
                    ?? 'Khách không liên kết';
                $pendingCancel = $booking->changeRequests->first(
                    fn ($request) => $request->request_type === 'CANCEL' && $request->status === 'PENDING'
                );
            @endphp

            <article class="panel booking-card" data-booking-card="{{ $booking->id }}">
                <header class="booking-card-header">
                    <div>
                        <p class="eyebrow">{{ $booking->booking_code }}</p>
                        <h2>{{ $booking->branch->public_name }}</h2>
                    </div>
                    <span class="booking-status" data-status="{{ $booking->status }}">{{ ['PENDING' => 'Chờ xác nhận', 'CONFIRMED' => 'Đã xác nhận', 'CHECKED_IN' => 'Đã đến', 'IN_PROGRESS' => 'Đang thực hiện', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy', 'REJECTED' => 'Đã từ chối', 'EXPIRED' => 'Hết hạn', 'NO_SHOW' => 'Không đến'][$booking->status] ?? $booking->status }}</span>
                </header>

                <div class="booking-card-meta">
                    <div>
                        <span>Thời gian</span>
                        <strong>{{ $localStart->format('d/m/Y H:i') }} – {{ $localEnd->format('H:i') }}</strong>
                    </div>
                    @if ($kind === 'CUSTOMER')
                        <div>
                            <span>Doanh nghiệp</span>
                            <strong>{{ $booking->branch->business->name }}</strong>
                        </div>
                    @else
                        <div>
                            <span>Khách</span>
                            <strong>{{ $customerName }}</strong>
                        </div>
                    @endif
                    <div>
                        <span>Tổng tiền</span>
                        <strong>{{ number_format((float) $booking->total_amount, 0, ',', '.') }} {{ $booking->currency }}</strong>
                    </div>
                </div>

                @include('booking.partials.service-items', ['booking' => $booking, 'kind' => $kind, 'now' => $now, 'bookingContext' => $bookingContext])
                @if (in_array($kind, ['CUSTOMER', 'OWNER', 'RECEPTIONIST']))
                    <p><a class="button" href="{{ route('finance.show', $booking) }}">Thanh toán và chứng từ</a></p>
                @endif
                @include('booking.partials.change-requests', ['booking' => $booking, 'kind' => $kind])
                @include('booking.partials.lifecycle-actions', ['booking' => $booking, 'kind' => $kind, 'now' => $now, 'startUtc' => $startUtc, 'endUtc' => $endUtc, 'pendingCancel' => $pendingCancel])
            </article>
        @empty
            <div class="panel empty-state">
                <h2>Chưa có lịch hẹn phù hợp</h2>
                <p>Thay đổi ngày lọc hoặc quay lại trang khám phá để tạo lịch mới.</p>
            </div>
        @endforelse
    </div>

    @if ($bookings->hasPages())
        <div class="booking-pagination">
            {{ $bookings->links() }}
        </div>
    @endif
</section>
@endsection
