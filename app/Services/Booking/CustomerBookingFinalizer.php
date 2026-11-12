<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Services\Promotions\PromotionPricingService;
use App\Services\Payments\DirectDeposit;
use App\Services\Wallet\WalletCore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a customer's own booking needs once it exists (booking-aggregate.ts), shared by the single, cart and combo
 * create paths so none of them can drift: the wallet deposit is held, the pending hold is tied to the deposit expiry,
 * and a branch on AUTO confirmation confirms the booking in the same transaction.
 * Counter bookings (owner / receptionist) are never touched: staff confirm those themselves.
 */
class CustomerBookingFinalizer
{
    /** @return bool true when the booking was confirmed by the branch's AUTO policy */
    public static function finalize(Booking $booking, array $context, object $branch, int|float|string $total, \Carbon\CarbonInterface|string $startAt, ?string $acceptedQuote): bool
    {
        if ($context['kind'] !== 'CUSTOMER') return false;
        $rules = BranchBookingRules::for((int) $branch->id);
        $deposit = WalletCore::holdForCustomerBooking($booking, $context, $branch, $total, $startAt, $acceptedQuote, $rules)
            ?? DirectDeposit::createFor($booking, $branch, $total, CarbonImmutable::parse($startAt, 'UTC'), $acceptedQuote, $rules);
        if ($deposit) {
            // One instant for both: the booking can never outlive its deposit hold, nor the deposit its booking.
            $booking->forceFill(['pending_expires_at' => CarbonImmutable::parse($deposit->expires_at, 'UTC')->setTimezone(config('app.timezone'))])->save();
        }
        // A direct deposit is paid by a later bank receipt: AUTO confirmation waits for that receipt, never for the booking.
        if ($deposit && $deposit->funding_source === DirectDeposit::FUNDING) return false;
        if ($rules['confirmationMode'] !== 'AUTO') return false;

        self::confirmByPolicy($booking, (int) $context['actor_user_id'], 'AUTO_CONFIRMATION_POLICY');

        return true;
    }

    /** Confirms a PENDING booking by the branch's AUTO policy: history (SYSTEM), promotion transition, notification. Caller holds the booking lock. */
    public static function confirmByPolicy(Booking $booking, int $customerUserId, string $reason): void
    {
        $booking->forceFill(['status' => 'CONFIRMED', 'pending_expires_at' => null])->save();
        $history = BookingStatusHistory::create(['booking_id' => $booking->id, 'actor_user_id' => $customerUserId, 'from_status' => 'PENDING', 'to_status' => 'CONFIRMED', 'reason' => $reason]);
        DB::table('booking_status_histories')->where('id', $history->id)->update(['actor_kind' => 'SYSTEM']);
        app(PromotionPricingService::class)->transition($booking, 'CONFIRMED');
        app(BookingNotificationService::class)->record($customerUserId, 'BOOKING_STATUS_CHANGED', 'Lịch hẹn đã được xác nhận', 'Lịch hẹn '.$booking->booking_code.' đã được cơ sở tự động xác nhận.', $booking->id, 'booking-auto-confirmed:'.$booking->id);
    }
}
