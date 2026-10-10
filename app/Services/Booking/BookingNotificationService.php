<?php

namespace App\Services\Booking;

use Illuminate\Support\Facades\DB;

class BookingNotificationService
{
    public function record(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?int $bookingId,
        string $dedupeKey,
        string $severity = 'INFO',
        array $metadata = [],
    ): void {
        DB::table('notifications')->insertOrIgnore([
            'user_id' => $userId,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'is_read' => false,
            'target_type' => $bookingId === null ? null : 'Booking',
            'target_id' => $bookingId === null ? null : (string) $bookingId,
            'action_url' => $bookingId === null ? null : '/bookings?booking='.$bookingId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'related_booking_id' => $bookingId,
            'dedupe_key' => $dedupeKey,
            'created_at' => now(),
        ]);

        $notification = DB::table('notifications')->where('dedupe_key', $dedupeKey)->first();

        if (! $notification) {
            return;
        }

        DB::table('notification_outbox')->insertOrIgnore([
            'user_id' => $userId,
            'notification_id' => $notification->id,
            'related_booking_id' => $bookingId,
            'dedupe_key' => $dedupeKey,
            'status' => 'PENDING',
            'attempts' => 0,
            'available_at' => now(),
            'payload' => json_encode([
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'booking_id' => $bookingId,
                'metadata' => $metadata,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
