<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\User;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Device push over the notification outbox. FCM and Google OAuth are replaced by Http::fake; no real Firebase project is used. */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $keyFile;
    private const PROJECT = 'beautybook-test-project';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'fcm-test-');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'push-test@'.self::PROJECT.'.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['push.enabled' => true, 'push.project_id' => self::PROJECT, 'push.service_account_file' => $this->keyFile]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    public function test_a_real_booking_event_pushes_to_the_recipients_devices_only_and_failed_sends_can_be_resent(): void
    {
        $customerToken = str_repeat('c', 40);
        $ownerToken = str_repeat('o', 40);
        $strangerToken = str_repeat('s', 40);
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $customerToken, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->actor('owner');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $ownerToken, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->actor('owner2');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $strangerToken, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->actor('customer');
        $this->assertStringNotContainsString($customerToken, (string) DB::table('beautybook_device_tokens')->where('user_id', User::where('email', 'customer@beautybook.local')->value('id'))->value('token'), 'tokens are stored encrypted');

        $booking = $this->booking('PENDING');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()->push(['error' => ['status' => 'UNAVAILABLE']], 503)->push(['name' => 'projects/'.self::PROJECT.'/messages/1']),
        ]);
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'CONFIRMED'])->assertOk();

        $customerId = User::where('email', 'customer@beautybook.local')->value('id');
        $notification = DB::table('notifications')->where('user_id', $customerId)->where('type', 'BOOKING_STATUS_CHANGED')->firstOrFail();
        $deliveries = DB::table('push_deliveries')->get();
        $this->assertCount(1, $deliveries, 'one device belongs to the notified customer');
        $this->assertSame($notification->id, $deliveries[0]->notification_id);
        Http::assertNothingSent();

        // First attempt: FCM is unavailable, so the delivery stays queued with a backoff.
        $this->assertSame(['sent' => 0, 'retrying' => 1, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $row = DB::table('push_deliveries')->first();
        $this->assertSame('PENDING', $row->status);
        $this->assertSame('FCM_503', $row->last_error);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch(), 'not due yet');
        DB::table('push_deliveries')->update(['available_at' => now('UTC')->subSecond()]);

        // Second attempt succeeds, exactly once.
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame('DELIVERED', DB::table('push_deliveries')->value('status'));
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $sends = Http::recorded(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'));
        $this->assertCount(2, $sends);
        [$request] = $sends[1];
        $this->assertSame('https://fcm.googleapis.com/v1/projects/'.self::PROJECT.'/messages:send', $request->url());
        $this->assertSame('Bearer fake-access-token', $request->header('Authorization')[0]);
        $message = $request['message'];
        $this->assertSame($customerToken, $message['token'], 'only the notified customer device is addressed');
        $this->assertSame((string) $notification->id, $message['data']['notificationId']);
        $this->assertSame((string) $customerId, $message['data']['userId']);
        $this->assertSame(hash('sha256', substr(hash('sha256', 'customer'), 0, 40)), $message['data']['sessionId'], 'the push names the session it was queued for');
        $this->assertSame(['notificationId' => (string) $notification->id, 'userId' => (string) $customerId, 'sessionId' => $message['data']['sessionId']], json_decode($message['data']['body'], true));
        $this->assertSame($notification->title, $message['notification']['title']);
        $this->assertStringNotContainsString($ownerToken, json_encode($sends));
        $this->assertStringNotContainsString($strangerToken, json_encode($sends));
        $tokenCall = Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com'))[0][0];
        $this->assertStringContainsString('grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer', $tokenCall->body());

        // The same event recorded again (retry of the booking command) queues nothing new.
        $this->assertSame(0, app(PushService::class)->queue($notification->id, $customerId, 'BOOKING_STATUS_CHANGED') - 0);
        $this->assertCount(1, DB::table('push_deliveries')->get());
    }

    public function test_non_retryable_failures_are_failed_then_requeued_and_dead_tokens_are_revoked(): void
    {
        $token = str_repeat('d', 40);
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'ANDROID', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $booking = $this->booking('PENDING');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                ->push(['error' => ['status' => 'UNAVAILABLE']], 429, ['Retry-After' => '120'])
                ->push(['name' => 'ok']),
        ]);
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'CONFIRMED'])->assertOk();
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 1, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame('FAILED', DB::table('push_deliveries')->value('status'));
        $this->assertSame('UNREGISTERED', DB::table('push_deliveries')->value('last_error'));
        $this->assertSame(0, (int) DB::table('beautybook_device_tokens')->value('active'), 'a dead token is revoked');

        // After the user registers the device again, the operator can send the failed push again.
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'ANDROID', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->assertSame(1, (int) DB::table('beautybook_device_tokens')->value('active'));
        $this->assertSame(1, app(PushService::class)->requeueFailed());
        $this->assertSame(['sent' => 0, 'retrying' => 1, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertGreaterThanOrEqual(115, strtotime(DB::table('push_deliveries')->value('available_at').' UTC') - time(), 'Retry-After is honoured');
        DB::table('push_deliveries')->update(['available_at' => now('UTC')->subSecond()]);
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame('DELIVERED', DB::table('push_deliveries')->value('status'));
    }

    public function test_removed_devices_inactive_accounts_and_disabled_push_receive_nothing(): void
    {
        $token = str_repeat('r', 40);
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->deleteJson('/api/v1/notifications/device-token', ['token' => $token])->assertOk();
        $this->actor('owner');
        $this->patchJson('/api/v1/bookings/'.$this->booking('PENDING')->id.'/status', ['status' => 'CONFIRMED'])->assertOk();
        $this->assertSame(0, DB::table('push_deliveries')->count(), 'a removed device is never queued');

        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->actor('owner');
        $this->patchJson('/api/v1/bookings/'.$this->booking('PENDING')->id.'/status', ['status' => 'CONFIRMED'])->assertOk();
        $this->assertSame(1, DB::table('push_deliveries')->count());
        // The account is closed between queueing and sending: the delivery is suppressed and nothing is sent.
        User::where('email', 'customer@beautybook.local')->update(['is_active' => false]);
        Http::fake();
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 1], app(PushService::class)->runBatch());
        Http::assertNothingSent();

        config(['push.enabled' => false]);
        $this->assertSame(0, app(PushService::class)->queue((int) DB::table('notifications')->value('id'), User::where('email', 'owner@beautybook.local')->value('id'), 'BOOKING_STATUS_CHANGED'));
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
    }

    public function test_only_the_push_events_of_the_source_policy_reach_devices_and_only_the_booking_customer_receives_them(): void
    {
        $customerToken = str_repeat('p', 40);
        $ownerToken = str_repeat('q', 40);
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $customerToken, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->actor('owner');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $ownerToken, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $booking = $this->booking('CONFIRMED');
        // Chat is in-app only (not a push event): neither side's device is queued.
        $conversation = $this->postJson('/api/v1/chat/branch/conversations', ['bookingId' => $booking->id])->assertOk()->json('id');
        $this->postJson("/api/v1/chat/branch/conversations/{$conversation}/messages", ['clientMessageId' => (string) \Illuminate\Support\Str::uuid(), 'body' => 'Mời bạn đến sớm 10 phút'])->assertCreated();
        $this->actor('customer');
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/messages", ['clientMessageId' => (string) \Illuminate\Support\Str::uuid(), 'body' => 'Dạ vâng'])->assertCreated();
        $this->assertSame(0, DB::table('push_deliveries')->count());
        // Intermediate states are not pushed; a customer cancelling notifies staff in-app but only the customer's own device is pushed.
        $this->actor('owner');
        $other = $this->booking('PENDING');
        $this->patchJson("/api/v1/bookings/{$other->id}/status", ['status' => 'REJECTED'])->assertOk();
        $this->assertSame(['BOOKING_STATUS_CHANGED'], DB::table('notifications')->join('push_deliveries', 'push_deliveries.notification_id', '=', 'notifications.id')->pluck('notifications.type')->all());
        $this->assertSame([DB::table('beautybook_device_tokens')->where('user_id', User::where('email', 'customer@beautybook.local')->value('id'))->value('id')], DB::table('push_deliveries')->pluck('device_id')->map(fn ($v) => (int) $v)->all());
        $this->actor('customer');
        $this->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'CANCELLED'])->assertOk();
        $this->assertSame(2, DB::table('push_deliveries')->count(), 'REJECTED and CANCELLED reach the customer device; the staff notice about the cancellation does not');
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);
        $this->assertSame(2, app(PushService::class)->runBatch()['sent']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com') && $request['message']['token'] === $customerToken);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com') && $request['message']['token'] === $ownerToken);
    }

    public function test_a_reminder_is_queued_once_for_a_confirmed_booking_starting_within_the_hour_and_expires_at_its_start(): void
    {
        $token = str_repeat('r', 39).'x';
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'android', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $soon = $this->booking('CONFIRMED');
        $soon->update(['appointment_start_at' => now('UTC')->addMinutes(40), 'appointment_end_at' => now('UTC')->addMinutes(100)]);
        $later = $this->booking('CONFIRMED');
        $pending = $this->booking('PENDING');
        $pending->update(['appointment_start_at' => now('UTC')->addMinutes(30), 'appointment_end_at' => now('UTC')->addMinutes(90)]);
        $started = $this->booking('CONFIRMED');
        $started->update(['appointment_start_at' => now('UTC')->subMinutes(5), 'appointment_end_at' => now('UTC')->addMinutes(55)]);

        $this->assertSame(1, PushService::queueReminders());
        $this->assertSame(0, PushService::queueReminders(), 'a second run never reminds twice');
        $delivery = DB::table('push_deliveries')->first();
        $this->assertSame(1, DB::table('push_deliveries')->count());
        $this->assertSame('BOOKING_REMINDER', DB::table('notifications')->where('id', $delivery->notification_id)->value('type'));
        $this->assertEqualsWithDelta(strtotime($soon->fresh()->getRawOriginal('appointment_start_at').' UTC'), strtotime($delivery->expires_at.' UTC'), 1, 'a reminder is worthless after the appointment starts');
        // Rescheduled bookings get a reminder for the new time.
        $soon->update(['appointment_start_at' => now('UTC')->addMinutes(20), 'appointment_end_at' => now('UTC')->addMinutes(80)]);
        $this->assertSame(1, PushService::queueReminders());
        config(['push.enabled' => false]);
        $this->assertSame(0, PushService::queueReminders());
    }

    public function test_the_trial_fcm_stand_in_does_not_exist_unless_it_is_explicitly_enabled(): void
    {
        $this->post('/api/v1/push-trial/oauth/token', ['grant_type' => 'x', 'assertion' => 'x'], ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson('/api/v1/push-trial/projects/'.self::PROJECT.'/messages:send', ['message' => []])->assertNotFound();
        $this->actor('customer');
        $this->getJson('/api/v1/notifications/push-config')->assertOk()->assertJsonPath('mode', 'off')->assertJsonPath('enabled', false)->assertJsonPath('reason', 'BROWSER_FCM_NOT_CONFIGURED');
        $this->getJson('/api/v1/notifications/push-trial/messages?token='.str_repeat('a', 20))->assertNotFound();
        config(['push.enabled' => false]);
        $this->getJson('/api/v1/notifications/push-config')->assertOk()->assertJsonPath('reason', 'PUSH_DISABLED');
    }

    public function test_a_browser_gets_the_firebase_web_settings_only_when_push_is_on_and_all_of_them_are_set(): void
    {
        $this->actor('customer');
        $web = ['api_key' => 'AIza-test-public-key', 'app_id' => '1:123456789:web:abc', 'sender_id' => '123456789', 'vapid_key' => 'B-test-vapid-public-key'];

        // Partly configured: still off, and nothing of the Firebase settings is exposed.
        config(['push.web' => ['api_key' => 'AIza-test-public-key'] + $web, 'push.web.vapid_key' => null]);
        $this->getJson('/api/v1/notifications/push-config')->assertOk()->assertJsonPath('mode', 'off')->assertJsonPath('reason', 'BROWSER_FCM_NOT_CONFIGURED')->assertJsonMissingPath('firebase');

        config(['push.web' => $web]);
        $config = $this->getJson('/api/v1/notifications/push-config')->assertOk()->assertJsonPath('mode', 'fcm')->assertJsonPath('enabled', true)->assertJsonPath('reason', null)->json();
        $this->assertSame(['apiKey' => 'AIza-test-public-key', 'projectId' => self::PROJECT, 'messagingSenderId' => '123456789', 'appId' => '1:123456789:web:abc', 'vapidKey' => 'B-test-vapid-public-key'], $config['firebase']);
        // Only public web settings travel: never the service account or its private key.
        $this->assertStringNotContainsString('private_key', json_encode($config));
        $this->assertStringNotContainsString((string) config('push.service_account_file'), json_encode($config));

        config(['push.enabled' => false]);
        $this->getJson('/api/v1/notifications/push-config')->assertOk()->assertJsonPath('mode', 'off')->assertJsonPath('reason', 'PUSH_DISABLED')->assertJsonMissingPath('firebase');
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession([
            'workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id,
            'beautybook_access_hash' => hash('sha256', 'push-fixture'), 'beautybook_access_expires' => time() + 900,
        ])->withHeader('Authorization', 'Bearer push-fixture')->withCredentials()->withCookie(config('session.cookie'), substr(hash('sha256', $name), 0, 40));

        return $user;
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
