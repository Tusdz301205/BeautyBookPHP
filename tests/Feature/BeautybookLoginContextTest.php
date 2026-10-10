<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\User;
use App\Services\Beautybook\LoginContext;
use App\Services\Identity\RoleAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BeautybookLoginContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function challenge(User $user, array $input): array
    {
        try {
            app(LoginContext::class)->resolve($user, $input);
            $this->fail('Ambiguous context must not select an assignment.');
        } catch (HttpResponseException $e) {
            $this->assertSame(400, $e->getResponse()->getStatusCode());

            return json_decode($e->getResponse()->getContent(), true);
        }
    }

    public function test_customer_workspace_cannot_be_used_to_enter_salon_and_invalid_workspace_is_rejected(): void
    {
        $customer = $this->user('customer@beautybook.local');
        $result = app(LoginContext::class)->resolve($customer, ['workspace' => 'CUSTOMER', 'businessId' => '1']);
        $this->assertSame('CUSTOMER', $result['workspace']);
        $this->assertNull($result['businessId']);
        $this->assertSame($customer->id, $result['assignment']->user_id);
        $this->assertSame(['message' => 'Không gian làm việc không hợp lệ'], $this->challenge($customer, ['workspace' => 'OTHER']));
        $this->expectException(HttpException::class);
        app(LoginContext::class)->resolve($customer, ['workspace' => 'SALON']);
    }

    public function test_multiple_businesses_require_a_real_challenge_then_select_only_the_requested_business(): void
    {
        $owner = $this->user('owner@beautybook.local');
        $other = $this->user('owner2@beautybook.local')->roleAssignments()->firstOrFail()->business;
        $role = app(RoleAssignmentService::class)->grant($owner, RoleName::BusinessOwner, $other);
        $challenge = $this->challenge($owner, []);
        $this->assertSame('BUSINESS_REQUIRED', $challenge['code']);
        $this->assertContains((string) $other->id, $challenge['businessIds']);
        $this->assertContains($other->name, array_column($challenge['businesses'], 'name'));
        $result = app(LoginContext::class)->resolve($owner, ['workspace' => 'SALON', 'businessId' => (string) $other->id]);
        $this->assertSame($role->id, $result['assignment']->id);
        $this->assertSame([(string) $other->id], $result['assignments']->pluck('business_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
    }

    public function test_platform_and_salon_are_ambiguous_and_explicit_platform_strips_tenant_scope(): void
    {
        $owner = $this->user('owner@beautybook.local');
        $platform = app(RoleAssignmentService::class)->grant($owner, RoleName::PlatformAdmin);
        $challenge = $this->challenge($owner, []);
        $this->assertSame('WORKSPACE_REQUIRED', $challenge['code']);
        $this->assertSame(['SALON', 'PLATFORM'], $challenge['availableWorkspaces']);
        $result = app(LoginContext::class)->resolve($owner, ['workspace' => 'PLATFORM', 'businessId' => '1', 'branchId' => '1']);
        $this->assertSame($platform->id, $result['assignment']->id);
        $this->assertNull($result['businessId']);
        $this->assertNull($result['branchId']);
    }

    public function test_owner_cannot_request_a_branch_from_another_business(): void
    {
        $owner = $this->user('owner@beautybook.local');
        $other = $this->user('owner2@beautybook.local')->roleAssignments()->firstOrFail()->business;
        $branch = Branch::where('business_id', $other->id)->firstOrFail();
        try {
            app(LoginContext::class)->resolve($owner, ['workspace' => 'SALON', 'branchId' => (string) $branch->id]);
            $this->fail('A global owner grant must not authorize a foreign branch.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_expired_roles_do_not_authorize_login_or_an_existing_bearer_session(): void
    {
        $owner = $this->user('owner@beautybook.local');
        $role = $owner->roleAssignments()->firstOrFail();
        $role->update(['expires_at' => CarbonImmutable::now('UTC')->subMinute()]);
        $this->actingAs($owner)->withHeader('Authorization', 'Bearer expiry-test')->withSession(['workspace_assignment_id' => $role->id, 'beautybook_access_hash' => hash('sha256', 'expiry-test'), 'beautybook_access_expires' => time() + 900]);
        $this->getJson('/api/v1/bookings')->assertForbidden();
        $this->postJson('/api/v1/auth/refresh')->assertForbidden();
        try {
            app(LoginContext::class)->resolve($owner);
            $this->fail('Expired assignments cannot create a fresh session.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_login_challenges_do_not_leave_an_authenticated_session_or_issue_a_token(): void
    {
        $owner = $this->user('owner@beautybook.local');
        app(RoleAssignmentService::class)->grant($owner, RoleName::PlatformAdmin);
        $response = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'BeautyBook123!'])->assertStatus(400)->assertJsonPath('code', 'WORKSPACE_REQUIRED');
        $this->assertNull($response->json('accessToken'));
        $this->assertGuest();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'BeautyBook123!', 'workspace' => 'PLATFORM'])->assertOk()->assertJsonPath('user.roles', ['PLATFORM_ADMIN']);
    }
}
