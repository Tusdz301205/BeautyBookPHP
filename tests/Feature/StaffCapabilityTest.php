<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Staff\StaffManagementService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_staff_ui_creates_profile_and_assigns_capability_in_the_same_branch(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $branch = Branch::where('business_id', $business->id)->where('slug', 'binh-tri-dong')->firstOrFail();
        $offering = BranchServiceOffering::where('business_id', $business->id)->where('branch_id', $branch->id)->firstOrFail();

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.staff.store', $business), [
                'branch_id' => $branch->id,
                'full_name' => 'Nhân viên Slice Four',
                'position' => 'Hair stylist',
                'employee_code' => 'BB-BTD-004',
                'experience_years' => 2,
                'public_visible' => '1',
                'is_bookable' => '1',
                'status' => 'ACTIVE',
                'hired_at' => '2026-09-01',
            ])
            ->assertRedirect();

        $profile = StaffProfile::where('business_id', $business->id)->where('employee_code', 'BB-BTD-004')->firstOrFail();
        $this->assertDatabaseHas('staff_branch_assignments', [
            'staff_profile_id' => $profile->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($owner)
            ->putWithCsrf(route('owner.staff.capabilities', [$business, $profile]), ['offering_ids' => [$offering->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('staff_services', [
            'staff_profile_id' => $profile->id,
            'branch_service_offering_id' => $offering->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_capability_service_rejects_an_offering_from_another_branch(): void
    {
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $profile = StaffProfile::where('business_id', $business->id)->where('status', 'ACTIVE')->firstOrFail();
        $otherOffering = BranchServiceOffering::where('business_id', $business->id)
            ->where('branch_id', '!=', $profile->branch_id)
            ->firstOrFail();

        $this->expectException(DomainException::class);

        app(StaffManagementService::class)->replaceCapabilities($profile, [$otherOffering->id]);
    }

    public function test_database_foreign_keys_reject_cross_branch_staff_capability_rows(): void
    {
        $profile = StaffProfile::where('status', 'ACTIVE')->firstOrFail();
        $otherOffering = BranchServiceOffering::where('business_id', $profile->business_id)
            ->where('branch_id', '!=', $profile->branch_id)
            ->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('staff_services')->insert([
            'staff_profile_id' => $profile->id,
            'branch_service_offering_id' => $otherOffering->id,
            'business_id' => $profile->business_id,
            'branch_id' => $profile->branch_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_cannot_create_staff_profile_in_another_business_branch(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $foreignBranch = Branch::whereHas('business', fn ($query) => $query->where('slug', 'glow-studio'))->firstOrFail();

        $this->actingAs($owner)
            ->from(route('owner.staff.index', $business))
            ->postWithCsrf(route('owner.staff.store', $business), [
                'branch_id' => $foreignBranch->id,
                'full_name' => 'Sai tenant',
                'employee_code' => 'CROSS-001',
                'public_visible' => '1',
                'is_bookable' => '1',
                'status' => 'ACTIVE',
            ])
            ->assertRedirect(route('owner.staff.index', $business))
            ->assertSessionHasErrors('domain');

        $this->assertDatabaseMissing('staff_profiles', [
            'business_id' => $business->id,
            'employee_code' => 'CROSS-001',
        ]);
    }

    public function test_archiving_staff_preserves_capability_and_ends_assignment_history(): void
    {
        $profile = StaffProfile::where('status', 'ACTIVE')->firstOrFail();
        $offeringId = DB::table('staff_services')
            ->where('staff_profile_id', $profile->id)
            ->value('branch_service_offering_id');

        $this->assertNotNull($offeringId);

        app(StaffManagementService::class)->archive($profile);

        $this->assertSoftDeleted('staff_profiles', ['id' => $profile->id]);
        $this->assertDatabaseHas('staff_profiles', [
            'id' => $profile->id,
            'status' => 'INACTIVE',
            'is_bookable' => false,
            'public_visible' => false,
        ]);
        $this->assertDatabaseHas('staff_branch_assignments', [
            'staff_profile_id' => $profile->id,
            'status' => 'ENDED',
            'is_primary' => false,
            'is_bookable' => false,
        ]);
        $this->assertDatabaseHas('staff_services', [
            'staff_profile_id' => $profile->id,
            'branch_service_offering_id' => $offeringId,
            'business_id' => $profile->business_id,
            'branch_id' => $profile->branch_id,
        ]);
    }

    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])->post($uri, ['_token' => $token, ...$data]);
    }

    private function putWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])->put($uri, ['_token' => $token, ...$data]);
    }
}
