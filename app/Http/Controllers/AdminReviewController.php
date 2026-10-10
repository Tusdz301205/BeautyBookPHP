<?php

namespace App\Http\Controllers;

use App\Enums\BranchReviewStatus;
use App\Enums\BusinessStatus;
use App\Enums\RoleName;
use App\Http\Requests\ReviewDecisionRequest;
use App\Models\Branch;
use App\Models\Business;
use App\Services\Branches\BranchLifecycleService;
use App\Services\Onboarding\BusinessOnboardingService;
use DomainException;
use Illuminate\Support\Facades\Gate;

class AdminReviewController extends Controller
{
    public function index()
    {
        $this->assertPlatformAdmin();

        return view('admin.reviews.index', [
            'businesses' => Business::query()->where('status', BusinessStatus::PendingReview->value)->with('ownerProfile.user')->orderBy('submitted_at')->get(),
            'branches' => Branch::query()->where('review_status', BranchReviewStatus::PendingReview->value)->with('business')->orderBy('submitted_at')->get(),
        ]);
    }

    public function business(Business $business)
    {
        Gate::authorize('review', $business);

        return view('admin.reviews.business', ['business' => $business->load(['ownerProfile.user', 'reviewEvents.actor'])]);
    }

    public function reviewBusiness(ReviewDecisionRequest $request, Business $business, BusinessOnboardingService $service)
    {
        Gate::authorize('review', $business);

        try {
            $service->review($business, $request->user(), $request->validated('decision'), $request->validated('note'));
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.reviews.index')->with('status', 'Đã xử lý hồ sơ doanh nghiệp.');
    }

    public function branch(Branch $branch)
    {
        Gate::authorize('review', $branch);

        return view('admin.reviews.branch', ['branch' => $branch->load(['business', 'workingHours', 'stateTransitions'])]);
    }

    public function reviewBranch(ReviewDecisionRequest $request, Branch $branch, BranchLifecycleService $service)
    {
        Gate::authorize('review', $branch);

        try {
            $service->review($branch, $request->user(), $request->validated('decision'), $request->validated('note'));
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.reviews.index')->with('status', 'Đã xử lý hồ sơ chi nhánh.');
    }

    private function assertPlatformAdmin(): void
    {
        abort_unless(
            request()->user()->roleAssignments()->where('role_name', RoleName::PlatformAdmin->value)->exists(),
            403,
        );
    }
}
