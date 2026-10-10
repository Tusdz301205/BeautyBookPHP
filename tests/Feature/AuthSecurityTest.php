<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Business;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_public_registration_always_creates_a_customer_with_customer_assignment(): void
    {
        Notification::fake();

        $response = $this->postWithCsrf('/register', [
            'name' => 'Khách mới',
            'email' => 'new-customer@example.com',
            'password' => 'BeautyBook123!',
            'password_confirmation' => 'BeautyBook123!',
            'account_kind' => AccountKind::Operational->value,
        ]);

        $user = User::where('email', 'new-customer@example.com')->firstOrFail();

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(AccountKind::Customer, $user->account_kind);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $user->id,
            'role_name' => RoleName::Customer->value,
            'scope_key' => 'self',
        ]);
        $this->assertSame(1, $user->roleAssignments()->count());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_all_five_demo_actors_can_login_and_login_regenerates_the_session(): void
    {
        $actors = [
            'customer@beautybook.local' => false,
            'owner@beautybook.local' => true,
            'receptionist@beautybook.local' => true,
            'staff@beautybook.local' => true,
            'admin@beautybook.local' => true,
        ];

        foreach ($actors as $email => $operational) {
            $this->get('/login');
            $beforeLogin = session()->getId();

            $response = $this->postWithCsrf('/login', [
                'email' => $email,
                'password' => 'BeautyBook123!',
            ]);

            $user = User::where('email', $email)->firstOrFail();

            $response->assertRedirect(route('app'));
            $this->assertAuthenticatedAs($user);
            $this->assertNotSame($beforeLogin, session()->getId());

            if ($operational) {
                $this->assertNotNull(session('workspace_assignment_id'));
            } else {
                $this->assertNull(session('workspace_assignment_id'));
            }

            $this->get('/app')->assertOk();
            $beforeLogoutToken = session()->token();
            $this->postWithCsrf('/logout')->assertRedirect(route('home'));
            $this->assertGuest();
            $this->assertNotSame($beforeLogoutToken, session()->token());
        }
    }

    public function test_email_verification_notice_send_and_signed_verification_work(): void
    {
        Notification::fake();

        $this->postWithCsrf('/register', [
            'name' => 'Chưa xác minh',
            'email' => 'verify-me@example.com',
            'password' => 'BeautyBook123!',
            'password_confirmation' => 'BeautyBook123!',
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'verify-me@example.com')->firstOrFail();

        $this->get('/verify-email')->assertOk();
        $this->postWithCsrf('/email/verification-notification')
            ->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmail::class);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this->get($verificationUrl)->assertRedirect(route('app'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_password_reset_notification_and_reset_work(): void
    {
        Notification::fake();
        $user = User::where('email', 'customer@beautybook.local')->firstOrFail();

        $this->postWithCsrf('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', __(Password::ResetLinkSent));

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertNotNull($token);

        $this->postWithCsrf('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BeautyBook456!',
            'password_confirmation' => 'BeautyBook456!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('BeautyBook456!', $user->fresh()->password));
    }

    public function test_workspace_selection_is_limited_to_the_authenticated_operational_user(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $current = $owner->roleAssignments()->firstOrFail();
        $otherBusiness = Business::create([
            'name' => 'BeautyBook Workspace Two',
            'slug' => 'beautybook-workspace-two',
            'status' => 'ACTIVE',
        ]);
        $second = app(RoleAssignmentService::class)
            ->grant($owner, RoleName::BusinessOwner, $otherBusiness);

        $this->actingAs($owner)
            ->withSession(['workspace_assignment_id' => $current->id])
            ->postWithCsrf('/workspace', ['assignment_id' => $second->id])
            ->assertRedirect(route('app'))
            ->assertSessionHas('workspace_assignment_id', $second->id);

        $foreign = $receptionist->roleAssignments()->firstOrFail();

        $this->postWithCsrf('/workspace', ['assignment_id' => $foreign->id])->assertNotFound();

        $this->actingAs($customer)->get('/workspace')->assertForbidden();
    }

    public function test_deleted_active_assignment_blocks_the_app_on_the_next_request(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['workspace_assignment_id' => $assignment->id]);

        $assignment->delete();

        $this->get('/app')->assertForbidden();
    }

    public function test_security_session_revocation_requires_current_password_and_deletes_database_sessions(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();

        DB::table('sessions')->insert([
            [
                'id' => 'owner-session-a',
                'user_id' => $owner->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'owner-session-b',
                'user_id' => $owner->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $this->actingAs($owner)
            ->postWithCsrf('/security/sessions/revoke', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('sessions', ['id' => 'owner-session-a', 'user_id' => $owner->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'owner-session-b', 'user_id' => $owner->id]);

        $this->postWithCsrf('/security/sessions/revoke', ['password' => 'BeautyBook123!'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());
    }

    public function test_csrf_middleware_rejects_a_post_without_a_token(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $this->post('/login', [
                'email' => 'customer@beautybook.local',
                'password' => 'BeautyBook123!',
            ])->assertStatus(419);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->post($uri, ['_token' => $token, ...$data]);
    }
}
