@php
    $transitionUrl = route('bookings.transition', $booking);
    $changeRequestUrl = route('bookings.change-requests.store', $booking);
@endphp

<section class="booking-lifecycle-actions" aria-label="Thao tác lịch hẹn">
    @if ($kind === 'CUSTOMER')
        @if (in_array($booking->status, ['PENDING', 'CONFIRMED'], true) && $now->lessThan($startUtc))
            @if ($pendingCancel)
                <p class="booking-action-note">Yêu cầu hủy đang chờ doanh nghiệp xử lý.</p>
            @elseif ($now->lessThanOrEqualTo($startUtc->subHours(4)))
                <button
                    class="button button-danger"
                    type="button"
                    data-booking-transition
                    data-url="{{ $transitionUrl }}"
                    data-status="CANCELLED"
                >
                    Hủy lịch
                </button>
            @else
                <button
                    class="button button-danger"
                    type="button"
                    data-booking-change-request
                    data-url="{{ $changeRequestUrl }}"
                    data-request-type="CANCEL"
                >
                    Gửi yêu cầu hủy muộn
                </button>
            @endif
        @endif
    @elseif (in_array($kind, ['OWNER', 'RECEPTIONIST'], true))
        <div class="booking-inline-actions">
            @if ($booking->status === 'PENDING')
                <button class="button" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="CONFIRMED">
                    Xác nhận
                </button>
                <button class="button" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="REJECTED">
                    Từ chối
                </button>
                <button class="button button-danger" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="CANCELLED">
                    Hủy lịch
                </button>
            @elseif ($booking->status === 'CONFIRMED')
                @if ($now->greaterThanOrEqualTo($startUtc->subMinutes(30)))
                    <button class="button" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="CHECKED_IN">
                        Check-in
                    </button>
                @endif
                <button class="button button-danger" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="CANCELLED">
                    Hủy lịch
                </button>
            @elseif ($kind === 'OWNER' && $booking->status === 'CHECKED_IN')
                @if ($now->greaterThanOrEqualTo($startUtc))
                    <button class="button" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="IN_PROGRESS">
                        Bắt đầu lịch
                    </button>
                @endif
                <button class="button button-danger" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="CANCELLED">
                    Hủy lịch
                </button>
            @elseif ($kind === 'OWNER' && $booking->status === 'IN_PROGRESS')
                @if ($now->greaterThanOrEqualTo($endUtc))
                    <button class="button" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="COMPLETED">
                        Hoàn thành lịch
                    </button>
                @endif
                <button class="button button-danger" type="button" data-booking-transition data-url="{{ $transitionUrl }}" data-status="CANCELLED">
                    Hủy lịch
                </button>
            @endif
        </div>

        @if ($booking->status === 'CONFIRMED' && $now->greaterThan($startUtc->addMinutes(15)))
            <label class="booking-no-show-confirm">
                <input type="checkbox" data-no-show-confirm="{{ $booking->id }}">
                <span>Tôi xác nhận khách chưa đến và đã kiểm tra yêu cầu hủy, check-in và tiến độ dịch vụ.</span>
            </label>
            <button
                class="button button-danger"
                type="button"
                data-booking-transition
                data-url="{{ $transitionUrl }}"
                data-status="NO_SHOW"
                data-booking-id="{{ $booking->id }}"
            >
                Đánh dấu không đến
            </button>
        @endif
    @endif
@if (in_array($kind, ['CUSTOMER', 'OWNER', 'RECEPTIONIST'], true) && in_array($booking->status, ['PENDING', 'CONFIRMED'], true) && $now->lessThan($startUtc))
    @php
        $pendingChange = $booking->changeRequests->first(fn ($change) => $change->status === 'PENDING' && $change->expires_at?->isFuture());
        $offeringIds = $booking->services->pluck('branch_service_offering_id')->unique();
        $eligibleStaff = $changeStaff->filter(fn ($person) => (int) $person->branch_id === (int) $booking->branch_id && $offeringIds->diff($person->offerings->pluck('id'))->isEmpty());
        $approvedReschedules = $booking->changeRequests->where('request_type', 'RESCHEDULE')->where('status', 'APPROVED')->count();
    @endphp
    @if (!$pendingChange)
        @if ($changeSettings['allowRescheduleRequests'] && $approvedReschedules < $changeSettings['maxRescheduleCountPerBooking'])
        <details class="booking-change-form"><summary>Yêu cầu đổi lịch</summary>
            <form class="form-stack" data-booking-change-form data-request-type="RESCHEDULE" data-url="{{ $changeRequestUrl }}" data-duration-minutes="{{ $startUtc->diffInMinutes($endUtc) }}" data-timezone="{{ $timezone }}">
                <label>Ngày và giờ mong muốn<input type="datetime-local" name="proposed_start_at" min="{{ now($timezone)->format('Y-m-d\TH:i') }}" required></label>
                <label>Lý do<textarea name="reason" maxlength="2000" rows="2"></textarea></label>
                <p class="muted-note">Thời lượng giữ nguyên. Lịch hiện tại có hiệu lực đến khi cơ sở duyệt yêu cầu.</p>
                <button type="submit" class="button">Gửi yêu cầu đổi lịch</button>
            </form>
        </details>
        @endif
        @if ($eligibleStaff->isNotEmpty())
        <details class="booking-change-form"><summary>Yêu cầu đổi chuyên viên</summary>
            <form class="form-stack" data-booking-change-form data-request-type="STAFF_CHANGE" data-url="{{ $changeRequestUrl }}">
                <label>Chuyên viên mong muốn<select name="proposed_staff_profile_id" required><option value="">Chọn chuyên viên</option>@foreach ($eligibleStaff as $person)<option value="{{ $person->id }}">{{ $person->full_name }}</option>@endforeach</select></label>
                <label>Lý do<textarea name="reason" maxlength="2000" rows="2"></textarea></label>
                <button type="submit" class="button">Gửi yêu cầu đổi chuyên viên</button>
            </form>
        </details>
        @endif
    @else
        <p class="booking-action-note">Bạn có một yêu cầu thay đổi đang chờ xử lý.</p>
    @endif
@endif
</section>

