@extends('layouts.app')
@section('title', 'Đặt lịch · '.$branch->public_name)
@section('content')
<section class="page-shell booking-shell bb-booking" data-booking-wizard
 data-quote-url="{{ route('booking.cart.quote', $branch) }}" data-slots-url="{{ route('booking.cart.slots', $branch) }}"
 data-store-url="{{ route('booking.cart.store', $branch) }}"
 data-board-url="{{ route('bookings.index') }}" data-self-booking-allowed="{{ ($bookingPolicy['self_booking_allowed'] ?? true) ? 'true' : 'false' }}">
<nav class="bb-booking-progress" aria-label="Tiến trình đặt lịch"><ol class="bb-booking-progress__list">
@foreach (['Dịch vụ', 'Chuyên viên', 'Thời gian', 'Thông tin', 'Xác nhận'] as $label)
<li class="bb-booking-progress__step" data-step-indicator="{{ $loop->iteration }}"><span class="bb-booking-progress__number">{{ $loop->iteration }}</span><span class="bb-booking-progress__label">{{ $label }}</span></li>
@endforeach
</ol></nav>
<header class="page-heading bb-booking-heading"><div><p class="eyebrow" data-step-label>Bước 1 / 5</p><h1 data-step-title tabindex="-1">Chọn dịch vụ</h1><p>{{ $branch->public_name }} · {{ $branch->business->name }}</p></div></header>
    @if (($bookingPolicy['warning_level'] ?? 'NORMAL') === 'WARNING_LEVEL_1')
        <div class="notice booking-policy-notice" data-booking-policy-warning>
            <strong>Lưu ý về lịch hẹn</strong>
            <p>Bạn đang có {{ $bookingPolicy['score'] }} điểm vi phạm trong {{ $bookingPolicy['window_days'] }} ngày gần đây tại doanh nghiệp này. Hãy hạn chế hủy muộn hoặc vắng mặt để tránh bị giới hạn tự đặt lịch.</p>
        </div>
    @elseif (($bookingPolicy['acknowledgment_required'] ?? false) === true)
        <div class="notice booking-policy-notice" data-booking-policy-acknowledgment>
            <strong>Cần xác nhận trước khi đặt lịch</strong>
            <p>Bạn đang có {{ $bookingPolicy['score'] }} điểm vi phạm trong {{ $bookingPolicy['window_days'] }} ngày gần đây tại doanh nghiệp này. Một vi phạm tiếp theo có thể kích hoạt giới hạn tự đặt lịch.</p>
        </div>
    @elseif (($bookingPolicy['restriction']['active'] ?? false) === true)
        <div class="notice booking-policy-notice" data-booking-policy-restriction>
            <strong>Tự đặt lịch đang bị giới hạn tại doanh nghiệp này</strong>
            <p>Giới hạn hiện tại có hiệu lực đến {{ \Carbon\CarbonImmutable::parse($bookingPolicy['restriction']['ends_at'])->setTimezone($branch->timezone)->format('d/m/Y H:i') }}. Vui lòng liên hệ cơ sở để được hỗ trợ đặt lịch.</p>
        </div>
    @endif

<div class="booking-grid"><form class="panel booking-form bb-booking-panel" data-booking-form novalidate>
<section class="booking-step" data-booking-step="1" aria-label="Dịch vụ">
<p class="muted-note">Chọn từ 1 đến 10 dịch vụ bạn muốn trải nghiệm. Dịch vụ được thực hiện theo thứ tự bạn chọn.</p>
<select id="booking-offering" name="branch_service_offering_id" required hidden aria-label="Dịch vụ"><option value="">Chọn dịch vụ</option>
@foreach ($offerings as $offering)<option value="{{ $offering->id }}">{{ $offering->service->name }}</option>@endforeach
</select>
<div class="booking-selection-list">
@foreach ($offerings as $offering)
<button type="button" class="bb-booking-selection" data-select-offering="{{ $offering->id }}" aria-pressed="false"><span class="bb-booking-selection__body"><span class="bb-booking-selection__title">{{ $offering->service->name }}</span><span class="bb-booking-selection__meta">{{ $offering->duration_minutes }} phút · {{ $offering->service->category?->name }}</span><span class="bb-booking-selection__trailing">{{ number_format((float) $offering->price, 0, ',', '.') }}đ</span></span></button>
@endforeach
</div>
<div class="booking-cart" data-booking-cart hidden><h2>Dịch vụ đã chọn <span data-cart-count></span></h2><div class="form-stack" data-selected-items></div></div>
@foreach ($offerings as $offering)
<template data-variants-offering="{{ $offering->id }}"><select aria-label="Lựa chọn cho {{ $offering->service->name }}"><option value="">Tiêu chuẩn</option>@foreach ($offering->variants as $variant)<option value="{{ $variant->id }}" data-bookable="{{ $variant->price_type === 'FIXED' ? 'true' : 'false' }}" @disabled($variant->price_type !== 'FIXED')>{{ $variant->name }}{{ $variant->price_type !== 'FIXED' ? ' · Liên hệ cơ sở' : '' }}</option>@endforeach</select></template>
@endforeach
@if ($offerings->contains(fn ($offering) => $offering->variants->contains(fn ($variant) => $variant->price_type !== 'FIXED')))
<p class="muted-note">Lựa chọn cần tư vấn hoặc báo giá riêng: @if ($branch->phone)<a href="tel:{{ preg_replace('/[^+0-9]/', '', $branch->phone) }}">liên hệ cơ sở {{ $branch->phone }}</a>@elseif ($branch->email)<a href="mailto:{{ $branch->email }}">liên hệ cơ sở qua email</a>@else vui lòng liên hệ trực tiếp cơ sở @endif để được hỗ trợ.</p>
@endif
<p class="muted-note"><a href="{{ route('combos.public', ['branch_id' => $branch->id]) }}">Khám phá các combo ưu đãi của cơ sở</a>.</p>
@if ($bookingContext['kind'] === 'CUSTOMER')<p class="muted-note">Đặt cùng một dịch vụ thường xuyên? <a href="{{ route('recurring.create', $branch) }}">Tạo lịch định kỳ</a>.</p>@endif
</section>
<section class="booking-step" data-booking-step="2" aria-label="Chuyên viên" hidden>
<p class="muted-note">Chọn chuyên viên phù hợp với dịch vụ của bạn.</p>
<select id="booking-staff" name="staff_profile_id" hidden aria-label="Chuyên viên"><option value="">Tự chọn chuyên viên phù hợp</option>
@foreach ($staffProfiles as $staff)<option value="{{ $staff->id }}" data-offerings='@json($staff->offerings->pluck("id")->values())'>{{ $staff->full_name }}</option>@endforeach
</select><div class="booking-selection-list">
<button type="button" class="bb-booking-selection" data-select-staff="" aria-pressed="true"><span class="bb-booking-selection__icon" aria-hidden="true">☆</span><span class="bb-booking-selection__body"><span class="bb-booking-selection__title">Tự chọn chuyên viên phù hợp</span><span class="bb-booking-selection__meta">Cơ sở chọn một chuyên viên phù hợp cho toàn bộ dịch vụ của bạn.</span></span></button>
@foreach ($staffProfiles as $staff)
<button type="button" class="bb-booking-selection" data-select-staff="{{ $staff->id }}" aria-pressed="false" hidden><span class="bb-booking-selection__icon">{{ mb_substr($staff->full_name, 0, 1) }}</span><span class="bb-booking-selection__body"><span class="bb-booking-selection__title">{{ $staff->full_name }}</span><span class="bb-booking-selection__meta">{{ $staff->position ?: 'Chuyên viên' }} @if($staff->experience_years) · {{ $staff->experience_years }} năm kinh nghiệm @endif</span></span></button>
@endforeach
</div><p class="muted-note" data-staff-empty hidden>Chưa có chuyên viên nhận toàn bộ dịch vụ đã chọn. Vui lòng quay lại chọn dịch vụ khác hoặc liên hệ cơ sở để được hỗ trợ.</p>
</section>
<section class="booking-step" data-booking-step="3" aria-label="Thời gian" hidden>
<div class="form-field"><label for="booking-day">Chọn ngày</label><input id="booking-day" type="date" data-booking-day min="{{ now($branch->timezone)->format('Y-m-d') }}" max="{{ now($branch->timezone)->addDays($bookingLimits['maxAdvanceBookingDays'])->format('Y-m-d') }}"></div>
<p class="muted-note">Giờ phục vụ tại cơ sở ({{ $branch->timezone }}).</p><div class="booking-slot-grid" data-booking-slots role="group" aria-label="Giờ còn trống"></div>
<input id="booking-start" name="start_at" type="hidden" required>
<button class="button" type="button" data-check-availability disabled>Kiểm tra lại giờ đã chọn</button>
</section>
<section class="booking-step" data-booking-step="4" aria-label="Thông tin" hidden>
@if ($bookingContext['kind'] === 'CUSTOMER')<div class="booking-customer-details"><h2>Thông tin của bạn</h2><p>{{ $customer->name }}</p><p>{{ $customer->phone ?: $customer->email }}</p><a href="{{ route('profile.show') }}">Cập nhật thông tin cá nhân</a></div>@endif
            @if ($bookingContext['kind'] === 'RECEPTIONIST')
                <div class="form-field">
                    <label for="booking-source">Kênh tiếp nhận</label>
                    <select id="booking-source" name="source" required>
                        <option value="PHONE">Điện thoại</option>
                        <option value="WALK_IN">Khách tại quầy</option>
                    </select>
                </div>
            @endif

            @if ($bookingContext['kind'] !== 'CUSTOMER')
                <fieldset class="booking-contact">
                    <legend>Thông tin khách</legend>
                    <div class="form-field">
                        <label for="booking-contact-name">Họ tên</label>
                        <input id="booking-contact-name" name="contact[full_name]" type="text" maxlength="160" required>
                    </div>
                    <div class="form-grid-two">
                        <div class="form-field">
                            <label for="booking-contact-phone">Điện thoại</label>
                            <input id="booking-contact-phone" name="contact[phone]" type="tel" maxlength="32">
                        </div>
                        <div class="form-field">
                            <label for="booking-contact-email">Email</label>
                            <input id="booking-contact-email" name="contact[email]" type="email" maxlength="255">
                        </div>
                    </div>
                    <p class="muted-note">Cần ít nhất điện thoại hoặc email. Thông tin giúp cơ sở liên hệ và xác nhận lịch hẹn.</p>
                </fieldset>
            @endif

            <div class="form-field">
                <label for="booking-note">Ghi chú</label>
                <textarea id="booking-note" name="note" rows="3" maxlength="2000"></textarea>
            </div>

            @if ($bookingContext['kind'] === 'CUSTOMER')
                <div class="form-field">
                    <label for="booking-voucher">Mã voucher</label>
                    <input id="booking-voucher" name="voucher_code" type="text" maxlength="64" placeholder="Nhập mã nếu có">
                    <p class="muted-note">Ưu đãi hợp lệ được tính vào báo giá trước khi xác nhận.</p>
                </div>
                <div class="form-field">
                    <label for="booking-loyalty">Điểm muốn sử dụng</label>
                    <input id="booking-loyalty" name="loyalty_points" type="number" value="0" min="0" step="1">
                </div>
            @endif

            @if (($bookingPolicy['acknowledgment_required'] ?? false) === true)
                <label class="booking-policy-acknowledgment">
                    <input type="checkbox" name="violation_acknowledged" value="1" required>
                    <span>Tôi đã hiểu cảnh báo 3 điểm và vẫn muốn tiếp tục tự đặt lịch.</span>
                </label>
            @endif

</section>
<section class="booking-step" data-booking-step="5" aria-label="Xác nhận" hidden>
<h2>Kiểm tra lịch hẹn</h2><p class="muted-note">Vui lòng kiểm tra thông tin trước khi gửi lịch đến cơ sở.</p><dl class="definition-list booking-confirm-details" data-confirm-details></dl>
<p class="muted-note">Lịch hẹn được gửi đến cơ sở để xác nhận. Bạn có thể theo dõi trạng thái trong Lịch của tôi.</p>
</section>
<div class="booking-feedback" data-booking-feedback role="status" aria-live="polite"></div>
<div class="action-row booking-actions"><button type="button" class="button" data-booking-back hidden>Quay lại</button><button type="button" class="button button-primary" data-booking-next disabled>Tiếp tục</button><button class="button button-primary" type="submit" data-confirm-booking hidden disabled>Xác nhận đặt lịch</button></div>
</form>
<aside class="panel booking-summary bb-booking-aside"><p class="eyebrow">Lịch hẹn của bạn</p><h2>{{ $branch->public_name }}</h2><p data-quote-service>Chưa chọn dịch vụ</p><dl class="definition-list"><div><dt>Chuyên viên</dt><dd data-summary-staff>—</dd></div><div><dt>Thời gian</dt><dd data-summary-time>—</dd></div><div><dt>Thời lượng</dt><dd data-quote-duration>—</dd></div><div><dt>Tổng tiền</dt><dd data-quote-price>—</dd></div></dl><span data-quote-buffer hidden></span><p class="muted-note">Ưu đãi hợp lệ sẽ được áp dụng trước khi bạn xác nhận.</p></aside>
</div>
<div class="panel booking-success" data-booking-success hidden role="status"><span class="booking-success-icon" aria-hidden="true">✓</span><h2>Đặt lịch thành công!</h2><p>Cảm ơn bạn đã đặt lịch. Cơ sở sẽ xác nhận lịch hẹn của bạn.</p><p data-booking-code></p><a class="button button-primary" data-success-link href="{{ route('bookings.index') }}">Xem lịch hẹn của tôi</a></div>
<noscript><p class="notice">Vui lòng bật JavaScript để chọn dịch vụ và giờ đặt lịch.</p></noscript>
</section>
@endsection


