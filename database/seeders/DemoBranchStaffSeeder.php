<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Services\Staff\StaffManagementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Add missing demo providers without resetting existing staff or capabilities. */
class DemoBranchStaffSeeder extends Seeder
{
    public function run(StaffManagementService $staffService): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $definitions = [
            'beautybook-demo' => ['binh-tri-dong', 'tan-phu', 'quan-6'],
            'glow-studio' => ['vo-van-tan', 'nguyen-dinh-chieu', 'phu-nhuan', 'go-vap'],
        ];

        DB::transaction(function () use ($definitions, $staffService) {
            foreach ($definitions as $businessSlug => $branchSlugs) {
                $branches = Branch::whereHas('business', fn ($query) => $query->where('slug', $businessSlug))
                    ->whereIn('slug', $branchSlugs)->get();
                foreach ($branches as $branch) {
                    $employeeCode = 'DEMO-'.strtoupper($branch->slug);
                    if (StaffProfile::where('business_id', $branch->business_id)->where('employee_code', $employeeCode)->exists()
                        || StaffProfile::where('branch_id', $branch->id)->exists()) {
                        continue;
                    }

                    $staff = $staffService->create($branch->business, [
                        'branch_id' => $branch->id,
                        'full_name' => 'Chuyên viên Demo '.$branch->name,
                        'position' => 'Chuyên viên dịch vụ',
                        'employee_code' => $employeeCode,
                        'status' => 'ACTIVE',
                        'is_bookable' => true,
                        'public_visible' => true,
                        'hired_at' => '2025-01-01',
                    ]);
                    $offeringIds = BranchServiceOffering::where('business_id', $branch->business_id)
                        ->where('branch_id', $branch->id)->where('is_active', true)->where('bookable', true)
                        ->whereHas('service', fn ($query) => $query->where('status', 'ACTIVE')->where('is_active', true))
                        ->pluck('id')->all();
                    $staffService->replaceCapabilities($staff, $offeringIds);
                }
            }
        });
    }
}
