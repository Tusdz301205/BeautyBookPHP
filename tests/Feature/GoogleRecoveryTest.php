<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Google sign-in verification and email-code recovery. Google is simulated: the ID tokens are signed with a locally
 * generated RSA key whose public half is served as the JWKS, so signature, audience, issuer, expiry and nonce checks
 * run for real, but no external Google account is involved.
 */
class GoogleRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = 'test-client.apps.googleusercontent.com';
    private const GOOGLE = '/api/v1/auth/google';
    private $key;
    private string $nonce;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
        // Route throttles are exercised by the platform; these tests send many requests from one address.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['services.google.client_id' => self::CLIENT]);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->nonce = bin2hex(random_bytes(32));
        $details = openssl_pkey_get_details($this->key)['rsa'];
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        Http::fake(['www.googleapis.com/*' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($details['n']), 'e' => $b64($details['e'])]]])]);
    }

    // ---- Google ---------------------------------------------------------------------------------------------

    public function test_config_issues_a_session_bound_nonce_and_google_signin_creates_one_account_per_subject(): void
    {
        $config = $this->getJson('/api/v1/auth/google/config')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('clientId', self::CLIENT)->json();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $config['nonce']);
        $this->assertSame($config['nonce'], session('google_nonce'));
        config(['services.google.client_id' => null]);
        $this->getJson('/api/v1/auth/google/config')->assertOk()->assertExactJson(['enabled' => false]);
        config(['services.google.client_id' => self::CLIENT]);

        $email = 'khach.google@gmail.com';
        $response = $this->google($this->token(['sub' => 'sub-1', 'email' => $email, 'name' => 'Khách Google']), 'CUSTOMER')->assertOk();
        $response->assertJsonPath('user.email', $email);
        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame(AccountKind::Customer, $user->account_kind);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->customerProfile);
        $this->assertDatabaseHas('external_identities', ['user_id' => $user->id, 'provider' => 'GOOGLE', 'subject' => 'sub-1']);
        $this->assertSame($user->id, auth()->id());
        $this->assertNull(session('google_nonce'), 'the nonce is single use');

        // The same Google subject always lands on the same account; asking for another account type changes nothing.
        $this->google($this->token(['sub' => 'sub-1', 'email' => $email]), 'BUSINESS_OWNER')->assertOk()->assertJsonPath('user.id', (string) $user->id);
        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertSame(1, DB::table('external_identities')->where('subject', 'sub-1')->count());
        $this->assertSame(0, DB::table('role_assignments')->where('user_id', $user->id)->where('role_name', 'BUSINESS_OWNER')->count());

        // A new owner account gets a private draft business, never an administrator role.
        $this->google($this->token(['sub' => 'sub-2', 'email' => 'chu.google@gmail.com']), 'BUSINESS_OWNER')->assertOk()->assertJsonPath('user.sessionType', 'salon');
        $owner = User::where('email', 'chu.google@gmail.com')->firstOrFail();
        $this->assertSame(['BUSINESS_OWNER'], DB::table('role_assignments')->where('user_id', $owner->id)->pluck('role_name')->all());
    }

    public function test_an_existing_account_links_only_when_google_is_authoritative_for_that_address(): void
    {
        $plain = $this->password('mat.khau@example.com');
        $this->google($this->token(['sub' => 'sub-x', 'email' => 'mat.khau@example.com']))->assertStatus(409);
        $this->assertDatabaseMissing('external_identities', ['user_id' => $plain->id]);
        $this->assertFalse(auth()->check());

        // Workspace domain: Google asserts hd, so it is authoritative and the existing account is linked, not duplicated.
        $this->google($this->token(['sub' => 'sub-hd', 'email' => 'mat.khau@example.com', 'hd' => 'example.com']))->assertOk()->assertJsonPath('user.id', (string) $plain->id);
        $this->assertSame(1, User::where('email', 'mat.khau@example.com')->count());
        // Another Google identity cannot take over an account that is already linked.
        $this->google($this->token(['sub' => 'sub-other', 'email' => 'mat.khau@example.com', 'hd' => 'example.com']))->assertStatus(409);

        $gmail = $this->password('co.san@gmail.com');
        $this->google($this->token(['sub' => 'sub-g', 'email' => 'co.san@gmail.com']))->assertOk()->assertJsonPath('user.id', (string) $gmail->id);
        $this->assertDatabaseHas('external_identities', ['user_id' => $gmail->id, 'subject' => 'sub-g']);
    }

    public function test_forged_expired_misdirected_and_unverified_tokens_and_privileged_accounts_are_refused(): void
    {
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-1'], nonce: str_repeat('a', 64)))->status(), 'nonce does not match this session');
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-2'], expiresIn: -3600))->status(), 'expired');
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-3', 'aud' => 'someone-else.apps.googleusercontent.com']))->status(), 'wrong audience');
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-4', 'iss' => 'https://evil.example']))->status(), 'wrong issuer');
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-5', 'email_verified' => false]))->status(), 'unverified email');
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-6'], signWith: openssl_pkey_new(['private_key_bits' => 2048])))->status(), 'signed by another key');
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-7'], kid: 'unknown-key'))->status(), 'unknown key id');
        $this->assertSame(401, $this->google('not.a.jwt')->status());
        $this->assertSame(401, $this->google($this->token(['sub' => 'bad-8'], alg: 'none'))->status(), 'alg none');
        $this->postJson(self::GOOGLE, [])->assertUnprocessable();
        $this->assertSame(0, DB::table('external_identities')->count());
        $this->assertFalse(auth()->check());

        // Locked accounts and platform administrators cannot use Google at all.
        $locked = $this->password('khoa@gmail.com');
        $locked->forceFill(['is_active' => false])->save();
        $this->google($this->token(['sub' => 'locked', 'email' => 'khoa@gmail.com']))->assertForbidden();
        $admin = User::forceCreate(['name' => 'Admin Gmail', 'email' => 'quantri@gmail.com', 'password' => Hash::make('Passw0rd!x'), 'account_kind' => AccountKind::Operational, 'is_active' => true, 'email_verified_at' => now()]);
        app(RoleAssignmentService::class)->grant($admin, RoleName::PlatformAdmin);
        $this->google($this->token(['sub' => 'admin', 'email' => 'quantri@gmail.com']))->assertForbidden();
        $this->assertSame(0, DB::table('external_identities')->count());

        config(['services.google.client_id' => '']);
        $this->google($this->token(['sub' => 'off']))->assertStatus(503);
    }

    // ---- recovery by emailed code ------------------------------------------------------------------------------

    public function test_a_recovery_code_resets_the_password_once_and_revokes_sessions_and_old_links(): void
    {
        $user = $this->password('khoi.phuc@example.com');
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'khong.co@example.com'])->assertStatus(202)->json();
        $known = $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'Khoi.Phuc@Example.com'])->assertStatus(202)->json();
        $this->assertSame(array_keys($unknown), array_keys($known), 'known and unknown addresses are indistinguishable');
        $this->assertSame(['accepted' => true, 'expiresIn' => 600, 'resendAfter' => 60], array_diff_key($known, ['requestId' => 1]));
        $mails = $this->mails();
        $this->assertCount(1, $mails, 'only a real account is mailed');
        $this->assertSame('khoi.phuc@example.com', $mails[0]['to']);
        $code = $mails[0]['code'];
        $stored = DB::table('password_reset_codes')->where('id', $known['requestId'])->first();
        $this->assertStringNotContainsString($code, json_encode($stored), 'only an HMAC of the code is stored');

        // A resend inside 60 seconds returns the same challenge and sends nothing.
        $this->assertSame($known['requestId'], $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'khoi.phuc@example.com'])->json('requestId'));
        $this->assertCount(1, $this->mails());

        $wrong = $code === '000000' ? '111111' : '000000';
        $new = 'MatKhauMoi456';
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $known['requestId'], 'code' => $wrong, 'newPassword' => $new])->assertStatus(400);
        $this->assertSame(1, (int) DB::table('password_reset_codes')->where('id', $known['requestId'])->value('attempts'));
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $known['requestId'], 'code' => $code, 'newPassword' => 'weak'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $known['requestId'], 'code' => 'abcdef', 'newPassword' => $new])->assertUnprocessable();

        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $known['requestId'], 'code' => $code, 'newPassword' => $new])->assertOk();
        $user->refresh();
        $this->assertTrue(Hash::check($new, $user->password));
        $this->assertFalse(Hash::check('Original123', $user->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(), 'old sessions are revoked');
        $this->assertSame(1, DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'AUTH_PASSWORD_RESET')->count());
        // A used code can never reset again, even with the right value.
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $known['requestId'], 'code' => $code, 'newPassword' => 'KhacHan789x'])->assertStatus(400);
        $this->assertTrue(Hash::check($new, $user->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => (string) Str::uuid(), 'code' => $code, 'newPassword' => 'KhacHan789x'])->assertStatus(400);
    }

    public function test_expired_exhausted_superseded_and_rate_limited_codes_are_rejected(): void
    {
        $user = $this->password('han.dung@example.com');
        $request = fn () => $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'han.dung@example.com'])->assertStatus(202)->json('requestId');
        $age = fn (string $id, int $seconds) => DB::table('password_reset_codes')->where('id', $id)->update(['created_at' => now('UTC')->subSeconds($seconds)]);

        $first = $request();
        $firstCode = $this->mails()[0]['code'];
        DB::table('password_reset_codes')->where('id', $first)->update(['expires_at' => now('UTC')->subSecond()]);
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $first, 'code' => $firstCode, 'newPassword' => 'MatKhauMoi456'])->assertStatus(400);
        $this->assertTrue(Hash::check('Original123', $user->fresh()->password), 'an expired code changes nothing');

        $age($first, 61);
        $second = $request();
        $this->assertNotSame($first, $second);
        $secondCode = $this->mails()[1]['code'];
        $this->assertNotNull(DB::table('password_reset_codes')->where('id', $first)->value('consumed_at'), 'a new request invalidates the previous one');

        $bad = $secondCode === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $second, 'code' => $bad, 'newPassword' => 'MatKhauMoi456'])->assertStatus(400);
        $this->assertSame(5, (int) DB::table('password_reset_codes')->where('id', $second)->value('attempts'));
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $second, 'code' => $secondCode, 'newPassword' => 'MatKhauMoi456'])->assertStatus(400);
        $this->assertTrue(Hash::check('Original123', $user->fresh()->password), 'five failures burn the challenge');

        // At most five challenges per address per hour: five old ones already on file block the sixth request.
        $hash = hash_hmac('sha256', 'recovery:v1:email:han.dung@example.com', (string) config('app.key'));
        DB::table('password_reset_codes')->where('email_hash', $hash)->delete();
        foreach (range(1, 5) as $i) {
            DB::table('password_reset_codes')->insert(['id' => (string) Str::uuid(), 'email_hash' => $hash, 'user_id' => null, 'code_hash' => str_repeat('0', 64), 'expires_at' => now('UTC')->addMinutes(5), 'consumed_at' => now('UTC'), 'created_at' => now('UTC')->subMinutes(5 + $i)]);
        }
        $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'han.dung@example.com'])->assertStatus(429);
        DB::table('password_reset_codes')->where('email_hash', $hash)->update(['created_at' => now('UTC')->subHours(2)]);
        $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'han.dung@example.com'])->assertStatus(202);
    }

    public function test_an_undelivered_code_is_invalidated_and_the_response_does_not_reveal_it(): void
    {
        $this->password('thu.loi@example.com');
        \Illuminate\Support\Facades\Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP down with secret details'));
        $id = $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'thu.loi@example.com'])->assertStatus(202)->assertJsonPath('accepted', true)->json('requestId');
        $this->assertNotNull(DB::table('password_reset_codes')->where('id', $id)->value('consumed_at'));
    }

    // ---- recovery by emailed link (existing flow) ------------------------------------------------------------

    public function test_link_tokens_work_once_and_expire(): void
    {
        $user = $this->password('lien.ket@example.com');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lien.ket@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'khong.ton.tai@example.com'])->assertOk();
        $this->assertCount(1, $this->mails());
        $token = $this->mails()[0]['token'];
        $this->assertSame(hash('sha256', $token), DB::table('beautybook_auth_tokens')->where('user_id', $user->id)->where('purpose', 'RESET_PASSWORD')->value('token_hash'));

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'newPassword' => 'MatKhauMoi456'])->assertOk();
        $this->assertTrue(Hash::check('MatKhauMoi456', $user->fresh()->password));
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'newPassword' => 'KhacHan789x'])->assertStatus(422);
        $this->assertTrue(Hash::check('MatKhauMoi456', $user->fresh()->password), 'a used token changes nothing');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lien.ket@example.com'])->assertOk();
        $expired = $this->mails()[1]['token'];
        DB::table('beautybook_auth_tokens')->where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $expired, 'newPassword' => 'KhacHan789x'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['token' => str_repeat('0', 64), 'newPassword' => 'KhacHan789x'])->assertStatus(422);
        $this->assertTrue(Hash::check('MatKhauMoi456', $user->fresh()->password));

        // A reset by code revokes outstanding link tokens for the same account.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'lien.ket@example.com'])->assertOk();
        $live = $this->mails()[2]['token'];
        $this->postJson('/api/v1/auth/forgot-password/code', ['email' => 'lien.ket@example.com'])->assertStatus(202);
        $id = DB::table('password_reset_codes')->value('id');
        $this->postJson('/api/v1/auth/reset-password/code', ['requestId' => $id, 'code' => $this->mails()[3]['code'], 'newPassword' => 'DungMa12345'])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $live, 'newPassword' => 'KhacHan789x'])->assertStatus(422);
        $this->assertTrue(Hash::check('DungMa12345', $user->fresh()->password));
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private function password(string $email): User
    {
        $user = User::forceCreate(['name' => 'Tài khoản '.$email, 'email' => $email, 'password' => Hash::make('Original123'), 'account_kind' => AccountKind::Customer, 'is_active' => true, 'email_verified_at' => now()]);
        app(RoleAssignmentService::class)->grant($user, RoleName::Customer);
        $user->customerProfile()->firstOrCreate([], []);

        return $user;
    }

    private function google(string $credential, ?string $accountType = null)
    {
        return $this->withSession(['google_nonce' => $this->nonce])->postJson(self::GOOGLE, ['credential' => $credential] + ($accountType ? ['accountType' => $accountType] : []));
    }

    private function token(array $claims = [], ?string $nonce = null, int $expiresIn = 3600, $signWith = null, string $kid = 'test-key', string $alg = 'RS256'): string
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $payload = array_merge(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'sub' => 'sub', 'email' => 'x@gmail.com', 'email_verified' => true, 'name' => 'Google User',
            'nonce' => $nonce ?? $this->nonce, 'iat' => time() - 10, 'exp' => time() + $expiresIn], $claims);
        $unsigned = $b64(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid])).'.'.$b64(json_encode($payload));
        openssl_sign($unsigned, $signature, $signWith ?? $this->key, OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.$b64($alg === 'none' ? '' : $signature);
    }

    /** @return list<array{to:string,code:?string,token:?string}> */
    private function mails(): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();

        return collect($transport->messages())->map(function ($sent) {
            $message = $sent->getOriginalMessage();
            $body = (string) $message->getTextBody();
            preg_match('/\b(\d{6})\b/', $body, $code);
            preg_match('/token=([A-Za-z0-9%]+)/', $body, $token);

            return ['to' => $message->getTo()[0]->getAddress(), 'code' => $code[1] ?? null, 'token' => isset($token[1]) ? urldecode($token[1]) : null];
        })->all();
    }
}
