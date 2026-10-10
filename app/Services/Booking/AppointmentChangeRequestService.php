<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\CustomerProfile;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentChangeRequestService
{
    public function __construct(
        private BookingLifecycleAccessService $access,
        private BookingPolicyService $policy,
        private BookingNotificationService $notifications,
    ) {
    }

    public function create(Request $request, Booking $booking, array $data, ?CarbonImmutable $at = null): AppointmentChangeRequest
    {
        $context = $this->access->resolve($request, $booking);
        abort_if($context['kind'] === 'STAFF', 403);
        $now = $at ?? CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($booking, $context, $data, $now) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCurrent($locked, $context);

            if (! in_array($locked->status, ['PENDING', 'CONFIRMED'], true)) {
                throw new BookingConflictException('Lịch hẹn không còn ở trạng thái cho phép gửi yêu cầu thay đổi.');
            }

            AppointmentChangeRequest::query()
                ->where('booking_id', $locked->id)
                ->where('status', 'PENDING')
                ->where('expires_at', '<=', $now)
                ->update(['status' => 'EXPIRED', 'updated_at' => now()]);

            $activeRequest = AppointmentChangeRequest::query()
                ->where('booking_id', $locked->id)
                ->where('status', 'PENDING')
                ->where('expires_at', '>', $now)
                ->lockForUpdate()
                ->exists();
            if ($activeRequest) {
                throw new BookingConflictException('Lịch hẹn đã có một yêu cầu thay đổi đang chờ xử lý.');
            }

            $type = strtoupper((string) $data['request_type']);
            abort_unless(in_array($type, ['CANCEL', 'RESCHEDULE', 'STAFF_CHANGE'], true), 422);
            $this->assertCreatePayload($locked, $type, $data, $context, $now);

            $row = AppointmentChangeRequest::query()->create([
                'booking_id' => $locked->id,
                'requested_by_user_id' => $context['actor_user_id'],
                'requested_by_type' => $context['kind'] === 'CUSTOMER' ? 'CUSTOMER' : ($context['kind'] === 'ADMIN' ? 'ADMIN' : 'SALON'),
                'request_type' => $type,
                'proposed_start_at' => $data['proposed_start_at'] ?? null,
                'proposed_end_at' => $data['proposed_end_at'] ?? null,
                'proposed_staff_profile_id' => $data['proposed_staff_profile_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => 'PENDING',
                'expires_at' => $now->addHours(24),
            ]);

            if ($context['kind'] === 'CUSTOMER' && $type === 'CANCEL') {
                $start = $this->bookingStart($locked);
                if ($now->greaterThan($start->subHours(4)) && $now->lessThan($start)) {
                    $occurredAt = CarbonImmutable::parse($row->getRawOriginal('created_at'), 'UTC');
                    $this->policy->recordViolation($locked, 'LATE_CANCELLATION', $context['actor_user_id'], $row->id, $occurredAt);
                }
            }

            $this->notifyRequestCreated($locked, $row, $context);

            return $row->refresh();
        }, 3);
    }

    public function approve(
        Request $request,
        Booking $booking,
        AppointmentChangeRequest $changeRequest,
        ?string $reviewNote = null,
        ?CarbonImmutable $at = null,
    ): AppointmentChangeRequest {
        $context = $this->access->resolve($request, $booking);
        abort_unless(in_array($context['kind'], ['OWNER', 'RECEPTIONIST', 'ADMIN'], true), 403);
        $now = $at ?? CarbonImmutable::now('UTC');

        $result = DB::transaction(function () use ($booking, $changeRequest, $context, $reviewNote, $now) {
            $lockedBooking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCurrent($lockedBooking, $context);
            $lockedRequest = AppointmentChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedRequest->booking_id === (int) $lockedBooking->id, 404);

            if ($this->expirePendingIfNeeded($lockedRequest, $now)) {
                return $lockedRequest->refresh();
            }

            $this->assertPendingReviewable($lockedBooking, $lockedRequest);

            if ($lockedRequest->request_type === 'CANCEL') {
                $this->applyCancellation($lockedBooking, $context['actor_user_id'], $lockedRequest->reason);
            } elseif ($lockedRequest->request_type === 'RESCHEDULE') {
                $this->applyReschedule($lockedBooking, $lockedRequest);
            } elseif ($lockedRequest->request_type === 'STAFF_CHANGE') {
                $this->applyStaffChange($lockedBooking, $lockedRequest);
            }

            $lockedRequest->status = 'APPROVED';
            $lockedRequest->reviewed_by_user_id = $context['actor_user_id'];
            $lockedRequest->reviewed_at = $now;
            $lockedRequest->review_note = $reviewNote;
            $lockedRequest->save();

            $this->notifyRequestReviewed($lockedBooking, $lockedRequest, 'APPROVED');

            return $lockedRequest->refresh();
        }, 3);

        if ($result->status === 'EXPIRED') {
            throw new BookingConflictException('Yêu cầu thay đổi đã hết hạn.');
        }

        return $result;
    }

    public function reject(
        Request $request,
        Booking $booking,
        AppointmentChangeRequest $changeRequest,
        ?string $reviewNote = null,
        ?CarbonImmutable $at = null,
    ): AppointmentChangeRequest {
        $context = $this->access->resolve($request, $booking);
        abort_unless(in_array($context['kind'], ['OWNER', 'RECEPTIONIST', 'ADMIN'], true), 403);
        $now = $at ?? CarbonImmutable::now('UTC');

        $result = DB::transaction(function () use ($booking, $changeRequest, $context, $reviewNote, $now) {
            $lockedBooking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCurrent($lockedBooking, $context);
            $lockedRequest = AppointmentChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedRequest->booking_id === (int) $lockedBooking->id, 404);

            if ($this->expirePendingIfNeeded($lockedRequest, $now)) {
                return $lockedRequest->refresh();
            }

            $this->assertPendingReviewable($lockedBooking, $lockedRequest);
            $lockedRequest->status = 'REJECTED';
            $lockedRequest->reviewed_by_user_id = $context['actor_user_id'];
            $lockedRequest->reviewed_at = $now;
            $lockedRequest->review_note = $reviewNote;
            $lockedRequest->save();

            $this->notifyRequestReviewed($lockedBooking, $lockedRequest, 'REJECTED');

            return $lockedRequest->refresh();
        }, 3);

        if ($result->status === 'EXPIRED') {
            throw new BookingConflictException('Yêu cầu thay đổi đã hết hạn.');
        }

        return $result;
    }

    private function assertCreatePayload(Booking $booking, string $type, array $data, array $context, CarbonImmutable $now): void
    {
        if ($context['kind'] === 'CUSTOMER' && $type === 'CANCEL' && ! $now->lessThan($this->bookingStart($booking))) {
            throw new BookingConflictException('Lịch hẹn đã bắt đầu. Vui lòng liên hệ cơ sở để được hỗ trợ.');
        }

        if ($type === 'RESCHEDULE') {
            $settings = app(\App\Services\Platform\PlatformSettingsService::class)->get();
            if (! $settings['allowRescheduleRequests'] || AppointmentChangeRequest::where('booking_id', $booking->id)
                ->where('request_type', 'RESCHEDULE')->where('status', 'APPROVED')->count() >= $settings['maxRescheduleCountPerBooking']) {
                throw new BookingConflictException('Lịch hẹn không được phép gửi thêm yêu cầu đổi lịch.');
            }
            if (empty($data['proposed_start_at']) || empty($data['proposed_end_at'])) {
                throw new BookingConflictException('Yêu cầu đổi lịch phải có giờ bắt đầu và kết thúc mới.');
            }
            $start = CarbonImmutable::parse($data['proposed_start_at'])->utc();
            $end = CarbonImmutable::parse($data['proposed_end_at'])->utc();
            if (! $start->lessThan($end) || ! $start->greaterThan($now)) {
                throw new BookingConflictException('Khung giờ đề xuất không hợp lệ.');
            }
            $oldStart = $this->bookingStart($booking);
            $oldEnd = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
            if ($oldStart->diffInSeconds($oldEnd) !== $start->diffInSeconds($end)) {
                throw new BookingConflictException('Đổi lịch phải giữ nguyên tổng thời lượng dịch vụ.');
            }
        }

        if ($type === 'STAFF_CHANGE' && empty($data['proposed_staff_profile_id'])) {
            throw new BookingConflictException('Yêu cầu đổi nhân viên phải chỉ định nhân viên mới.');
        }
    }

    private function expirePendingIfNeeded(AppointmentChangeRequest $request, CarbonImmutable $now): bool
    {
        if ($request->status !== 'PENDING') {
            return false;
        }
        if ($now->lessThan(CarbonImmutable::parse($request->getRawOriginal('expires_at'), 'UTC'))) {
            return false;
        }

        $request->status = 'EXPIRED';
        $request->save();

        return true;
    }

    private function assertPendingReviewable(Booking $booking, AppointmentChangeRequest $request): void
    {
        if ($request->status !== 'PENDING') {
            throw new BookingConflictException('Yêu cầu này đã được xử lý trước đó.');
        }
        if (! in_array($booking->status, ['PENDING', 'CONFIRMED'], true)) {
            throw new BookingConflictException('Lịch hẹn không còn ở trạng thái cho phép áp dụng yêu cầu.');
        }
    }

    private function applyCancellation(Booking $booking, int $actorUserId, ?string $reason): void
    {
        $from = $booking->status;
        $booking->status = 'CANCELLED';
        app(\App\Services\Promotions\PromotionPricingService::class)->transition($booking, 'CANCELLED');
        $booking->save();
        app(\App\Services\Finance\PackageService::class)->redeemForBooking($booking->id, 'CANCELLED');
        BookingService::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        BookingStatusHistory::query()->create([
            'booking_id' => $booking->id,
            'actor_user_id' => $actorUserId,
            'from_status' => $from,
            'to_status' => 'CANCELLED',
            'reason' => $reason,
        ]);
    }

    private function applyReschedule(Booking $booking, AppointmentChangeRequest $request): void
    {
        $newStart = CarbonImmutable::parse($request->getRawOriginal('proposed_start_at'), 'UTC');
        $newEnd = CarbonImmutable::parse($request->getRawOriginal('proposed_end_at'), 'UTC');
        $oldStart = $this->bookingStart($booking);
        $delta = $newStart->getTimestamp() - $oldStart->getTimestamp();

        if ($booking->customer_profile_id !== null) {
            $customerConflict = Booking::query()
                ->where('customer_profile_id', $booking->customer_profile_id)
                ->whereKeyNot($booking->id)
                ->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])
                ->where('appointment_start_at', '<', $newEnd)
                ->where('appointment_end_at', '>', $newStart)
                ->lockForUpdate()
                ->exists();
            if ($customerConflict) {
                throw new BookingConflictException('Khung giờ mới trùng với một lịch hẹn khác của khách.');
            }
        }

        $items = BookingService::query()->where('booking_id', $booking->id)->lockForUpdate()->get();
        foreach ($items as $item) {
            $occupiedStart = CarbonImmutable::parse($item->getRawOriginal('occupied_start_at'), 'UTC')->addSeconds($delta);
            $occupiedEnd = CarbonImmutable::parse($item->getRawOriginal('occupied_end_at'), 'UTC')->addSeconds($delta);
            if ($item->staff_profile_id !== null) {
                $staffConflict = BookingService::query()
                    ->where('staff_profile_id', $item->staff_profile_id)
                    ->where('booking_id', '<>', $booking->id)
                    ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                    ->where('occupied_start_at', '<', $occupiedEnd)
                    ->where('occupied_end_at', '>', $occupiedStart)
                    ->lockForUpdate()
                    ->exists();
                if ($staffConflict) {
                    throw new BookingConflictException('Nhân viên đã có lịch trùng với khung giờ mới.');
                }
            }
        }

        foreach ($items as $item) {
            foreach (['item_start_at', 'item_end_at', 'occupied_start_at', 'occupied_end_at'] as $field) {
                $item->{$field} = CarbonImmutable::parse($item->getRawOriginal($field), 'UTC')->addSeconds($delta);
            }
            $item->revision = ((int) $item->revision) + 1;
            $item->save();
        }

        $booking->appointment_start_at = $newStart;
        $booking->appointment_end_at = $newEnd;
        $booking->save();
    }

    private function applyStaffChange(Booking $booking, AppointmentChangeRequest $request): void
    {
        $staff = StaffProfile::query()
            ->whereKey($request->proposed_staff_profile_id)
            ->where('business_id', $booking->business_id)
            ->where('branch_id', $booking->branch_id)
            ->where('status', 'ACTIVE')
            ->where('is_bookable', true)
            ->lockForUpdate()
            ->first();
        if (! $staff) {
            throw new BookingConflictException('Nhân viên đề xuất không còn khả dụng tại chi nhánh này.');
        }

        $items = BookingService::query()->where('booking_id', $booking->id)->lockForUpdate()->get();
        foreach ($items as $item) {
            $qualified = DB::table('staff_services')
                ->where('staff_profile_id', $staff->id)
                ->where('branch_service_offering_id', $item->branch_service_offering_id)
                ->where('business_id', $booking->business_id)
                ->where('branch_id', $booking->branch_id)
                ->exists();
            if (! $qualified) {
                throw new BookingConflictException('Nhân viên đề xuất không đủ điều kiện thực hiện dịch vụ này.');
            }

            $conflict = BookingService::query()
                ->where('staff_profile_id', $staff->id)
                ->where('booking_id', '<>', $booking->id)
                ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                ->where('occupied_start_at', '<', $item->getRawOriginal('occupied_end_at'))
                ->where('occupied_end_at', '>', $item->getRawOriginal('occupied_start_at'))
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw new BookingConflictException('Nhân viên đề xuất đã có lịch trùng giờ.');
            }
        }

        foreach ($items as $item) {
            $item->staff_profile_id = $staff->id;
            $item->revision = ((int) $item->revision) + 1;
            $item->save();
        }
    }

    private function notifyRequestCreated(Booking $booking, AppointmentChangeRequest $request, array $context): void
    {
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
                'BOOKING_CHANGE_REQUEST',
                $request->request_type === 'CANCEL' ? 'Khách gửi yêu cầu hủy lịch' : 'Khách gửi yêu cầu thay đổi lịch',
                $request->reason ?: 'Vui lòng xem yêu cầu trong lịch hẹn.',
                $booking->id,
                'change-request:'.$request->id.':created:'.$userId,
                $request->request_type === 'CANCEL' ? 'WARNING' : 'INFO',
                ['request_id' => $request->id, 'request_type' => $request->request_type],
            );
        }
    }

    private function notifyRequestReviewed(Booking $booking, AppointmentChangeRequest $request, string $status): void
    {
        $userIds = collect([$request->requested_by_user_id]);
        if ($booking->customer_profile_id !== null) {
            $customerUserId = CustomerProfile::query()->whereKey($booking->customer_profile_id)->value('user_id');
            if ($customerUserId) {
                $userIds->push((int) $customerUserId);
            }
        }

        foreach ($userIds->unique() as $userId) {
            $this->notifications->record(
                (int) $userId,
                'BOOKING_CHANGE_REQUEST_REVIEWED',
                $status === 'APPROVED' ? 'Yêu cầu thay đổi đã được duyệt' : 'Yêu cầu thay đổi đã bị từ chối',
                $request->review_note,
                $booking->id,
                'change-request:'.$request->id.':'.$status.':'.$userId,
                $status === 'APPROVED' ? 'INFO' : 'WARNING',
                ['request_id' => $request->id, 'status' => $status],
            );
        }
    }

    private function bookingStart(Booking $booking): CarbonImmutable
    {
        return CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
    }
}
