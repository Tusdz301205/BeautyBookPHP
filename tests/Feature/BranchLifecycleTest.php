<?php

namespace Tests\Feature;

use App\Enums\BranchOperationalStatus;
use App\Enums\BranchReviewStatus;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchStateTransition;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BranchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_can_create_submit_admin_approve_and_owner_publish_branch(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.branches.store', $business), $this->branchPayload('slice-three'))
            ->assertRedirect();

        $branch = Branch::where('business_id', $business->id)->where('slug', 'slice-three')->firstOrFail();

        $this->actingAs($owner)
            ->putWithCsrf(route('owner.branches.hours', [$business, $branch]), ['hours' => $this->hours()])
            ->assertRedirect();

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.branches.submit', [$business, $branch]))
            ->assertRedirect();
        $this->assertSame(BranchReviewStatus::PendingReview, $branch->refresh()->review_status);

        $this->actingAs($admin)
            ->postWithCsrf(route('admin.reviews.branch.review', $branch), [
                'decision' => 'APPROVE',
                'note' => '',
            ])
            ->assertRedirect(route('admin.reviews.index'));

        $branch->refresh();
        $this->assertSame(BranchStatus::Inactive, $branch->status);
        $this->assertSame(BranchReviewStatus::Approved, $branch->review_status);
        $this->assertSame(BranchOperationalStatus::ReadyToPublish, $branch->operational_status);
        $this->get('/explore/beautybook-demo/slice-three')->assertNotFound();

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.branches.publish', [$business, $branch]))
            ->assertRedirect();

        $branch->refresh();
        $this->assertSame(BranchStatus::Active, $branch->status);
        $this->assertSame(BranchOperationalStatus::Active, $branch->operational_status);
        $this->get('/explore/beautybook-demo/slice-three')->assertOk()->assertSee('Slice Three Studio');
        $this->assertSame(
            ['SUBMIT', 'APPROVE', 'PUBLISH'],
            BranchStateTransition::where('branch_id', $branch->id)->orderBy('version')->pluck('action')->all(),
        );
        $this->assertSame([1, 2, 3], BranchStateTransition::where('branch_id', $branch->id)->orderBy('version')->pluck('version')->all());
    }

    public function test_hours_validation_rejects_open_day_without_valid_time_range(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $branch = Branch::where('business_id', $business->id)->where('slug', 'coming-soon')->firstOrFail();
        $hours = $this->hours();
        $hours[1]['open_time'] = '20:00';
        $hours[1]['close_time'] = '09:00';

        $this->actingAs($owner)
            ->from(route('owner.branches.edit', [$business, $branch]))
            ->putWithCsrf(route('owner.branches.hours', [$business, $branch]), ['hours' => $hours])
            ->assertRedirect(route('owner.branches.edit', [$business, $branch]))
            ->assertSessionHasErrors('hours.1.close_time');
    }

    public function test_owner_cannot_manage_branch_from_another_tenant(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'glow-studio')->firstOrFail();
        $branch = Branch::where('business_id', $business->id)->where('slug', 'vo-van-tan')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('owner.branches.edit', [$business, $branch]))
            ->assertForbidden();
    }

    private function branchPayload(string $slug): array
    {
        return [
            'name' => 'Slice Three Internal',
            'slug' => $slug,
            'public_name' => 'Slice Three Studio',
            'description' => 'Chi nhánh dùng kiểm thử lifecycle.',
            'address_line' => 'Tân Tạo, Bình Tân, TP.HCM',
            'phone' => '0902223344',
            'email' => 'slice-three@example.test',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ];
    }

    private function hours(): array
    {
        return collect(range(0, 6))->map(fn (int $day) => [
            'day_of_week' => $day,
            'is_closed' => $day === 0 ? '1' : '0',
            'open_time' => $day === 0 ? null : '09:00',
            'close_time' => $day === 0 ? null : '20:00',
        ])->all();
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
