<?php

namespace App\Services\Booking;

use App\Models\BookingService;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\BranchWorkingHour;
use App\Models\StaffProfile;
use App\Services\Platform\PlatformSettingsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AvailabilityService
{
    private const BLOCKING_STATUSES = ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'];

    public function resolveStaff(
        Branch $branch,
        BranchServiceOffering $offering,
        int $staffProfileId,
        bool $customerVisible,
        bool $lock = false,
    ): StaffProfile {
        $query = StaffProfile::query()
            ->whereKey($staffProfileId)
            ->where('business_id', $branch->business_id)
            ->where('branch_id', $branch->id)
            ->where('status', 'ACTIVE')
            ->where('is_bookable', true);

        if ($customerVisible) {
            $query->where('public_visible', true);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $staff = $query->first();

        if (! $staff) {
            throw new DomainException('Nhân viên không khả dụng tại chi nhánh này.');
        }

        $capabilityQuery = DB::table('staff_services')
            ->where('staff_profile_id', $staff->id)
            ->where('branch_service_offering_id', $offering->id)
            ->where('business_id', $branch->business_id)
            ->where('branch_id', $branch->id);

        if ($lock) {
            $capabilityQuery->lockForUpdate();
        }

        if (! $capabilityQuery->first()) {
            throw new DomainException('Nhân viên chưa được cấp capability cho dịch vụ này.');
        }

        return $staff;
    }

    public function check(
        Branch $branch,
        ?StaffProfile $staff,
        array $quote,
        string $localStartValue,
        ?int $customerProfileId = null,
        bool $lock = false,
        ?int $ignoreBookingId = null,
        bool $allowHistoricalWindow = false,
        string $source = 'ONLINE_WEB',
    ): array {
        $timezone = $branch->timezone ?: 'Asia/Ho_Chi_Minh';
        $itemStartLocal = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $localStartValue, $timezone);

        if (! $itemStartLocal) {
            throw new DomainException('Thời gian bắt đầu không hợp lệ.');
        }

        if (! $allowHistoricalWindow && $itemStartLocal->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
            throw new DomainException('Thời gian đặt lịch phải ở tương lai.');
        }

        $settings = app(PlatformSettingsService::class)->get();
        $branchSettings = Schema::hasTable('beautybook_branch_settings') ? DB::table('beautybook_branch_settings')->where('branch_id', $branch->id)->first() : null;
        $branchPolicy = $branchSettings ? (json_decode($branchSettings->booking_policy ?? '{}', true) ?: []) : [];
        if ($source === 'WALK_IN' && ($branchPolicy['allowWalkIn'] ?? true) === false) {
            throw new DomainException('Chi nhánh không nhận lịch walk-in.');
        }
        if (in_array($source, ['WALK_IN', 'PHONE', 'STAFF_CREATED'], true) && ($branchPolicy['allowCounterBooking'] ?? true) === false) {
            throw new DomainException('Chi nhánh không nhận lịch tại quầy.');
        }
        $localNow = CarbonImmutable::now($timezone);
        if (! $allowHistoricalWindow && ($itemStartLocal->lessThan($localNow->addMinutes(in_array($source, ['WALK_IN', 'STAFF_CREATED'], true) ? 0 : ($branchPolicy['leadTimeMinutes'] ?? $settings['minBookingLeadTimeHours'] * 60)))
            || $itemStartLocal->greaterThan($localNow->addDays($branchPolicy['bookingHorizonDays'] ?? $settings['maxAdvanceBookingDays'])))) {
            throw new DomainException('Khung giờ nằm ngoài khoảng đặt trước được cho phép.');
        }

        $itemEndLocal = $itemStartLocal->addMinutes((int) $quote['duration_minutes']);
        $occupiedStartLocal = $itemStartLocal->subMinutes((int) $quote['buffer_before_minutes']);
        $occupiedEndLocal = $itemEndLocal->addMinutes((int) $quote['buffer_after_minutes']);

        $hoursQuery = BranchWorkingHour::query()
            ->where('branch_id', $branch->id)
            ->where('day_of_week', $itemStartLocal->dayOfWeek);

        if ($lock) {
            $hoursQuery->lockForUpdate();
        }

        $hours = $hoursQuery->first();
        if (Schema::hasTable('beautybook_branch_calendar')) {
            $exceptionQuery = DB::table('beautybook_branch_calendar')->where('branch_id', $branch->id)->where('date', $itemStartLocal->toDateString());
            if ($lock) {
                $exceptionQuery->lockForUpdate();
            }
            $exception = $exceptionQuery->first();
            if ($exception) {
                $hours = $exception;
            }
        }

        if (! $hours || $hours->is_closed || ! $hours->open_time || ! $hours->close_time) {
            throw new DomainException('Chi nhánh đóng cửa vào ngày đã chọn.');
        }

        $open = CarbonImmutable::parse($itemStartLocal->toDateString().' '.$hours->open_time, $timezone);
        $close = CarbonImmutable::parse($itemStartLocal->toDateString().' '.$hours->close_time, $timezone);

        if ($occupiedStartLocal->lessThan($open) || $occupiedEndLocal->greaterThan($close)) {
            throw new DomainException('Khung giờ nằm ngoài giờ phục vụ của chi nhánh.');
        }

        $itemStartUtc = $itemStartLocal->utc();
        $itemEndUtc = $itemEndLocal->utc();
        $occupiedStartUtc = $occupiedStartLocal->utc();
        $occupiedEndUtc = $occupiedEndLocal->utc();

        $staffOverlap = BookingService::query()
            ->where('staff_profile_id', $staff?->id)
            ->where('occupied_start_at', '<', $occupiedEndUtc)
            ->where('occupied_end_at', '>', $occupiedStartUtc)
            ->whereHas('booking', function ($booking) use ($ignoreBookingId) {
                $booking->whereIn('status', self::BLOCKING_STATUSES);

                if ($ignoreBookingId !== null) {
                    $booking->whereKeyNot($ignoreBookingId);
                }
            });

        if ($lock) {
            $staffOverlap->lockForUpdate();
        }

        if ($staff !== null && $staffOverlap->first()) {
            throw new DomainException('Nhân viên đã có lịch trùng trong khung giờ này.');
        }

        if ($customerProfileId !== null) {
            $customerOverlap = BookingService::query()
                ->where('item_start_at', '<', $itemEndUtc)
                ->where('item_end_at', '>', $itemStartUtc)
                ->whereHas('booking', function ($booking) use ($customerProfileId) {
                    $booking->where('customer_profile_id', $customerProfileId)
                        ->whereIn('status', self::BLOCKING_STATUSES);
                });

            if ($ignoreBookingId !== null) {
                $customerOverlap->where('booking_id', '<>', $ignoreBookingId);
            }

            if ($lock) {
                $customerOverlap->lockForUpdate();
            }

            if ($customerOverlap->first()) {
                throw new DomainException('Bạn đã có lịch hẹn khác trùng thời gian này.');
            }
        }

        return [
            'item_start_at' => $itemStartUtc,
            'item_end_at' => $itemEndUtc,
            'occupied_start_at' => $occupiedStartUtc,
            'occupied_end_at' => $occupiedEndUtc,
            'local_start_at' => $itemStartLocal,
            'local_end_at' => $itemEndLocal,
        ];
    }
}
