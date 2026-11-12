<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\User;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * push-devices.service.ts / push-queue.ts / push.worker.ts: a device belongs to one installation and one login session,
 * every delivery carries the session that was current when it was queued, and nothing is sent once that session is gone.
 * The suite runs on the database session driver (as deployed), with a fixed session cookie per simulated browser.
 */
class PushSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'beautybook-test-project';
    private const SESSION_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SESSION_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SESSION_OWNER = 'cccccccccccccccccccccccccccccccccccccccc';
    private const URL = '/api/v1/notifications/device-token';

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        $this->seed();
        Cache::flush();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'fcm-test-');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'push-test@'.self::PROJECT.'.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['push.enabled' => true, 'push.project_id' => self::PROJECT, 'push.service_account_file' => $this->keyFile]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/'.self::PROJECT.'/messages/1']),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    public function test_a_device_needs_an_installation_and_is_bound_to_the_session_that_registered_it(): void
    {
        $token = str_repeat('c', 40);
        $installation = (string) Str::uuid();
        $this->browser('customer', self::SESSION_A);
        $this->postJson(self::URL, ['token' => $token, 'platform' => 'android'])->assertUnprocessable();
        $this->postJson(self::URL, ['token' => $token, 'platform' => 'android', 'installationId' => 'not-a-uuid'])->assertUnprocessable();
        $this->postJson(self::URL, ['token' => 'short', 'platform' => 'android', 'installationId' => $installation])->assertUnprocessable();
        $this->postJson(self::URL, ['token' => str_repeat('!', 40), 'platform' => 'android', 'installationId' => $installation])->assertUnprocessable();
        $this->assertSame(0, DB::table('beautybook_device_tokens')->count());

        $this->postJson(self::URL, ['token' => $token, 'platform' => 'android', 'installationId' => $installation])->assertOk();
        $device = DB::table('beautybook_device_tokens')->first();
        $this->assertSame($installation, $device->installation_id);
        $this->assertSame(hash('sha256', self::SESSION_A), $device->session_id_hash);
        $this->assertStringNotContainsString(self::SESSION_A, json_encode($device), 'the raw session id is a credential and is never stored with the device');
        $this->assertStringNotContainsString($token, (string) $device->token, 'the token is stored encrypted');
    }

    public function test_one_installation_has_one_current_token(): void
    {
        $installation = (string) Str::uuid();
        $first = str_repeat('1', 40);
        $second = str_repeat('2', 40);
        $other = str_repeat('3', 40);
        $this->browser('customer', self::SESSION_A);
        $this->register($first, $installation);
        $this->register($other, (string) Str::uuid());
        $this->register($second, $installation);
        $this->assertSame([0, 1, 1], [$this->active($first), $this->active($second), $this->active($other)], 'a refreshed token replaces the old one of the same installation only');
        $this->register($first, $installation);
        $this->assertSame([1, 0], [$this->active($first), $this->active($second)]);
    }

    public function test_a_new_login_rebinds_the_device_and_pushes_queued_for_the_old_session_are_never_replayed(): void
    {
        $token = str_repeat('c', 40);
        $installation = (string) Str::uuid();
        $this->browser('customer', self::SESSION_A);
        $this->register($token, $installation);
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(hash('sha256', self::SESSION_A), DB::table('push_deliveries')->value('session_id_hash'), 'a delivery carries the session of its device when it was queued');

        // The customer signs in again (another session) and the app registers the same installation and token.
        $this->browser('customer', self::SESSION_B);
        $this->register($token, $installation);
        $this->assertSame(hash('sha256', self::SESSION_B), DB::table('beautybook_device_tokens')->value('session_id_hash'));
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch());
        Http::assertNothingSent();

        $this->confirm($this->booking('PENDING'));
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame(1, $this->fcmSends());
    }

    public function test_nothing_is_queued_or_sent_for_a_session_that_ended_or_expired(): void
    {
        $token = str_repeat('c', 40);
        $this->browser('customer', self::SESSION_A);
        $this->register($token, (string) Str::uuid());
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count());

        // The session expires between queueing and sending.
        DB::table('sessions')->where('id', self::SESSION_A)->update(['last_activity' => time() - config('session.lifetime') * 60 - 5]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch());
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count(), 'an expired session is not queued for');

        // A session that was revoked elsewhere (its row is gone) is treated the same way.
        DB::table('sessions')->where('id', self::SESSION_A)->update(['last_activity' => time()]);
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(2, DB::table('push_deliveries')->count());
        DB::table('sessions')->where('id', self::SESSION_A)->delete();
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch());
        Http::assertNothingSent();

        // A session of another account never keeps this account's device alive.
        $this->liveSession(self::SESSION_A, User::where('email', 'owner@beautybook.local')->value('id'));
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(2, DB::table('push_deliveries')->count());
    }

    public function test_logging_out_or_revoking_sessions_deactivates_the_devices_of_those_sessions_only(): void
    {
        $a = str_repeat('a', 40);
        $b = str_repeat('b', 40);
        $this->browser('customer', self::SESSION_A);
        $this->register($a, (string) Str::uuid());
        $this->browser('customer', self::SESSION_B);
        $this->register($b, (string) Str::uuid());
        $this->liveSession(self::SESSION_A, User::where('email', 'customer@beautybook.local')->value('id'));

        $this->browser('customer', self::SESSION_A);
        $this->deleteJson('/api/v1/auth/sessions')->assertOk();
        $this->assertSame([1, 0], [$this->active($a), $this->active($b)], 'revoking the other sessions switches off their devices');

        $this->browser('customer', self::SESSION_B);
        $this->register($b, DB::table('beautybook_device_tokens')->where('token_hash', hash('sha256', $b))->value('installation_id'));
        $this->assertSame([1, 1], [$this->active($a), $this->active($b)]);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame([1, 0], [$this->active($a), $this->active($b)], 'logout switches off the device of that session only');
    }

    public function test_changing_the_password_revokes_every_device_of_the_account(): void
    {
        $a = str_repeat('a', 40);
        $b = str_repeat('b', 40);
        $this->browser('customer', self::SESSION_A);
        $this->register($a, (string) Str::uuid());
        $this->browser('customer', self::SESSION_B);
        $this->register($b, (string) Str::uuid());
        $this->postJson('/api/v1/auth/change-password', ['currentPassword' => 'BeautyBook123!', 'newPassword' => 'Another-Password-456'])->assertOk();
        $this->assertSame([0, 0], [$this->active($a), $this->active($b)]);
    }

    public function test_removing_a_device_works_by_installation_or_token_and_only_from_the_session_that_owns_it(): void
    {
        $token = str_repeat('c', 40);
        $installation = (string) Str::uuid();
        $this->browser('customer', self::SESSION_A);
        $this->register($token, $installation);

        // Another session of the same account, another account, and an unknown installation change nothing.
        $this->browser('customer', self::SESSION_B);
        $this->deleteJson(self::URL, ['installationId' => $installation])->assertOk();
        $this->assertSame(1, $this->active($token));
        $this->browser('owner', self::SESSION_OWNER);
        $this->deleteJson(self::URL, ['installationId' => $installation])->assertOk();
        $this->deleteJson(self::URL, ['token' => $token])->assertOk();
        $this->assertSame(1, $this->active($token));
        $this->browser('customer', self::SESSION_A);
        $this->deleteJson(self::URL, ['installationId' => (string) Str::uuid()])->assertOk();
        $this->assertSame(1, $this->active($token));
        $this->deleteJson(self::URL, [])->assertUnprocessable();

        $this->deleteJson(self::URL, ['installationId' => $installation])->assertOk();
        $this->assertSame(0, $this->active($token));
        $this->register($token, $installation);
        $this->deleteJson(self::URL, ['token' => $token])->assertOk();
        $this->assertSame(0, $this->active($token));
    }

    public function test_a_device_handed_to_another_account_is_reassigned_and_the_former_owner_gets_nothing_more(): void
    {
        $token = str_repeat('c', 40);
        $installation = (string) Str::uuid();
        $this->browser('customer', self::SESSION_A);
        $this->register($token, $installation);
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count());

        $this->browser('owner', self::SESSION_OWNER);
        $this->register($token, $installation);
        $device = DB::table('beautybook_device_tokens')->first();
        $this->assertSame([User::where('email', 'owner@beautybook.local')->value('id'), hash('sha256', self::SESSION_OWNER)], [(int) $device->user_id, $device->session_id_hash]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch(), 'what was queued for the customer is not delivered to the new holder');
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count(), 'and the customer has no device left to queue for');
        Http::assertNothingSent();
    }

    public function test_a_reminder_is_re_checked_against_the_booking_and_the_customer_when_it_is_sent(): void
    {
        $token = str_repeat('c', 40);
        $this->browser('customer', self::SESSION_A);
        $this->register($token, (string) Str::uuid());
        $soon = fn () => tap($this->booking('CONFIRMED'), fn (Booking $b) => $b->update(['appointment_start_at' => now('UTC')->addMinutes(40), 'appointment_end_at' => now('UTC')->addMinutes(100)]));

        $cancelled = $soon();
        $this->assertSame(1, PushService::queueReminders());
        $cancelled->update(['status' => 'CANCELLED']);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch(), 'a cancelled booking is not reminded');

        $moved = $soon();
        $this->assertSame(1, PushService::queueReminders());
        $moved->update(['appointment_start_at' => now('UTC')->addMinutes(55), 'appointment_end_at' => now('UTC')->addMinutes(115)]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch(), 'a reminder for the old time is not sent after a reschedule');
        $moved->update(['status' => 'CANCELLED']);

        $closed = $soon();
        $this->assertSame(1, PushService::queueReminders());
        $other = $soon();
        $closed->customerProfile->delete();
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch(), 'a deleted customer profile receives nothing');
        $this->assertSame(0, PushService::queueReminders(), 'and is not reminded of its other bookings either');
        $this->assertNotNull($other->id);
        Http::assertNothingSent();
    }

    public function test_a_push_needs_a_customer_role_that_is_still_valid_when_it_is_queued_and_when_it_is_sent(): void
    {
        $token = str_repeat('c', 40);
        $customer = $this->browser('customer', self::SESSION_A);
        $this->register($token, (string) Str::uuid());
        $role = fn () => DB::table('role_assignments')->where('user_id', $customer->id)->where('role_name', 'CUSTOMER');

        // The customer access expires between queueing and sending (the session and the account are fine): nothing is sent.
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count());
        $role()->update(['expires_at' => now('UTC')->subMinute()]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch(), 'an expired customer role is not pushed to');
        Http::assertNothingSent();

        // While it stays expired nothing new is queued either.
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count());

        // A role that has not expired yet (or never expires) is pushed to, and expiring after the queue still suppresses.
        $role()->update(['expires_at' => now('UTC')->addDay()]);
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(2, DB::table('push_deliveries')->count());
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame(1, $this->fcmSends());
        $role()->update(['expires_at' => null]);
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
    }

    public function test_without_the_database_session_driver_a_device_is_valid_for_one_session_lifetime_after_its_last_registration(): void
    {
        // Only the database driver can be asked "is this session still alive?". For any other driver the device is trusted for as
        // long as a session lasts counted from its last registration (the app registers again at every start), never forever.
        config(['session.driver' => 'array', 'session.lifetime' => 120]);
        $token = str_repeat('c', 40);
        $this->browser('customer', self::SESSION_A);
        $this->register($token, (string) Str::uuid());
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count());
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());

        DB::table('beautybook_device_tokens')->update(['updated_at' => now()->subMinutes(121)]);
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(1, DB::table('push_deliveries')->count(), 'a device not seen for a whole session lifetime is not queued for');

        // It is trusted again as soon as the app registers it again; what was queued while it was stale is suppressed.
        $this->browser('customer', self::SESSION_A);
        $this->register($token, (string) DB::table('beautybook_device_tokens')->value('installation_id'));
        $this->confirm($this->booking('PENDING'));
        $this->assertSame(2, DB::table('push_deliveries')->count());
        DB::table('beautybook_device_tokens')->update(['updated_at' => now()->subMinutes(121)]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch());
    }

    private function register(string $token, string $installation)
    {
        return $this->postJson(self::URL, ['token' => $token, 'platform' => 'android', 'installationId' => $installation])->assertOk();
    }

    private function active(string $token): int
    {
        return (int) DB::table('beautybook_device_tokens')->where('token_hash', hash('sha256', $token))->value('active');
    }

    private function fcmSends(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')));
    }

    private function liveSession(string $id, int $userId): void
    {
        DB::table('sessions')->updateOrInsert(['id' => $id], ['user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
    }

    /** Acts as one browser: a fixed session cookie, so the registration and later requests share a real session id. */
    private function browser(string $name, string $sessionId): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        // The session handler keeps its "row exists" flag between requests of one test process, so a second session id would
        // never get its row inserted by the request itself: the row a real login would have created is made here.
        $this->liveSession($sessionId, $user->id);
        $this->actingAs($user)->withSession([
            'workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id,
            'beautybook_access_hash' => hash('sha256', 'push-fixture'), 'beautybook_access_expires' => time() + 900,
        ])->withHeader('Authorization', 'Bearer push-fixture')->withCredentials()->withCookie(config('session.cookie'), $sessionId);

        return $user;
    }

    private function confirm(Booking $booking): void
    {
        $this->browser('owner', self::SESSION_OWNER);
        $this->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'CONFIRMED'])->assertOk();
    }

    private function booking(string $status): Booking
    {
        $offering = BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))->orderBy('id')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = now('UTC')->addDay()->addMinutes(random_int(1, 500));
        $end = $start->copy()->addHour();
        $booking = Booking::create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'PUSH-'.bin2hex(random_bytes(4)), 'status' => $status, 'appointment_start_at' => $start, 'appointment_end_at' => $end,
            'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000,
        ]);
        $booking->services()->create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
            'price_at_booking' => 100000, 'currency' => 'VND', 'duration_minutes' => 60, 'service_name_snapshot' => 'Fixture service',
            'item_start_at' => $start, 'item_end_at' => $end, 'occupied_start_at' => $start, 'occupied_end_at' => $end,
        ]);

        return $booking;
    }
}
