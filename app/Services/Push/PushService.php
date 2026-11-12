<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Device push built on the notification outbox (push-queue.ts / push.worker.ts). A notification row is the
 * source of truth: recording one queues a delivery per active device of the recipient, and the worker sends
 * each delivery at most once per claim, retries transient failures with backoff and revokes dead tokens.
 */
class PushService
{
    public function __construct(private FcmClient $fcm) {}

    public static function enabled(): bool
    {
        return (bool) config('push.enabled');
    }

    /** The trial FCM stand-in is available only when explicitly enabled in a local or testing environment. */
    /** The public Firebase web settings for the browser, or null unless every one of them is set. */
    public static function webConfig(): ?array
    {
        $web = (array) config('push.web');
        $project = config('push.project_id');
        foreach ([$project, $web['api_key'] ?? null, $web['app_id'] ?? null, $web['sender_id'] ?? null, $web['vapid_key'] ?? null] as $value) {
            if (! is_string($value) || trim($value) === '') return null;
        }

        return ['apiKey' => $web['api_key'], 'projectId' => $project, 'messagingSenderId' => $web['sender_id'], 'appId' => $web['app_id'], 'vapidKey' => $web['vapid_key']];
    }

    public static function trialSink(): bool
    {
        return (bool) config('push.trial_sink') && app()->environment(['local', 'testing']);
    }

    /**
     * The push events of push-policy.ts, mapped from this app's notification types: booking confirmed / rejected /
     * cancelled, change-request outcomes and the one-hour reminder. Chat and every staff-side notice are not pushed.
     */
    public static function pushEvent(string $type, array $metadata): ?string
    {
        return match ($type) {
            'BOOKING_STATUS_CHANGED' => ['CONFIRMED' => 'BOOKING_CONFIRMED', 'REJECTED' => 'BOOKING_REJECTED', 'CANCELLED' => 'BOOKING_CANCELLED'][$metadata['status'] ?? ''] ?? null,
            'BOOKING_CHANGE_REQUEST_REVIEWED' => ['APPROVED' => 'BOOKING_CHANGE_APPROVED', 'REJECTED' => 'BOOKING_CHANGE_REJECTED', 'EXPIRED' => 'BOOKING_CHANGE_EXPIRED'][$metadata['status'] ?? ''] ?? null,
            'BOOKING_REMINDER' => 'BOOKING_REMINDER',
            default => null,
        };
    }

    public static function sessionHash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** The dedupe key of a booking reminder: it carries the start time, so a rescheduled booking is reminded for its new time. */
    public static function reminderKey(int $bookingId, string $startAt): string
    {
        return 'BOOKING_REMINDER:'.$bookingId.':'.\Carbon\CarbonImmutable::parse($startAt, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Is the login session behind a device still alive (push-queue.ts: revokedAt null, expiresAt in the future)? Only the
     * database session driver keeps rows that can be asked; with any other driver a session ends through the explicit
     * revocations below (logout, password change, revoking sessions, deactivation).
     */
    public static function sessionAlive(int $userId, ?string $sessionHash): bool
    {
        if (! $sessionHash) return false;
        if (config('session.driver') !== 'database') {
            // Only the database driver can answer "is this session still alive?". For any other driver the device is trusted for one
            // session lifetime counted from its last registration (the app registers again at every start), never indefinitely.
            return DB::table('beautybook_device_tokens')->where('user_id', $userId)->where('session_id_hash', $sessionHash)->where('active', true)
                ->where('updated_at', '>', now()->subMinutes((int) config('session.lifetime')))->exists();
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $userId)->where('last_activity', '>', time() - (int) config('session.lifetime') * 60)
            ->whereRaw('SHA2(id, 256) = ?', [$sessionHash])->exists();
    }

    /** The account's own customer access, valid right now (push.worker.ts: a CUSTOMER role without expiry or not yet expired). */
    public static function customerAccess(int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('role_name', 'CUSTOMER')->where('scope_key', 'self')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->exists();
    }

    public function queue(int $notificationId, int $userId, string $type): int
    {
        if (! self::enabled()) return 0;
        $notification = DB::table('notifications')->where('id', $notificationId)->first(['related_booking_id', 'metadata']);
        $event = $notification ? self::pushEvent($type, (array) json_decode((string) $notification->metadata, true)) : null;
        if (! $event || ! $notification->related_booking_id) return 0;
        // Only the booking's own customer is pushed; a notification addressed to anybody else never reaches a device.
        $booking = DB::table('bookings as b')->join('customer_profiles as p', 'p.id', '=', 'b.customer_profile_id')->whereNull('p.deleted_at')->where('b.id', $notification->related_booking_id)->first(['p.user_id', 'b.appointment_start_at']);
        if (! $booking || (int) $booking->user_id !== $userId) return 0;
        $user = DB::table('users')->where('id', $userId)->where('is_active', true)->first();
        if (! $user || ! self::customerAccess($userId)) return 0;
        $now = now('UTC');
        $expires = $event === 'BOOKING_REMINDER' ? \Carbon\CarbonImmutable::parse($booking->appointment_start_at, 'UTC') : $now->copy()->addDay();
        if ($expires->lessThanOrEqualTo($now)) return 0;
        $count = 0;
        foreach (DB::table('beautybook_device_tokens')->where('user_id', $userId)->where('active', true)->whereNotNull('session_id_hash')->get(['id', 'session_id_hash']) as $device) {
            if (! self::sessionAlive($userId, $device->session_id_hash)) continue;
            $count += DB::table('push_deliveries')->insertOrIgnore(['notification_id' => $notificationId, 'device_id' => $device->id, 'session_id_hash' => $device->session_id_hash, 'status' => 'PENDING', 'attempts' => 0,
                'available_at' => $now, 'expires_at' => $expires->format('Y-m-d H:i:s'), 'created_at' => $now, 'updated_at' => $now]);
        }

        return $count;
    }

    /**
     * Maintenance: one reminder per confirmed booking that starts within the hour. The dedupe key carries the start
     * time, so a rescheduled booking is reminded for its new time and a repeated run never reminds twice.
     */
    public static function queueReminders(int $limit = 200): int
    {
        if (! self::enabled()) return 0;
        $count = 0;
        $due = DB::table('bookings as b')->join('customer_profiles as p', 'p.id', '=', 'b.customer_profile_id')->join('users as u', 'u.id', '=', 'p.user_id')->whereNull('p.deleted_at')->where('u.is_active', true)->where('b.status', 'CONFIRMED')
            ->where('b.appointment_start_at', '>', now('UTC')->format('Y-m-d H:i:s'))->where('b.appointment_start_at', '<=', now('UTC')->addHour()->format('Y-m-d H:i:s'))
            ->orderBy('b.appointment_start_at')->limit($limit)->get(['b.id', 'b.booking_code', 'b.appointment_start_at', 'p.user_id']);
        foreach ($due as $row) {
            $key = self::reminderKey((int) $row->id, $row->appointment_start_at);
            if (DB::table('notifications')->where('dedupe_key', $key)->exists()) continue;
            app(\App\Services\Booking\BookingNotificationService::class)->record((int) $row->user_id, 'BOOKING_REMINDER', 'Sắp đến giờ hẹn tại BeautyBook', 'Bạn có lịch hẹn '.$row->booking_code.' trong vòng một giờ tới. Mở chi tiết để xem địa chỉ và dịch vụ.', (int) $row->id, $key, 'INFO', ['status' => 'REMINDER']);
            $count++;
        }

        return $count;
    }

    /** Claims due deliveries, sends them and returns [sent, retrying, failed, suppressed]. */
    public function runBatch(int $limit = 5): array
    {
        $result = ['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0];
        if (! self::enabled()) return $result;
        $claims = DB::transaction(function () use ($limit) {
            $now = now('UTC');
            $rows = DB::table('push_deliveries')->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'PENDING')->where('available_at', '<=', $now))
                ->orWhere(fn ($q) => $q->where('status', 'PROCESSING')->where('locked_until', '<=', $now)))
                ->orderBy('available_at')->orderBy('id')->limit($limit)->lock('for update skip locked')->get();
            $until = $now->copy()->addMinutes(5);
            foreach ($rows as $row) DB::table('push_deliveries')->where('id', $row->id)->update(['status' => 'PROCESSING', 'attempts' => $row->attempts + 1, 'locked_until' => $until, 'updated_at' => $now]);

            return $rows->map(fn ($row) => ['id' => $row->id, 'attempts' => $row->attempts + 1, 'lockedUntil' => $until]);
        }, 3);
        foreach ($claims as $claim) {
            try {
                $result[$this->deliver($claim)]++;
            } catch (\Throwable $e) {
                $failure = $e instanceof FcmFailure ? $e : new FcmFailure('PUSH_TRANSIENT', true);
                $retry = $failure->retryable && $claim['attempts'] < config('push.max_attempts');
                DB::table('push_deliveries')->where('id', $claim['id'])->where('status', 'PROCESSING')->where('attempts', $claim['attempts'])->update([
                    'status' => $retry ? 'PENDING' : 'FAILED', 'locked_until' => null, 'last_error' => $failure->errorCode, 'updated_at' => now('UTC'),
                    'available_at' => now('UTC')->addSeconds(max($failure->retryAfterSeconds, min(3600, 60 * 2 ** min($claim['attempts'] - 1, 6)))),
                ]);
                $result[$retry ? 'retrying' : 'failed']++;
            }
        }

        return $result;
    }

    private function owned(array $claim)
    {
        return DB::table('push_deliveries')->where('id', $claim['id'])->where('status', 'PROCESSING')->where('attempts', $claim['attempts']);
    }

    /** @return 'sent'|'suppressed' */
    private function deliver(array $claim): string
    {
        $job = $this->owned($claim)->first();
        if (! $job) return 'suppressed';
        $device = DB::table('beautybook_device_tokens')->find($job->device_id);
        $notification = DB::table('notifications')->find($job->notification_id);
        $user = $notification ? DB::table('users')->where('id', $notification->user_id)->first() : null;
        $booking = $notification?->related_booking_id ? DB::table('bookings as b')->join('customer_profiles as p', 'p.id', '=', 'b.customer_profile_id')->where('b.id', $notification->related_booking_id)
            ->first(['b.id', 'b.status', 'b.appointment_start_at', 'p.user_id as customer_user_id', 'p.deleted_at as customer_deleted_at']) : null;
        // The recipient is whoever owns the notification and the booking; the device must still belong to that account
        // and to the very session it was bound to when this push was queued (push.worker.ts), and that session must be alive.
        $valid = $device && $notification && $user && $booking && $device->active && (int) $device->user_id === (int) $notification->user_id && $user->is_active && self::customerAccess((int) $notification->user_id)
            && ! $booking->customer_deleted_at && (int) $booking->customer_user_id === (int) $notification->user_id
            && $device->session_id_hash && hash_equals((string) $device->session_id_hash, (string) $job->session_id_hash) && self::sessionAlive((int) $device->user_id, $device->session_id_hash)
            && strtotime($job->expires_at.' UTC') > time()
            // A reminder is only worth sending for the booking as it stands: still confirmed, still in the future, still at the time it was queued for.
            && ($notification->type !== 'BOOKING_REMINDER' || ($booking->status === 'CONFIRMED' && strtotime($booking->appointment_start_at.' UTC') > time() && $notification->dedupe_key === self::reminderKey((int) $booking->id, $booking->appointment_start_at)));
        if (! $valid) {
            $this->owned($claim)->update(['status' => 'DELIVERED', 'locked_until' => null, 'last_error' => 'SUPPRESSED', 'updated_at' => now('UTC')]);

            return 'suppressed';
        }
        try {
            $this->fcm->send(['token' => \Illuminate\Support\Facades\Crypt::decryptString($device->token), 'notificationId' => $notification->id, 'userId' => $notification->user_id, 'sessionId' => $device->session_id_hash, 'type' => $notification->type,
                'title' => $notification->title, 'body' => $notification->body, 'ttlSeconds' => strtotime($job->expires_at.' UTC') - time()]);
        } catch (FcmFailure $e) {
            if ($e->invalidToken) DB::table('beautybook_device_tokens')->where('id', $device->id)->where('token_hash', $device->token_hash)->update(['active' => false, 'updated_at' => now()]);
            if ($e->errorCode === 'FCM_401') Cache::forget('fcm:access-token');
            throw $e;
        }
        $this->owned($claim)->update(['status' => 'DELIVERED', 'locked_until' => null, 'last_error' => null, 'sent_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return 'sent';
    }

    /** Operator "send again": failed deliveries go back to the queue with a fresh attempt budget. */
    public function requeueFailed(?int $notificationId = null): int
    {
        return DB::table('push_deliveries')->where('status', 'FAILED')->when($notificationId, fn ($q) => $q->where('notification_id', $notificationId))
            ->where('expires_at', '>', now('UTC'))->update(['status' => 'PENDING', 'attempts' => 0, 'available_at' => now('UTC'), 'locked_until' => null, 'updated_at' => now('UTC')]);
    }

    /**
     * push-devices.service.ts: one installation has one current token and one account; the device is bound to the login
     * session that registered it (only a hash of the session id is kept). Registering a token again from a new session
     * rebinds it, so what was queued for the old session is never replayed.
     */
    public function registerDevice(int $userId, string $token, string $platform, string $installationId, string $sessionId): void
    {
        $hash = hash('sha256', $token);
        DB::transaction(function () use ($userId, $token, $platform, $hash, $installationId, $sessionId) {
            $existing = DB::table('beautybook_device_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
            DB::table('beautybook_device_tokens')->where('installation_id', $installationId)->where('token_hash', '!=', $hash)->where('active', true)->update(['active' => false, 'updated_at' => now()]);
            DB::table('beautybook_device_tokens')->updateOrInsert(['token_hash' => $hash], ['user_id' => $userId, 'token' => \Illuminate\Support\Facades\Crypt::encryptString($token), 'platform' => strtoupper($platform), 'installation_id' => $installationId,
                'session_id_hash' => self::sessionHash($sessionId), 'active' => true, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
        }, 3);
    }

    /** Switches off the device of this installation (or token) that belongs to this account and this very session. */
    public function removeDevice(int $userId, string $sessionId, ?string $token, ?string $installationId): void
    {
        DB::table('beautybook_device_tokens')->where('user_id', $userId)->where('session_id_hash', self::sessionHash($sessionId))
            ->where(fn ($q) => $q->when($token, fn ($q) => $q->orWhere('token_hash', hash('sha256', $token)))->when($installationId, fn ($q) => $q->orWhere('installation_id', $installationId)))
            ->update(['active' => false, 'updated_at' => now()]);
    }

    /** The session ended (logout, revoked): its devices stop receiving pushes. */
    public static function revokeSessions(array $sessionIds): void
    {
        if ($sessionIds === []) return;
        DB::table('beautybook_device_tokens')->whereIn('session_id_hash', array_map([self::class, 'sessionHash'], $sessionIds))->where('active', true)->update(['active' => false, 'updated_at' => now()]);
    }

    /** Every session of the account ended (password change or reset, account closed): all its devices stop receiving pushes. */
    public static function revokeUser(int $userId): void
    {
        DB::table('beautybook_device_tokens')->where('user_id', $userId)->where('active', true)->update(['active' => false, 'updated_at' => now()]);
    }
}
