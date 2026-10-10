<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\CustomerProfile;
use App\Models\RoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingLifecycleService
{
    private const TRANSITIONS = [
        'PENDING' => ['CONFIRMED', 'CANCELLED', 'REJECTED', 'EXPIRED'],
        'CONFIRMED' => ['CHECKED_IN', 'CANCELLED', 'NO_SHOW'],
        'CHECKED_IN' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
        'NO_SHOW' => [],
        'REJECTED' => [],
        'EXPIRED' => [],
    ];

    private const ACTOR_TARGETS = [
        'CUSTOMER' => [
            'PENDING' => ['CANCELLED'],
            'CONFIRMED' => ['CANCELLED'],
        ],
        'RECEPTIONIST' => [
            'PENDING' => ['CONFIRMED', 'CANCELLED', 'REJECTED'],
            'CONFIRMED' => ['CHECKED_IN', 'CANCELLED', 'NO_SHOW'],
        ],
        'STAFF' => [
            'CHECKED_IN' => ['IN_PROGRESS'],
            'IN_PROGRESS' => ['COMPLETED'],
        ],
        'OWNER' => [
            'PENDING' => ['CONFIRMED', 'CANCELLED', 'REJECTED'],
            'CONFIRMED' => ['CHECKED_IN', 'CANCELLED', 'NO_SHOW'],
            'CHECKED_IN' => ['IN_PROGRESS', 'CANCELLED'],
            'IN_PROGRESS' => ['COMPLETED', 'CANCELLED'],
        ],
    ];

    public function __construct(
        private BookingLifecycleAccessService $access,
        private BookingPolicyService $policy,
        private BookingNotificationService $notifications,
    ) {
    }

    public function transition(
        Request $request,
        Booking $booking,
        string $toStatus,
        ?string $reason = null,
        bool $confirmedNoShow = false,
        ?CarbonImmutable $at = null,
    ): Booking {
        $context = $this->access->resolve($request, $booking);
        $now = $at ?? CarbonImmutable::now('UTC');
        $target = strtoupper($toStatus);

        return DB::transaction(function () use ($booking, $context, $target, $reason, $confirmedNoShow, $now) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCurrent($locked, $context);

            if ($locked->status === $target) {
                return $locked;
            }

            $this->assertTransitionAllowed($locked, $context, $target);
            $this->assertTimeRules($locked, $context, $target, $confirmedNoShow, $now);

            $fromStatus = $locked->status;
            $locked->status = $target;
            $locked->save();
            app(\App\Services\Promotions\PromotionPricingService::class)->transition($locked, $target);
            if ($target === 'COMPLETED') {
                app(\App\Services\Operations\LoyaltyService::class)->earnForBooking($locked->id, $context['actor_user_id']);
                app(\App\Services\Finance\SettlementService::class)->ensureFee($locked, $context['actor_user_id']);
            }
            app(\App\Services\Finance\PackageService::class)->redeemForBooking($locked->id, $target);

            if (in_array($target, ['CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'], true)) {
                BookingService::query()
                    ->where('booking_id', $locked->id)
                    ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                    ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            }

            $history = BookingStatusHistory::query()->create([
                'booking_id' => $locked->id,
                'actor_user_id' => $context['actor_user_id'],
                'from_status' => $fromStatus,
                'to_status' => $target,
                'reason' => $reason,
            ]);
            DB::table('booking_status_histories')->where('id', $history->id)->update(['actor_kind' => $context['kind']]);

            if ($target === 'NO_SHOW') {
                $this->policy->recordViolation($locked, 'NO_SHOW', $context['actor_user_id'], null, $now);
            }

            $this->recordTransitionNotifications($locked, $context, $target, $history->id);

            return $locked->refresh();
        }, 3);
    }

    private function assertTransitionAllowed(Booking $booking, array $context, string $target): void
    {
        $legalTargets = self::TRANSITIONS[$booking->status] ?? [];
        if (! in_array($target, $legalTargets, true)) {
            throw new BookingConflictException('Trạng thái lịch hẹn đã thay đổi hoặc không cho phép thao tác này.');
        }

        if ($context['kind'] === 'ADMIN') {
            if ($target === 'NO_SHOW') {
                abort(403, 'Quản trị viên nền tảng không được đánh dấu khách vắng mặt.');
            }
            return;
        }

        $actorTargets = self::ACTOR_TARGETS[$context['kind']][$booking->status] ?? [];
        abort_unless(in_array($target, $actorTargets, true), 403);
    }

    private function assertTimeRules(
        Booking $booking,
        array $context,
        string $target,
        bool $confirmedNoShow,
        CarbonImmutable $now,
    ): void {
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $end = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');

        if ($context['kind'] === 'CUSTOMER' && $target === 'CANCELLED') {
            if ($now->greaterThanOrEqualTo($start)) {
                throw new BookingConflictException('Lịch hẹn đã bắt đầu. Vui lòng liên hệ cơ sở để được hỗ trợ.');
            }
            if ($now->greaterThan($start->subHours(4))) {
                throw new BookingConflictException('Đã qua mốc hủy trực tiếp 4 giờ. Vui lòng gửi yêu cầu hủy muộn.');
            }
        }

        if ($target === 'CHECKED_IN' && $now->lessThan($start->subMinutes(30))) {
            throw new BookingConflictException('Chỉ được check-in từ 30 phút trước giờ hẹn.');
        }

        if ($target === 'IN_PROGRESS' && $now->lessThan($start)) {
            throw new BookingConflictException('Chưa đến giờ bắt đầu lịch hẹn.');
        }

        if ($target === 'COMPLETED' && $now->lessThan($end)) {
            throw new BookingConflictException('Chưa đến thời điểm hoàn thành lịch hẹn.');
        }

        if ($target === 'NO_SHOW') {
            abort_unless(in_array($context['kind'], ['OWNER', 'RECEPTIONIST'], true), 403);
            if (! $confirmedNoShow) {
                throw new BookingConflictException('Cần xác nhận rõ trước khi đánh dấu khách vắng mặt.');
            }
            if (! $now->greaterThan($start->addMinutes(15))) {
                throw new BookingConflictException('Chỉ được đánh dấu vắng mặt sau hơn 15 phút kể từ giờ hẹn.');
            }

            $hasCancellationEvidence = AppointmentChangeRequest::query()
                ->where('booking_id', $booking->id)
                ->where('request_type', 'CANCEL')
                ->exists();
            $hasArrivalHistory = BookingStatusHistory::query()
                ->where('booking_id', $booking->id)
                ->whereIn('to_status', ['CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'])
                ->exists();
            $hasServiceProgress = BookingService::query()
                ->where('booking_id', $booking->id)
                ->whereIn('status', ['IN_PROGRESS', 'COMPLETED'])
                ->exists();

            if ($hasCancellationEvidence || $hasArrivalHistory || $hasServiceProgress) {
                throw new BookingConflictException('Không thể đánh dấu vắng mặt vì lịch hẹn đã có bằng chứng hủy hoặc khách đã đến.');
            }
        }
    }

    private function recordTransitionNotifications(Booking $booking, array $context, string $target, int $historyId): void
    {
        if ($booking->customer_profile_id !== null) {
            $customerUserId = CustomerProfile::query()->whereKey($booking->customer_profile_id)->value('user_id');
            if ($customerUserId) {
                $this->notifications->record(
                    (int) $customerUserId,
                    'BOOKING_STATUS_CHANGED',
                    'Trạng thái lịch hẹn đã thay đổi',
                    'Lịch hẹn '.$booking->booking_code.' hiện ở trạng thái '.$target.'.',
                    $booking->id,
                    'booking-status:'.$historyId.':customer:'.$customerUserId,
                    $target === 'NO_SHOW' ? 'WARNING' : 'INFO',
                    ['status' => $target],
                );
            }
        }

        if ($context['kind'] !== 'CUSTOMER') {
            return;
        }

        $recipientIds = RoleAssignment::query()
            ->where('business_id', $booking->business_id)
            ->where(function ($query) use ($booking) {
                $query->where('role_name', 'BUSINESS_OWNER')
                    ->orWhere(function ($branchQuery) use ($booking) {
                        $branchQuery->where('role_name', 'RECEPTIONIST')->where('branch_id', $booking->branch_id);
                    });
            })
            ->pluck('user_id')
            ->unique();

        foreach ($recipientIds as $userId) {
            $this->notifications->record(
                (int) $userId,
                'BOOKING_STATUS_CHANGED',
                'Khách đã cập nhật lịch hẹn',
                'Lịch hẹn '.$booking->booking_code.' hiện ở trạng thái '.$target.'.',
                $booking->id,
                'booking-status:'.$historyId.':salon:'.$userId,
                'INFO',
                ['status' => $target],
            );
        }
    }
}
