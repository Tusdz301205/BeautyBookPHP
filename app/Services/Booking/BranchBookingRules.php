<?php

namespace App\Services\Booking;

use App\Services\Platform\PlatformSettingsService;
use Illuminate\Support\Facades\DB;

/**
 * The booking rules a branch owner configures (BranchBookingPolicy in the source): the direct-cancellation cutoff,
 * how long a pending booking is held, whether bookings confirm themselves, how late a customer may still ask to reschedule,
 * how early the salon may check a customer in and how long it waits before marking a no-show. They live in
 * beautybook_branch_settings (the owner UI writes `booking_policy.cancellationHours|rescheduleHours|earlyCheckInMinutes|gracePeriodMinutes`,
 * `metadata.pendingHoldMinutes`, `metadata.bookingConfirmationMode`). A branch that never stored a value (or stored one out of
 * bounds) keeps what the code did before the rules were read: 4 hours to cancel, the platform hold, manual confirmation,
 * no reschedule cutoff, check-in from 30 minutes before and no-show after 15 minutes.
 */
class BranchBookingRules
{
    public const DEFAULT_CANCELLATION_HOURS = 4;

    public const DEFAULT_EARLY_CHECK_IN_MINUTES = 30;

    public const DEFAULT_GRACE_PERIOD_MINUTES = 15;

    /** @return array{cancellationHours:int,pendingHoldMinutes:int,confirmationMode:'MANUAL'|'AUTO',rescheduleHours:int,earlyCheckInMinutes:int,gracePeriodMinutes:int} */
    public static function for(int $branchId): array
    {
        $row = DB::table('beautybook_branch_settings')->where('branch_id', $branchId)->first(['metadata', 'booking_policy']);
        $metadata = json_decode($row->metadata ?? '{}', true) ?: [];
        $policy = json_decode($row->booking_policy ?? '{}', true) ?: [];

        return [
            'cancellationHours' => self::bounded($policy['cancellationHours'] ?? null, 0, 168, self::DEFAULT_CANCELLATION_HOURS),
            'pendingHoldMinutes' => self::bounded($metadata['pendingHoldMinutes'] ?? null, 5, 1440, (int) app(PlatformSettingsService::class)->value('pendingHoldMinutes')),
            'confirmationMode' => ($metadata['bookingConfirmationMode'] ?? null) === 'AUTO_CONFIRMATION' ? 'AUTO' : 'MANUAL',
            'rescheduleHours' => self::bounded($policy['rescheduleHours'] ?? null, 0, 720, 0),
            'earlyCheckInMinutes' => self::bounded($policy['earlyCheckInMinutes'] ?? null, 0, 1440, self::DEFAULT_EARLY_CHECK_IN_MINUTES),
            'gracePeriodMinutes' => self::bounded($policy['gracePeriodMinutes'] ?? null, 0, 1440, self::DEFAULT_GRACE_PERIOD_MINUTES),
        ];
    }

    public static function cancellationHours(int $branchId): int
    {
        return self::for($branchId)['cancellationHours'];
    }

    private static function bounded(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) && (int) $value == $value && (int) $value >= $min && (int) $value <= $max ? (int) $value : $default;
    }
}
