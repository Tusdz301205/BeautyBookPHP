<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoBranchStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoBranchStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_public_demo_branch_has_a_provider_and_slots_for_its_whole_cart(): void
    {
        $this->seed();
        $this->actingAs(User::where('email', 'customer@beautybook.local')->firstOrFail())
            ->withHeader('Authorization', 'Bearer demo-staff-test')
            ->withSession(['beautybook_access_hash' => hash('sha256', 'demo-staff-test'), 'beautybook_access_expires' => time() + 900]);
        $date = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay();
        while ($date->dayOfWeek === 0) {
            $date = $date->addDay();
        }
        $branches = Branch::publicReady()->whereHas('business', fn ($query) => $query->whereIn('slug', ['beautybook-demo', 'glow-studio']))->get();
        $this->assertCount(8, $branches);
        foreach ($branches as $branch) {
            $serviceIds = BranchServiceOffering::where('branch_id', $branch->id)->pluck('id')->implode(',');
            $query = http_build_query(['branchId' => $branch->id, 'serviceIds' => $serviceIds]);
            $this->assertNotEmpty($this->getJson('/api/v1/staff/public?'.$query)->assertOk()->json());
            $this->assertNotEmpty($this->getJson('/api/v1/bookings/available-slots?'.$query.'&date='.$date->toDateString())->assertOk()->json('slots'));
        }
    }

    public function test_repair_is_additive_and_preserves_staff_edits_and_removed_capabilities(): void
    {
        $this->seed();
        $staff = StaffProfile::where('employee_code', 'DEMO-GO-VAP')->firstOrFail();
        $offering = $staff->offerings()->firstOrFail();
        $staff->offerings()->detach($offering->id);
        $staff->update(['full_name' => 'Edited demo provider', 'is_bookable' => false]);
        $before = $staff->fresh()->getRawOriginal();
        $count = StaffProfile::count();
        $this->seed(DemoBranchStaffSeeder::class);
        $this->assertSame($count, StaffProfile::count());
        $this->assertSame($before, $staff->fresh()->getRawOriginal());
        $this->assertFalse($staff->offerings()->where('branch_service_offerings.id', $offering->id)->exists());
        $this->assertFalse(StaffProfile::whereHas('branch', fn ($query) => $query->whereIn('slug', ['coming-soon', 'suspended-demo']))->exists());
    }
}
