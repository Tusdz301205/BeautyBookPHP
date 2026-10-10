<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Enums\BusinessStatus;
use App\Enums\RoleName;
use App\Models\Business;
use App\Models\BusinessReviewEvent;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_operational_user_can_create_submit_resubmit_and_get_business_approved(): void
    {
        $owner = User::factory()->create([
            'account_kind' => AccountKind::Operational->value,
            'email_verified_at' => now(),
        ]);
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();

        $payload = $this->businessPayload('new-studio');

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.businesses.store'), $payload)
            ->assertRedirect();

        $business = Business::where('slug', 'new-studio')->firstOrFail();
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $owner->id,
            'role_name' => RoleName::BusinessOwner->value,
            'business_id' => $business->id,
        ]);

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.businesses.submit', $business))
            ->assertRedirect();
        $this->assertSame(BusinessStatus::PendingReview, $business->refresh()->status);

        $this->actingAs($admin)
            ->postWithCsrf(route('admin.reviews.business.review', $business), [
                'decision' => 'REQUEST_INFO',
                'note' => 'Bổ sung mô tả rõ hơn.',
            ])
            ->assertRedirect(route('admin.reviews.index'));
        $this->assertSame(BusinessStatus::NeedMoreInfo, $business->refresh()->status);

        $payload['description'] = 'Hồ sơ đã bổ sung nội dung theo yêu cầu kiểm duyệt.';
        $this->actingAs($owner)
            ->putWithCsrf(route('owner.businesses.update', $business), $payload)
            ->assertRedirect();
        $this->actingAs($owner)
            ->postWithCsrf(route('owner.businesses.submit', $business))
            ->assertRedirect();

        $this->actingAs($admin)
            ->postWithCsrf(route('admin.reviews.business.review', $business), [
                'decision' => 'APPROVE',
                'note' => '',
            ])
            ->assertRedirect(route('admin.reviews.index'));

        $this->assertSame(BusinessStatus::Approved, $business->refresh()->status);
        $this->assertSame(
            ['SUBMIT', 'REQUEST_INFO', 'RESUBMIT', 'APPROVE'],
            BusinessReviewEvent::where('business_id', $business->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_owner_cannot_self_approve_and_non_admin_cannot_open_review_queue(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('admin.reviews.index'))
            ->assertForbidden();

        $candidate = User::factory()->create([
            'account_kind' => AccountKind::Operational->value,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($candidate)->postWithCsrf(route('owner.businesses.store'), $this->businessPayload('self-review'))->assertRedirect();
        $selfBusiness = Business::where('slug', 'self-review')->firstOrFail();
        $this->actingAs($candidate)->postWithCsrf(route('owner.businesses.submit', $selfBusiness))->assertRedirect();
        app(RoleAssignmentService::class)->grant($candidate, RoleName::PlatformAdmin);

        $this->actingAs($candidate)
            ->postWithCsrf(route('admin.reviews.business.review', $selfBusiness), [
                'decision' => 'APPROVE',
                'note' => '',
            ])
            ->assertForbidden();

        $this->assertSame(BusinessStatus::PendingReview, $selfBusiness->refresh()->status);
        $this->assertSame(BusinessStatus::Active, $business->status);
    }

    public function test_owner_cannot_manage_another_tenant_business(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $other = Business::where('slug', 'glow-studio')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('owner.businesses.edit', $other))
            ->assertForbidden();
    }

    private function businessPayload(string $slug): array
    {
        return [
            'name' => 'Studio kiểm thử',
            'slug' => $slug,
            'description' => 'Hồ sơ doanh nghiệp dùng cho kiểm thử Slice 3.',
            'contact_email' => $slug.'@example.test',
            'contact_phone' => '0901112233',
            'address_line' => 'Bình Tân, TP.HCM',
            'company_name' => 'Công ty '.$slug,
            'tax_code' => $slug === 'new-studio' ? '0319990001' : '0319990002',
        ];
    }

    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->post($uri, ['_token' => $token, ...$data]);
    }

    private function putWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])
            ->put($uri, ['_token' => $token, ...$data]);
    }
}
