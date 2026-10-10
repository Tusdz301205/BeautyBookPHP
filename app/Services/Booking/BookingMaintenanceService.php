<?php

namespace App\Services\Booking;

use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use Illuminate\Support\Facades\DB;

class BookingMaintenanceService
{
    public function expire(int $limit = 500): int
    {
        $count = 0;
        $ids = Booking::query()->where('status', 'PENDING')->where('pending_expires_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $booking = Booking::query()->whereKey($id)->lockForUpdate()->first();
                if (! $booking || $booking->status !== 'PENDING' || ! $booking->pending_expires_at?->isPast()) {
                    return 0;
                }
                $booking->update(['status' => 'EXPIRED', 'pending_expires_at' => null]);
                BookingService::query()->where('booking_id', $id)->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
                    ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                BookingStatusHistory::query()->create([
                    'booking_id' => $id, 'actor_user_id' => null, 'from_status' => 'PENDING',
                    'to_status' => 'EXPIRED', 'reason' => 'Hết thời gian giữ chỗ',
                ]);
                app(\App\Services\Promotions\PromotionPricingService::class)->transition($booking, 'EXPIRED');
                app(\App\Services\Finance\PackageService::class)->redeemForBooking($booking->id, 'EXPIRED');
                return 1;
            }, 3);
        }
        AppointmentChangeRequest::query()->where('status', 'PENDING')->where('expires_at', '<=', now())
            ->update(['status' => 'EXPIRED', 'updated_at' => now()]);
        return $count;
    }

    /** Database inbox delivery; no email or device push is claimed. */
    public function drainOutbox(int $limit = 50): int
    {
        $ids = DB::table('notification_outbox')->whereIn('status', ['PENDING', 'FAILED'])
            ->where('attempts', '<', 10)->where('available_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $row = DB::table('notification_outbox')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->status === 'SENT' || $row->attempts >= 10 || $row->available_at > now()->format('Y-m-d H:i:s.u')) {
                    return 0;
                }
                $notification = DB::table('notifications')->where('id', $row->notification_id)
                    ->where('user_id', $row->user_id)->where('dedupe_key', $row->dedupe_key)->first();
                if (! $notification) {
                    DB::table('notification_outbox')->where('id', $id)->update([
                        'status' => 'FAILED', 'attempts' => $row->attempts + 1,
                        'available_at' => now()->addSeconds(min(1800, 2 ** ($row->attempts + 1))),
                        'last_error' => 'Inbox projection unavailable', 'updated_at' => now(),
                    ]);
                    return 0;
                }
                DB::table('notification_outbox')->where('id', $id)->update([
                    'status' => 'SENT', 'attempts' => $row->attempts + 1,
                    'sent_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
                return 1;
            }, 3);
        }
        return $count;
    }
}
