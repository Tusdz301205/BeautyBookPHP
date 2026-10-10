<?php

namespace App\Services\Staff;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffManagementService
{
    public function create(Business $business, array $data): StaffProfile
    {
        $branch = $this->branchForBusiness($business, (int) $data['branch_id']);

        return DB::transaction(function () use ($business, $branch, $data) {
            $profile = StaffProfile::query()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'full_name' => $data['full_name'],
                'position' => $data['position'] ?? null,
                'bio' => $data['bio'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'experience_years' => $data['experience_years'] ?? null,
                'public_visible' => (bool) ($data['public_visible'] ?? false),
                'is_bookable' => (bool) ($data['is_bookable'] ?? false),
                'status' => $data['status'] ?? 'PROFILE_ONLY',
                'hired_at' => $data['hired_at'] ?? null,
            ]);

            $profile->assignments()->create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'start_date' => $data['hired_at'] ?? now()->toDateString(),
                'status' => 'ACTIVE',
                'job_title' => $data['position'] ?? null,
                'is_primary' => true,
                'is_bookable' => (bool) ($data['is_bookable'] ?? false),
            ]);

            return $profile->load(['branch', 'assignments']);
        });
    }

    public function update(StaffProfile $profile, array $data): StaffProfile
    {
        $branch = Branch::query()
            ->where('id', $data['branch_id'])
            ->where('business_id', $profile->business_id)
            ->first();

        if (! $branch) {
            throw new DomainException('Chi nhánh nhân viên không thuộc doanh nghiệp hiện tại.');
        }

        return DB::transaction(function () use ($profile, $branch, $data) {
            $oldBranchId = $profile->branch_id;
            $profile->update([
                'branch_id' => $branch->id,
                'full_name' => $data['full_name'],
                'position' => $data['position'] ?? null,
                'bio' => $data['bio'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'experience_years' => $data['experience_years'] ?? null,
                'public_visible' => (bool) ($data['public_visible'] ?? false),
                'is_bookable' => (bool) ($data['is_bookable'] ?? false),
                'status' => $data['status'],
                'hired_at' => $data['hired_at'] ?? null,
            ]);

            if ($oldBranchId !== $branch->id) {
                $profile->offerings()->detach();
                $profile->assignments()->where('status', 'ACTIVE')->update([
                    'status' => 'ENDED',
                    'end_date' => now()->toDateString(),
                    'is_primary' => false,
                    'is_bookable' => false,
                ]);
                $profile->assignments()->create([
                    'business_id' => $profile->business_id,
                    'branch_id' => $branch->id,
                    'start_date' => now()->toDateString(),
                    'status' => 'ACTIVE',
                    'job_title' => $data['position'] ?? null,
                    'is_primary' => true,
                    'is_bookable' => (bool) ($data['is_bookable'] ?? false),
                ]);
            }

            if ($data['status'] !== 'ACTIVE' || ! ($data['is_bookable'] ?? false)) {
                $profile->offerings()->detach();
            }

            return $profile->refresh()->load(['branch', 'assignments', 'offerings.service']);
        });
    }

    public function replaceCapabilities(StaffProfile $profile, array $offeringIds): void
    {
        if ($profile->status !== 'ACTIVE' || ! $profile->is_bookable) {
            throw new DomainException('Chỉ nhân viên ACTIVE và có thể nhận lịch mới được gán capability.');
        }

        $ids = collect($offeringIds)->map(fn ($id) => (int) $id)->unique()->values();
        $offerings = BranchServiceOffering::query()
            ->whereIn('id', $ids)
            ->where('business_id', $profile->business_id)
            ->where('branch_id', $profile->branch_id)
            ->where('bookable', true)
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('status', 'ACTIVE')->where('is_active', true))
            ->get();

        if ($offerings->count() !== $ids->count()) {
            throw new DomainException('Capability chỉ được gán cho offering active/bookable trong đúng chi nhánh của nhân viên.');
        }

        $pivot = $offerings->mapWithKeys(fn (BranchServiceOffering $offering) => [
            $offering->id => ['business_id' => $profile->business_id, 'branch_id' => $profile->branch_id],
        ])->all();

        $profile->offerings()->sync($pivot);
    }

    public function invite(Business $business, User $actor, array $data): StaffInvitation
    {
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null;

        if ($branchId !== null) {
            $this->branchForBusiness($business, $branchId);
        }

        $profileId = isset($data['staff_profile_id']) && $data['staff_profile_id'] !== '' ? (int) $data['staff_profile_id'] : null;

        if ($profileId !== null && ! StaffProfile::query()->whereKey($profileId)->where('business_id', $business->id)->exists()) {
            throw new DomainException('Hồ sơ nhân viên không thuộc doanh nghiệp hiện tại.');
        }

        return StaffInvitation::query()->create([
            'email' => mb_strtolower($data['email']),
            'role_code' => $data['role_code'],
            'business_id' => $business->id,
            'branch_id' => $branchId,
            'staff_profile_id' => $profileId,
            'token_hash' => hash('sha256', Str::random(64)),
            'status' => 'PENDING',
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function archive(StaffProfile $profile): void
    {
        DB::transaction(function () use ($profile) {
            $profile->assignments()->where('status', 'ACTIVE')->update([
                'status' => 'ENDED',
                'end_date' => now()->toDateString(),
                'is_primary' => false,
                'is_bookable' => false,
            ]);
            $profile->forceFill(['status' => 'INACTIVE', 'is_bookable' => false, 'public_visible' => false])->save();
            $profile->delete();
        });
    }

    private function branchForBusiness(Business $business, int $branchId): Branch
    {
        $branch = Branch::query()->whereKey($branchId)->where('business_id', $business->id)->first();

        if (! $branch) {
            throw new DomainException('Chi nhánh không thuộc doanh nghiệp hiện tại.');
        }

        return $branch;
    }
}
