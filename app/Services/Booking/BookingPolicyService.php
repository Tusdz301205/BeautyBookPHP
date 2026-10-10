<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\BookingViolationEvent;
use App\Models\CustomerBookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BookingPolicyService
{
    public const POLICY_VERSION = '2026-09-18-request-time';
    public const WINDOW_DAYS = 90;
    public const RESTRICTION_DAYS = 30;

    public function assertSelfBookingAllowed(
        int $customerProfileId,
        int $businessId,
        bool $acknowledged,
        ?CarbonImmutable $at = null,
    ): array {
        $this->lockPair($customerProfileId, $businessId);
        $policy = $this->read($customerProfileId, $businessId, $at);

        if (! $policy['self_booking_allowed']) {
            abort(403, 'Bạn đang được hạn chế tự đặt lịch tại doanh nghiệp này. Vui lòng liên hệ cơ sở để được hỗ trợ.');
        }

        if ($policy['acknowledgment_required'] && ! $acknowledged) {
            throw new BookingConflictException('Vui lòng xác nhận đã hiểu cảnh báo 3 điểm trước khi tiếp tục đặt lịch.');
        }

        return $policy;
    }

    public function read(int $customerProfileId, int $businessId, ?CarbonImmutable $at = null): array
    {
        $now = $at ?? CarbonImmutable::now('UTC');
        $windowStart = $now->subDays(self::WINDOW_DAYS);
        $events = BookingViolationEvent::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('business_id', $businessId)
            ->whereNull('voided_at')
            ->whereBetween('occurred_at', [$windowStart, $now])
            ->get(['kind']);
        $lateCancellations = $events->where('kind', 'LATE_CANCELLATION')->count();
        $noShows = $events->where('kind', 'NO_SHOW')->count();
        $score = $lateCancellations + ($noShows * 2);
        $row = CustomerBookingPolicy::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('business_id', $businessId)
            ->first();
        $startsAt = $row?->starts_at ? CarbonImmutable::parse($row->getRawOriginal('starts_at'), 'UTC') : null;
        $endsAt = $row?->ends_at ? CarbonImmutable::parse($row->getRawOriginal('ends_at'), 'UTC') : null;
        $active = $startsAt !== null && $startsAt->lessThanOrEqualTo($now) && $endsAt !== null && $endsAt->greaterThan($now);

        return [
            'business_id' => $businessId,
            'window_days' => self::WINDOW_DAYS,
            'late_cancellations' => $lateCancellations,
            'no_shows' => $noShows,
            'score' => $score,
            'warning_level' => $score >= 3 ? 'WARNING_LEVEL_2' : ($score === 2 ? 'WARNING_LEVEL_1' : 'NORMAL'),
            'acknowledgment_required' => $score === 3 && ! $active,
            'self_booking_allowed' => ! $active,
            'restriction' => $endsAt === null ? null : [
                'active' => $active,
                'starts_at' => $startsAt?->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ],
        ];
    }

    public function recordViolation(
        Booking $booking,
        string $kind,
        int $actorUserId,
        ?int $sourceRequestId = null,
        ?CarbonImmutable $at = null,
    ): ?BookingViolationEvent {
        if ($booking->customer_profile_id === null) {
            return null;
        }

        $this->lockPair($booking->customer_profile_id, $booking->business_id);

        $existing = BookingViolationEvent::query()
            ->where('booking_id', $booking->id)
            ->whereNull('voided_at')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        $occurredAt = $at ?? CarbonImmutable::now('UTC');
        $event = BookingViolationEvent::query()->create([
            'booking_id' => $booking->id,
            'customer_profile_id' => $booking->customer_profile_id,
            'business_id' => $booking->business_id,
            'source_request_id' => $sourceRequestId,
            'kind' => $kind,
            'occurred_at' => $occurredAt,
            'appointment_start_at' => $this->bookingStartUtc($booking),
            'recorded_by_user_id' => $actorUserId,
            'policy_version' => self::POLICY_VERSION,
        ]);

        $this->refreshRestriction($booking->customer_profile_id, $booking->business_id);

        return $event;
    }

    private function lockPair(int $customerProfileId, int $businessId): CustomerBookingPolicy
    {
        DB::table('customer_booking_policies')->insertOrIgnore([
            'customer_profile_id' => $customerProfileId,
            'business_id' => $businessId,
            'revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = CustomerBookingPolicy::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->firstOrFail();
        $row->increment('revision');

        return $row->refresh();
    }

    private function refreshRestriction(int $customerProfileId, int $businessId): void
    {
        $events = BookingViolationEvent::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('business_id', $businessId)
            ->whereNull('voided_at')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $window = [];
        $score = 0;
        $startsAt = null;
        $endsAt = null;
        $triggeredBy = null;

        foreach ($events as $event) {
            $occurredAt = CarbonImmutable::parse($event->getRawOriginal('occurred_at'), 'UTC');
            $cutoff = $occurredAt->subDays(self::WINDOW_DAYS);

            while ($window !== [] && $window[0]['at']->lessThan($cutoff)) {
                $score -= $window[0]['weight'];
                array_shift($window);
            }

            $weight = $event->kind === 'NO_SHOW' ? 2 : 1;
            $window[] = ['at' => $occurredAt, 'weight' => $weight];
            $score += $weight;

            if ($event->policy_version !== self::POLICY_VERSION) {
                continue;
            }

            $active = $endsAt !== null && $endsAt->greaterThan($occurredAt);

            if ($score >= 4 || $active) {
                if (! $active) {
                    $startsAt = $occurredAt;
                }

                $endsAt = $occurredAt->addDays(self::RESTRICTION_DAYS);
                $triggeredBy = $event->id;
            }
        }

        CustomerBookingPolicy::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('business_id', $businessId)
            ->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'triggered_by_violation_event_id' => $triggeredBy,
                'updated_at' => now(),
            ]);
    }

    private function bookingStartUtc(Booking $booking): CarbonImmutable
    {
        return CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
    }
}
