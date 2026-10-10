@if ($booking->changeRequests->isNotEmpty())
    <section class="booking-change-list" aria-label="Yêu cầu thay đổi lịch hẹn">
        <h3>Yêu cầu thay đổi</h3>

        @foreach ($booking->changeRequests as $changeRequest)
            <div class="booking-change-row">
                <div>
                    <strong>
                        @if ($changeRequest->request_type === 'CANCEL')
                            Yêu cầu hủy lịch
                        @elseif ($changeRequest->request_type === 'RESCHEDULE')
                            Yêu cầu đổi giờ
                        @else
                            Yêu cầu đổi nhân viên
                        @endif
                    </strong>
                    <span>{{ ['PENDING' => 'Chờ xử lý', 'APPROVED' => 'Đã duyệt', 'REJECTED' => 'Đã từ chối', 'EXPIRED' => 'Hết hạn'][$changeRequest->status] ?? $changeRequest->status }}</span>
                    @if ($changeRequest->request_type === 'RESCHEDULE' && $changeRequest->proposed_start_at)
                        <p>Giờ đề xuất: {{ \Carbon\CarbonImmutable::parse($changeRequest->getRawOriginal('proposed_start_at'), 'UTC')->setTimezone($booking->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</p>
                    @elseif ($changeRequest->request_type === 'STAFF_CHANGE' && $changeRequest->proposed_staff_profile_id)
                        <p>Chuyên viên đề xuất: {{ $changeStaff->firstWhere('id', $changeRequest->proposed_staff_profile_id)?->full_name ?: 'Chuyên viên đã được yêu cầu' }}</p>
                    @endif
                    @if ($changeRequest->reason)
                        <p>{{ $changeRequest->reason }}</p>
                    @endif
                </div>

                @if (in_array($kind, ['OWNER', 'RECEPTIONIST'], true) && $changeRequest->status === 'PENDING')
                    <div class="booking-inline-actions">
                        <button
                            class="button"
                            type="button"
                            data-change-request-review
                            data-url="{{ route('bookings.change-requests.approve', [$booking, $changeRequest]) }}"
                        >
                            Duyệt
                        </button>
                        <button
                            class="button button-danger"
                            type="button"
                            data-change-request-review
                            data-url="{{ route('bookings.change-requests.reject', [$booking, $changeRequest]) }}"
                        >
                            Từ chối
                        </button>
                    </div>
                @endif
            </div>
        @endforeach
    </section>
@endif
