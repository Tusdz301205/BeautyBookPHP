<?php

namespace App\Http\Controllers;

use App\Http\Requests\StaffCapabilityRequest;
use App\Http\Requests\StaffInvitationRequest;
use App\Http\Requests\StaffProfileRequest;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\MediaFile;
use App\Models\StaffInvitation;
use App\Models\StaffProfile;
use App\Services\Staff\StaffManagementService;
use DomainException;
use Illuminate\Support\Facades\Gate;

class OwnerStaffController extends Controller
{
    public function index(Business $business)
    {
        Gate::authorize('manage', $business);

        return view('owner.staff.index', [
            'business' => $business->load('branches'),
            'staffProfiles' => StaffProfile::query()
                ->where('business_id', $business->id)
                ->with(['branch', 'offerings.service'])
                ->orderBy('full_name')
                ->get(),
            'offerings' => BranchServiceOffering::query()
                ->where('business_id', $business->id)
                ->where('bookable', true)
                ->where('is_active', true)
                ->with(['branch', 'service'])
                ->orderBy('branch_id')
                ->get(),
            'invitations' => StaffInvitation::query()
                ->where('business_id', $business->id)
                ->latest()
                ->limit(20)
                ->get(),
            'mediaFiles' => MediaFile::query()
                ->where('business_id', $business->id)
                ->where('entity_type', 'STAFF')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(StaffProfileRequest $request, Business $business, StaffManagementService $service)
    {
        Gate::authorize('manage', $business);

        return $this->run(fn () => $service->create($business, $request->validated()), 'Đã tạo hồ sơ nhân viên.');
    }

    public function update(StaffProfileRequest $request, Business $business, StaffProfile $staff, StaffManagementService $service)
    {
        $this->authorizeStaff($business, $staff);

        return $this->run(fn () => $service->update($staff, $request->validated()), 'Đã cập nhật hồ sơ nhân viên.');
    }

    public function capabilities(StaffCapabilityRequest $request, Business $business, StaffProfile $staff, StaffManagementService $service)
    {
        $this->authorizeStaff($business, $staff);

        return $this->run(
            fn () => $service->replaceCapabilities($staff, $request->validated('offering_ids') ?? []),
            'Đã cập nhật capability của nhân viên.',
        );
    }

    public function invite(StaffInvitationRequest $request, Business $business, StaffManagementService $service)
    {
        Gate::authorize('manage', $business);

        return $this->run(fn () => $service->invite($business, $request->user(), $request->validated()), 'Đã tạo lời mời nhân viên.');
    }

    public function archive(Business $business, StaffProfile $staff, StaffManagementService $service)
    {
        $this->authorizeStaff($business, $staff);

        return $this->run(fn () => $service->archive($staff), 'Đã lưu trữ hồ sơ nhân viên và giữ lịch sử assignment.');
    }

    private function authorizeStaff(Business $business, StaffProfile $staff): void
    {
        Gate::authorize('manage', $business);
        abort_unless($staff->business_id === $business->id, 404);
    }

    private function run(callable $action, string $message)
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return back()->with('status', $message);
    }
}
