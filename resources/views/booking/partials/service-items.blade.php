@php
    $visibleServices = $kind === 'STAFF'
        ? $booking->services->where('staff_profile_id', $bookingContext['staff_profile_id'])
        : $booking->services;
@endphp

@if ($visibleServices->isNotEmpty())
    <section class="booking-service-list" aria-label="Dịch vụ trong lịch hẹn">
        <h3>Dịch vụ</h3>

        @foreach ($visibleServices as $item)
            @php
                $itemStart = \Carbon\CarbonImmutable::parse($item->getRawOriginal('item_start_at'), 'UTC');
                $itemEnd = \Carbon\CarbonImmutable::parse($item->getRawOriginal('item_end_at'), 'UTC');
                $canStart = $kind === 'STAFF'
                    && $item->status === 'SCHEDULED'
                    && in_array($booking->status, ['CHECKED_IN', 'IN_PROGRESS'], true)
                    && $now->greaterThanOrEqualTo($itemStart);
                $canComplete = $kind === 'STAFF'
                    && $item->status === 'IN_PROGRESS'
                    && $booking->status === 'IN_PROGRESS'
                    && $now->greaterThanOrEqualTo($itemEnd);
            @endphp

            <div class="booking-service-row">
                <div class="booking-service-copy">
                    <strong>{{ $item->service_name_snapshot }}</strong>
                    <span>
                        {{ $item->staffProfile?->full_name ?? 'Chưa gán nhân viên' }}
                        · {{ $itemStart->setTimezone($booking->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('H:i') }}
                        – {{ $itemEnd->setTimezone($booking->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('H:i') }}
                    </span>
                </div>

                <div class="booking-service-side">
                    <span class="booking-item-status" data-status="{{ $item->status }}">{{ ['SCHEDULED' => 'Đã lên lịch', 'IN_PROGRESS' => 'Đang thực hiện', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã hủy'][$item->status] ?? $item->status }}</span>

                    @if ($canStart)
                        <button
                            class="button"
                            type="button"
                            data-booking-item-transition
                            data-url="{{ route('bookings.items.transition', [$booking, $item]) }}"
                            data-action="START"
                            data-revision="{{ $item->revision }}"
                        >
                            Bắt đầu dịch vụ
                        </button>
                    @elseif ($canComplete)
                        <button
                            class="button"
                            type="button"
                            data-booking-item-transition
                            data-url="{{ route('bookings.items.transition', [$booking, $item]) }}"
                            data-action="COMPLETE"
                            data-revision="{{ $item->revision }}"
                        >
                            Hoàn thành dịch vụ
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </section>
@endif
