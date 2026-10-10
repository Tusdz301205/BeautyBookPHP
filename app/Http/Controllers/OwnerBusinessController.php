<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\BusinessDraftRequest;
use App\Models\Business;
use App\Services\Onboarding\BusinessOnboardingService;
use DomainException;
use Illuminate\Support\Facades\Gate;

class OwnerBusinessController extends Controller
{
    public function index()
    {
        $ids = request()->user()->roleAssignments()
            ->where('role_name', RoleName::BusinessOwner->value)
            ->pluck('business_id');

        return view('owner.businesses.index', [
            'businesses' => Business::query()->whereIn('id', $ids)->with('branches')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('owner.businesses.form', ['business' => null]);
    }

    public function store(BusinessDraftRequest $request, BusinessOnboardingService $service)
    {
        $business = $service->create($request->user(), $request->validated());

        return redirect()->route('owner.businesses.edit', $business)->with('status', 'Đã tạo hồ sơ doanh nghiệp nháp.');
    }

    public function edit(Business $business)
    {
        Gate::authorize('manage', $business);

        return view('owner.businesses.form', [
            'business' => $business->load(['ownerProfile', 'branches.workingHours']),
        ]);
    }

    public function update(BusinessDraftRequest $request, Business $business, BusinessOnboardingService $service)
    {
        Gate::authorize('manage', $business);

        try {
            $service->updateDraft($business, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Đã lưu hồ sơ doanh nghiệp.');
    }

    public function submit(Business $business, BusinessOnboardingService $service)
    {
        Gate::authorize('manage', $business);

        try {
            $service->submit($business, request()->user());
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()]);
        }

        return back()->with('status', 'Hồ sơ đã được gửi kiểm duyệt.');
    }
}
