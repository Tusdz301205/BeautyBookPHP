<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchDraftRequest;
use App\Http\Requests\BranchHoursRequest;
use App\Models\Branch;
use App\Models\Business;
use App\Services\Branches\BranchLifecycleService;
use DomainException;
use Illuminate\Support\Facades\Gate;

class OwnerBranchController extends Controller
{
    public function create(Business $business)
    {
        Gate::authorize('manage', $business);

        return view('owner.branches.form', ['business' => $business, 'branch' => null]);
    }

    public function store(BranchDraftRequest $request, Business $business, BranchLifecycleService $service)
    {
        Gate::authorize('manage', $business);
        $branch = $service->create($business->id, $request->validated());

        return redirect()->route('owner.branches.edit', [$business, $branch])->with('status', 'Đã tạo chi nhánh nháp.');
    }

    public function edit(Business $business, Branch $branch)
    {
        $this->authorizeNested($business, $branch);

        return view('owner.branches.form', ['business' => $business, 'branch' => $branch->load('workingHours')]);
    }

    public function update(BranchDraftRequest $request, Business $business, Branch $branch, BranchLifecycleService $service)
    {
        $this->authorizeNested($business, $branch);

        try {
            $service->updateDraft($branch, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Đã lưu thông tin chi nhánh.');
    }

    public function updateHours(BranchHoursRequest $request, Business $business, Branch $branch, BranchLifecycleService $service)
    {
        $this->authorizeNested($business, $branch);

        try {
            $service->replaceHours($branch, $request->validated('hours'));
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()]);
        }

        return back()->with('status', 'Đã cập nhật giờ mở cửa.');
    }

    public function submit(Business $business, Branch $branch, BranchLifecycleService $service)
    {
        $this->authorizeNested($business, $branch);

        return $this->transition(fn () => $service->submit($branch, request()->user()), 'Chi nhánh đã được gửi kiểm duyệt.');
    }

    public function publish(Business $business, Branch $branch, BranchLifecycleService $service)
    {
        $this->authorizeNested($business, $branch);

        return $this->transition(fn () => $service->publish($branch, request()->user()), 'Chi nhánh đã được công khai trên marketplace.');
    }

    private function authorizeNested(Business $business, Branch $branch): void
    {
        Gate::authorize('manage', $business);
        abort_unless($branch->business_id === $business->id, 404);
        Gate::authorize('manage', $branch);
    }

    public function operational(\Illuminate\Http\Request $request, Business $business, Branch $branch, BranchLifecycleService $service)
    {
        $this->authorizeNested($business, $branch);
        $data = $request->validate(['action' => 'required|in:PAUSE,RESTORE,CLOSE', 'reason' => 'required|string|max:2000']);
        $method = strtolower($data['action']);
        return $this->transition(fn () => $service->$method($branch, $request->user(), $data['reason']), 'Đã cập nhật trạng thái vận hành chi nhánh.');
    }

    private function transition(callable $action, string $message)
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['domain' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }
}
