<?php

namespace Tests\Feature;

use App\Models\BranchServiceOffering;
use App\Models\Branch;
use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BeautybookIdentityEngagementApiTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function actor(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail(); $role = $user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $role->id, 'beautybook_access_hash' => hash('sha256', 'identity-test'), 'beautybook_access_expires' => time() + 900])->withHeader('Authorization', 'Bearer identity-test');
        return $user;
    }

    public function test_source_login_has_one_valid_workspace_and_bearer_is_bound_to_session(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'customer@beautybook.local', 'password' => 'BeautyBook123!'])->assertOk()->assertJsonPath('user.roles', ['CUSTOMER'])->assertJsonPath('user.sessionType', 'customer');
        $token = $response->json('accessToken'); $this->assertNotEmpty($response->headers->get('X-CSRF-TOKEN'));
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/users/me/profile')->assertOk();
        $this->withHeader('Authorization', 'Bearer forged')->getJson('/api/v1/users/me/profile')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('X-Business-Id', '1')->getJson('/api/v1/users/me/profile')->assertForbidden();
    }

    public function test_refresh_revalidates_revoked_current_assignment_and_customer_cannot_get_management_data(): void
    {
        $owner = $this->actor('owner@beautybook.local'); $owner->roleAssignments()->delete();
        $this->postJson('/api/v1/auth/refresh')->assertForbidden();
        $this->actor('customer@beautybook.local');
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/reviews/manage')->assertForbidden();
        $this->getJson('/api/v1/vouchers')->assertForbidden();
    }

    public function test_owner_registration_creates_real_private_draft_and_verification_token_is_hashed(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register', ['accountType' => 'BUSINESS_OWNER', 'fullName' => 'Chủ mới', 'email' => 'new-owner@example.com', 'phone' => '0909876543', 'password' => 'BeautyBook123!'])->assertCreated()->assertJsonPath('user.roles', ['BUSINESS_OWNER'])->assertJsonPath('user.sessionType', 'salon');
        $user = User::where('email', 'new-owner@example.com')->firstOrFail(); $role = $user->roleAssignments()->firstOrFail();
        $this->assertNotNull($role->business_id); $this->assertSame('DRAFT', $role->business->status->value);
        $row = DB::table('beautybook_auth_tokens')->where('user_id', $user->id)->first();
        $this->assertSame(64, strlen($row->token_hash)); $this->assertNull($row->consumed_at); $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseMissing('branches', ['business_id' => $role->business_id]);
    }

    public function test_password_reset_is_single_use_and_revokes_existing_sessions(): void
    {
        $user = User::where('email', 'customer@beautybook.local')->firstOrFail();
        DB::table('beautybook_auth_tokens')->insert(['user_id' => $user->id, 'purpose' => 'RESET_PASSWORD', 'token_hash' => hash('sha256', 'reset-one'), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $payload = ['token' => 'reset-one', 'newPassword' => 'NewBeautyBook456!'];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk()->assertJsonPath('requiresLogin', true);
        $this->assertTrue(Hash::check($payload['newPassword'], $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_notification_filters_and_read_actions_never_cross_accounts(): void
    {
        $customer = $this->actor('customer@beautybook.local'); $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        foreach ([$customer, $owner] as $user) DB::table('notifications')->insert(['user_id' => $user->id, 'type' => 'SYSTEM', 'severity' => 'WARNING', 'title' => 'Cần chú ý', 'body' => 'Thông báo thật', 'is_read' => false, 'dedupe_key' => 'identity-notification-'.$user->id, 'created_at' => now()]);
        $own = DB::table('notifications')->where('user_id', $customer->id)->first(); $foreign = DB::table('notifications')->where('user_id', $owner->id)->first();
        $this->getJson('/api/v1/notifications?state=unread&severity=WARNING&search=chú')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1);
        $this->patchJson('/api/v1/notifications/'.$foreign->id.'/read')->assertNotFound();
        $this->patchJson('/api/v1/notifications/'.$own->id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('count', 0);
        $this->assertDatabaseHas('notifications', ['id' => $foreign->id, 'is_read' => false]);
    }

    public function test_saved_offering_and_profile_edits_are_real_and_self_scoped(): void
    {
        $user = $this->actor('customer@beautybook.local'); $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->firstOrFail();
        $this->postJson('/api/v1/customer/saved-services/'.$offering->id)->assertOk();
        $this->getJson('/api/v1/customer/saved-services')->assertOk()->assertJsonPath('0.branchServiceOfferingId', (string) $offering->id)->assertJsonPath('0.isAvailable', true);
        $this->patchJson('/api/v1/users/me/profile', ['fullName' => 'Tên đã đổi', 'phone' => '0901112233', 'gender' => 'OTHER', 'address' => 'Địa chỉ mới'])->assertOk()->assertJsonPath('fullName', 'Tên đã đổi')->assertJsonPath('gender', 'OTHER');
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $user->id, 'phone' => '0901112233']);
        $this->actor('owner@beautybook.local'); $this->postJson('/api/v1/customer/saved-services/'.$offering->id)->assertForbidden();
    }

    public function test_private_avatar_needs_session_and_does_not_accept_another_user_id(): void
    {
        $this->getJson('/api/v1/users/me/avatar')->assertUnauthorized();
        $this->actor('staff@beautybook.local');
        $this->getJson('/api/v1/users/me/avatar')->assertNotFound();
        $this->getJson('/api/v1/users/9/avatar')->assertNotFound();
    }

    public function test_only_platform_can_list_users_and_admin_phone_search_uses_real_profile(): void
    {
        $this->actor('owner@beautybook.local'); $this->getJson('/api/v1/users')->assertForbidden();
        $this->actor('receptionist@beautybook.local'); $this->getJson('/api/v1/users')->assertForbidden();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        DB::table('beautybook_user_profiles')->insert(['user_id' => $owner->id, 'phone' => '0901239876', 'updated_at' => now()]);
        $this->actor('admin@beautybook.local');
        $this->getJson('/api/v1/users?search=0901239876')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone', '0901239876')->assertJsonStructure(['meta' => ['summary' => ['teamMembers']]]);
    }

    public function test_management_review_queue_keeps_all_rows_relations_and_exact_source_appeal_payload(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $last = null;
        foreach (range(1, 21) as $number) {
            $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id, 'booking_code' => 'SOURCE-REVIEW-'.$number, 'appointment_start_at' => now()->subDays(2), 'appointment_end_at' => now()->subDays(2)->addHour(), 'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000]);
            $last = Review::create(['booking_id' => $booking->id, 'customer_profile_id' => $customer->customerProfile->id, 'overall_rating' => 5, 'comment' => 'Nội dung cần kiểm tra', 'is_anonymous' => false, 'status' => 'APPROVED', 'service_ratings' => []]);
        }
        $this->actor('owner@beautybook.local');
        $this->postJson('/api/v1/reviews/'.$last->id.'/report', ['reason' => 'Báo cáo nội dung', 'category' => 'ABUSE', 'severity' => 'MEDIUM'])->assertOk();
        $this->getJson('/api/v1/reviews/manage')->assertOk()->assertJsonCount(21)->assertJsonPath('0.customer.user.fullName', $customer->name)->assertJsonPath('0.booking.branch.name', $branch->name)->assertJsonPath('0.reports.0.reason', 'Báo cáo nội dung');
        $last->update(['status' => 'HIDDEN']);
        $this->postJson('/api/v1/reviews/'.$last->id.'/appeals', ['reason' => 'Xin xét lại'])->assertOk();
        $appeal = DB::table('review_appeals')->where('review_id', $last->id)->value('id');
        $this->actor('admin@beautybook.local');
        $this->patchJson('/api/v1/reviews/appeals/'.$appeal, ['approve' => true, 'resolution' => 'Đã xác minh'])->assertOk();
        $this->assertDatabaseHas('review_appeals', ['id' => $appeal, 'status' => 'APPROVED', 'resolution' => 'Đã xác minh']);
        $this->assertSame('APPROVED', $last->fresh()->status);
        $this->actor('owner2@beautybook.local'); $this->getJson('/api/v1/reviews/manage')->assertOk()->assertJsonCount(0);
    }

    public function test_temporarily_unavailable_saved_service_retains_its_identity(): void
    {
        $this->actor('customer@beautybook.local');
        $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->firstOrFail();
        $this->postJson('/api/v1/customer/saved-services/'.$offering->id)->assertOk();
        $offering->update(['bookable' => false]);
        $this->getJson('/api/v1/customer/saved-services')->assertOk()->assertJsonPath('0.available', false)->assertJsonPath('0.offering.id', (string) $offering->id)->assertJsonPath('0.offering.name', $offering->service->name);
    }

    public function test_private_staff_reviews_resolve_live_staff_grant_without_receptionist_cross_branch_access(): void
    {
        $staffUser = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staff = \App\Models\StaffProfile::where('user_id', $staffUser->id)->firstOrFail();
        $otherBranch = Branch::where('business_id', $staff->business_id)->where('id', '!=', $staff->branch_id)->firstOrFail();
        $counter = app(\App\Services\Identity\RoleAssignmentService::class)->grant($staffUser, \App\Enums\RoleName::Receptionist, $staff->business, $otherBranch);
        $this->actor('staff@beautybook.local');
        $this->withSession(['workspace_assignment_id' => $counter->id]);
        $this->getJson('/api/v1/reviews/staff/'.$staff->id)->assertOk()->assertJsonPath('totalRatings', 0);
        $otherStaff = \App\Models\StaffProfile::create(['business_id' => $staff->business_id, 'branch_id' => $staff->branch_id, 'full_name' => 'Chuyên viên khác', 'position' => 'Chuyên viên làm đẹp', 'employee_code' => 'REVIEW-OTHER-PROVIDER', 'status' => 'ACTIVE', 'public_visible' => false, 'is_bookable' => false]);
        $this->getJson('/api/v1/reviews/staff/'.$otherStaff->id)->assertForbidden();
        $this->actor('owner2@beautybook.local');
        $this->getJson('/api/v1/reviews/staff/'.$staff->id)->assertForbidden();
        $this->actor('customer@beautybook.local');
        $this->getJson('/api/v1/reviews/staff/'.$staff->id)->assertForbidden();
    }
}
