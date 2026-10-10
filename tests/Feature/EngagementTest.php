<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_only_customer_owner_can_review_completed_booking_once(): void
    {
        $booking = $this->booking('PENDING');
        $customer = $this->customer();
        $this->actingAs($customer);
        $this->send(route('engagement.store', $booking), $this->payload())->assertUnprocessable();
        $booking->update(['status' => 'COMPLETED']);
        $other = User::create(['name' => 'Other', 'email' => 'other-review@example.test', 'password' => 'password', 'account_kind' => 'CUSTOMER', 'email_verified_at' => now()]);
        CustomerProfile::create(['user_id' => $other->id]);
        $this->actingAs($other);
        $this->send(route('engagement.store', $booking), $this->payload())->assertForbidden();
        $this->actingAs($customer);
        $this->send(route('engagement.store', $booking), $this->payload())->assertCreated();
        $this->send(route('engagement.store', $booking), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_anonymous_public_review_and_hidden_summary_are_safe(): void
    {
        \Illuminate\Support\Facades\DB::table('platform_settings')->updateOrInsert(['key' => 'platform'], ['value' => json_encode(['allowAnonymousReview' => true]), 'created_at' => now(), 'updated_at' => now()]);
        $booking = $this->booking();
        $this->actingAs($this->customer());
        $this->send(route('engagement.store', $booking), [...$this->payload(), 'is_anonymous' => true])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->get(route('engagement.public', $booking->business_id))->assertOk()->assertSee('Ẩn danh')->assertDontSee($this->customer()->name);
        Review::first()->update(['status' => 'HIDDEN']);
        $this->get(route('engagement.public', $booking->business_id))->assertOk()->assertDontSee('Dịch vụ rất tốt.')->assertSee('0 đánh giá');
    }

    public function test_owner_reply_and_platform_moderation_report_appeal_lifecycle(): void
    {
        $booking = $this->booking();
        $customer = $this->customer();
        $this->actingAs($customer);
        $reviewId = $this->send(route('engagement.store', $booking), $this->payload())->assertCreated()->json('id');
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->send(route('engagement.action', [$reviewId, 'reply']), ['content' => 'Cảm ơn bạn!'])->assertOk();
        $this->send(route('engagement.action', [$reviewId, 'moderate']), ['status' => 'HIDDEN', 'reason_code' => 'POLICY', 'reason' => 'Xem lại'])->assertForbidden();
        $this->actingAs($customer);
        $report = ['reason' => 'Lộ thông tin riêng tư', 'category' => 'PII', 'severity' => 'HIGH'];
        $this->send(route('engagement.action', [$reviewId, 'report']), $report)->assertOk()->assertJsonPath('status', 'HIDDEN');
        $this->send(route('engagement.action', [$reviewId, 'report']), $report)->assertUnprocessable();
        $this->send(route('engagement.action', [$reviewId, 'appeal']), ['reason' => 'Xin xem lại'])->assertOk();
        $this->send(route('engagement.action', [$reviewId, 'appeal']), ['reason' => 'Xin xem lại'])->assertUnprocessable();
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $adminRole = $admin->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->firstOrFail();
        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $adminRole->id]);
        $this->get(route('engagement.reviews'))->assertOk()->assertSee('Xin xem lại');
        $appealId = \Illuminate\Support\Facades\DB::table('review_appeals')->value('id');
        $this->send(route('engagement.action', [$reviewId, 'resolve']), ['appeal_id' => $appealId, 'approve' => true, 'reason' => 'Đã kiểm tra'])->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertDatabaseHas('review_moderation_events', ['review_id' => $reviewId, 'action' => 'APPEAL_APPROVED']);
    }

    public function test_cross_business_reply_and_foreign_service_rating_are_rejected(): void
    {
        $booking = $this->booking();
        $this->actingAs($this->customer());
        $this->send(route('engagement.store', $booking), [...$this->payload(), 'service_ratings' => [['booking_service_id' => 999999, 'rating' => 5]]])->assertUnprocessable();
        $id = $this->send(route('engagement.store', $booking), $this->payload())->assertCreated()->json('id');
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        // A selected role belonging to another business cannot reply to this review.
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => 999999]);
        $this->send(route('engagement.action', [$id, 'reply']), ['content' => 'Invalid workspace'])->assertForbidden();
        $otherOwner = User::where('email', 'owner2@beautybook.local')->firstOrFail();
        $otherAssignment = $otherOwner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($otherOwner)->withSession(['workspace_assignment_id' => $otherAssignment->id]);
        $this->send(route('engagement.action', [$id, 'reply']), ['content' => 'Wrong branch'])->assertForbidden();
    }

    public function test_favorites_are_idempotent_scoped_and_keep_unavailable_entries(): void
    {
        $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->firstOrFail();
        $this->actingAs($this->customer());
        $this->send(route('engagement.save', $offering), [])->assertOk()->assertJsonPath('saved', true);
        $this->send(route('engagement.save', $offering), [])->assertOk();
        $this->assertDatabaseCount('saved_services', 1);
        $offering->update(['is_active' => false]);
        $this->send(route('engagement.save', $offering), [])->assertNotFound();
        $this->get(route('engagement.favorites'))->assertOk()->assertSee('Hiện không khả dụng');
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $this->actingAs($owner);
        $this->send(route('engagement.save', $offering), [])->assertForbidden();
        $this->actingAs($this->customer());
        $this->withSession(['_token' => 'engagement-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'engagement-csrf'])->deleteJson(route('engagement.remove', $offering))->assertOk();
        $this->assertDatabaseCount('saved_services', 0);
    }

    public function test_public_summaries_include_only_public_staff_metadata_and_no_customer_identity_when_anonymous():void
    {
        $booking=$this->booking();
        $staff=\App\Models\StaffProfile::where('branch_id',$booking->branch_id)->firstOrFail();
        $staff->update(['public_visible'=>true,'status'=>'ACTIVE']);
        $offering=BranchServiceOffering::where('branch_id',$booking->branch_id)->firstOrFail();
        Review::create(['booking_id'=>$booking->id,'customer_profile_id'=>$booking->customer_profile_id,'overall_rating'=>4,'comment'=>'Public feedback','is_anonymous'=>true,'status'=>'APPROVED',
            'service_ratings'=>[['staff_profile_id'=>$staff->id,'business_service_id'=>$offering->business_service_id,'service_name'=>'Test service','rating'=>4]]]);
        $this->getJson(route('engagement.public',$booking->business_id))->assertOk()->assertJsonPath('data.0.customerName','Ẩn danh')->assertJsonPath('data.0.customerAvatar',null)->assertJsonPath('data.0.serviceRatings.0.staff.fullName',$staff->full_name)->assertJsonMissing(['email'=>$this->customer()->email]);
        $this->getJson(route('engagement.service',$offering->business_service_id))->assertOk()->assertJsonPath('ratings.0.staff.fullName',$staff->full_name);
        $staff->update(['public_visible'=>false]);
        $this->getJson(route('engagement.service',$offering->business_service_id))->assertOk()->assertJsonPath('ratings.0.staff',null);
        $this->getJson(route('engagement.staff',$staff))->assertNotFound();
    }

    private function customer(): User { return User::where('email', 'customer@beautybook.local')->firstOrFail(); }
    public function test_public_review_avatar_uses_self_public_reference_and_respects_anonymity(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $customer = $this->customer();
        $this->actingAs($customer);
        $image = \Illuminate\Http\UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ZkAAAAASUVORK5CYII='));
        $avatar = $this->send(route('profile.avatar.upload'), ['avatar' => $image])->assertCreated()->json('avatar_url');
        $booking = $this->booking();
        $this->send(route('engagement.store', $booking), $this->payload())->assertCreated();
        $review = Review::firstOrFail();
        $url = route('engagement.public', $booking->business_id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.customerAvatar', $avatar);
        $review->update(['is_anonymous' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.customerAvatar', null)->assertJsonPath('data.0.customerName', 'Ẩn danh');
    }
    private function payload(): array { return ['overall_rating' => 5, 'comment' => 'Dịch vụ rất tốt.']; }
    private function send(string $url, array $data)
    {
        return $this->withSession(['_token' => 'engagement-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'engagement-csrf'])->postJson($url, $data);
    }
    private function booking(string $status = 'COMPLETED'): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        return Booking::create([
            'business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'customer_profile_id' => CustomerProfile::where('user_id', $this->customer()->id)->value('id'),
            'created_by_user_id' => $this->customer()->id, 'booking_code' => 'TEST-'.uniqid(),
            'appointment_start_at' => now()->subDay(), 'appointment_end_at' => now()->subDay()->addHour(),
            'status' => $status, 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000,
        ]);
    }
}
