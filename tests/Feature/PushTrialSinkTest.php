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

/**
 * Register -> receive -> remove push on a trial browser. The real FcmClient signs its service-account JWT and calls the
 * trial OAuth/FCM stand-ins (forwarded in-process here). Verification level: protocol-faithful fake, not Google FCM.
 */
class PushTrialSinkTest extends TestCase
{
    use RefreshDatabase;

    private string $keyFile;
    private const PROJECT = 'beautybook-trial-project';
    private const TOKEN_URL = 'http://localhost/api/v1/push-trial/oauth/token';

    protected function setUp(): void
    {
        // Routes are registered at boot, so the sink flag must be in the environment before the application starts.
        foreach (['PUSH_TRIAL_SINK' => 'true'] as $name => $value) { putenv("$name=$value"); $_ENV[$name] = $value; $_SERVER[$name] = $value; }
        parent::setUp();
        $this->seed();
        // The preview keeps the trial inbox in the database cache, which serialises values: use it so a non-array payload would show up.
        config(['cache.default' => 'database']);
        Cache::flush();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'fcm-trial-');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'push-trial@'.self::PROJECT.'.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['push.enabled' => true, 'push.project_id' => self::PROJECT, 'push.service_account_file' => $this->keyFile,
            'push.token_url' => self::TOKEN_URL, 'push.send_url' => 'http://localhost/api/v1/push-trial/projects/%s/messages:send']);
        // Route every outgoing FCM/OAuth call to the in-app trial endpoints.
        Http::fake(fn (Request $request) => $this->forward($request));
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        foreach (['PUSH_TRIAL_SINK'] as $name) { putenv($name); unset($_ENV[$name], $_SERVER[$name]); }
        parent::tearDown();
    }

    public function test_a_trial_browser_registers_receives_a_real_booking_push_and_removes_the_device(): void
    {
        $token = 'trial-'.bin2hex(random_bytes(16));
        $this->actor('customer');
        $this->getJson('/api/v1/notifications/push-config')->assertOk()->assertJsonPath('mode', 'trial')->assertJsonPath('enabled', true);
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'WEB', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $this->getJson('/api/v1/notifications/push-trial/messages?token='.$token)->assertOk()->assertExactJson(['items' => []]);

        $booking = $this->booking('PENDING');
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'CONFIRMED'])->assertOk();
        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame('DELIVERED', DB::table('push_deliveries')->value('status'));

        // The owner (a different account) can neither see nor drain the customer's device inbox.
        $this->getJson('/api/v1/notifications/push-trial/messages?token='.$token)->assertNotFound();
        $this->actor('customer');
        $received = $this->getJson('/api/v1/notifications/push-trial/messages?token='.$token)->assertOk()->json('items');
        $this->assertCount(1, $received);
        $notification = DB::table('notifications')->where('user_id', User::where('email', 'customer@beautybook.local')->value('id'))->where('type', 'BOOKING_STATUS_CHANGED')->firstOrFail();
        $this->assertSame($notification->title, $received[0]['title']);
        $this->assertSame('BOOKING_STATUS_CHANGED', $received[0]['data']['type']);
        $this->assertSame((string) $notification->id, $received[0]['data']['notificationId']);
        // Reading consumes the inbox, and a rerun of the worker does not send the same push again.
        $this->getJson('/api/v1/notifications/push-trial/messages?token='.$token)->assertOk()->assertExactJson(['items' => []]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());

        // Removing the device stops delivery: the next event queues nothing and the inbox is gone.
        $this->deleteJson('/api/v1/notifications/device-token', ['token' => $token])->assertOk();
        $this->getJson('/api/v1/notifications/push-trial/messages?token='.$token)->assertNotFound();
        $next = $this->booking('PENDING');
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/{$next->id}/status", ['status' => 'CONFIRMED'])->assertOk();
        $this->assertSame(1, DB::table('push_deliveries')->count());
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0], app(PushService::class)->runBatch());
    }

    public function test_the_trial_endpoints_check_the_service_account_signature_the_bearer_token_and_the_device(): void
    {
        $json = fn (string $assertion, string $grant = 'urn:ietf:params:oauth:grant-type:jwt-bearer') => $this->post('/api/v1/push-trial/oauth/token', ['grant_type' => $grant, 'assertion' => $assertion], ['Accept' => 'application/json']);
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $account = json_decode((string) file_get_contents($this->keyFile), true);
        $sign = function (array $claims, ?string $pem = null) use ($b64, $account) {
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));
            openssl_sign($unsigned, $signature, $pem ?? $account['private_key'], OPENSSL_ALGO_SHA256);

            return $unsigned.'.'.$b64($signature);
        };
        $claims = ['iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => self::TOKEN_URL, 'iat' => time(), 'exp' => time() + 3600];
        $other = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($other, $otherPem);

        $json($sign($claims), 'password')->assertStatus(400);
        $json('not.a.jwt')->assertStatus(400);
        $json($sign($claims, $otherPem))->assertStatus(400);
        $json($sign(['exp' => time() - 10] + $claims))->assertStatus(400);
        $json($sign(['aud' => 'https://evil.example/token'] + $claims))->assertStatus(400);
        $access = $json($sign($claims))->assertOk()->json('access_token');

        $send = fn (?string $bearer, array $message, string $project = self::PROJECT) => $this->postJson("/api/v1/push-trial/projects/{$project}/messages:send", ['message' => $message], $bearer ? ['Authorization' => 'Bearer '.$bearer] : []);
        $message = ['token' => 'trial-unknown', 'notification' => ['title' => 'x', 'body' => 'y']];
        $send(null, $message)->assertUnauthorized();
        $send('forged', $message)->assertUnauthorized();
        $send($access, $message, 'another-project')->assertNotFound();
        $send($access, ['token' => '', 'notification' => []])->assertStatus(400);
        // Unknown or removed devices answer exactly like FCM, so the real client revokes them.
        $unknown = $send($access, $message)->assertNotFound();
        $this->assertSame('UNREGISTERED', $unknown->json('error.details.0.errorCode'));
    }

    public function test_an_unregistered_device_fails_the_delivery_and_is_revoked_like_real_fcm(): void
    {
        $token = 'trial-'.bin2hex(random_bytes(16));
        $this->actor('customer');
        $this->postJson('/api/v1/notifications/device-token', ['token' => $token, 'platform' => 'WEB', 'installationId' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        $booking = $this->booking('PENDING');
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'CONFIRMED'])->assertOk();
        // The browser lost its subscription between queueing and sending: its stored hash no longer resolves.
        DB::table('beautybook_device_tokens')->update(['token_hash' => hash('sha256', 'something-else')]);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 1, 'suppressed' => 0], app(PushService::class)->runBatch());
        $this->assertSame('UNREGISTERED', DB::table('push_deliveries')->value('last_error'));
        $this->assertSame(0, (int) DB::table('beautybook_device_tokens')->value('active'));
    }

    /** Executes a recorded outgoing request against this application and returns its reply as the HTTP client would see it. */
    private function forward(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $headers = ['Accept' => 'application/json'] + (($auth = $request->header('Authorization')[0] ?? null) ? ['Authorization' => $auth] : []);
        $response = $request->isForm() ? $this->post($path, $request->data(), $headers) : $this->postJson($path, $request->data(), $headers);

        return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all() ? array_map(fn ($v) => $v[0], $response->headers->all()) : []);
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
            'booking_code' => 'TRIAL-'.bin2hex(random_bytes(4)), 'status' => $status, 'appointment_start_at' => $start, 'appointment_end_at' => $end,
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
