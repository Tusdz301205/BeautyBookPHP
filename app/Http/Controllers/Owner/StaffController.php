<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StaffRequest;
use App\Models\Branch;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $branchIds = $business ? $business->branches()->pluck('id') : collect();

        $staffMembers = Staff::with(['branch', 'services'])
            ->whereIn('branch_id', $branchIds)
            ->latest()
            ->paginate(12);

        return view('owner.staff.index', compact('staffMembers'));
    }

    public function create(Request $request): View
    {
        $business = $request->user()->business;
        $branches = $business ? $business->branches()->with('services')->get() : collect();

        return view('owner.staff.create', compact('branches'));
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $branch = Branch::with('business')->findOrFail($request->branch_id);
        if ($branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $request) {
            if ($request->hasFile('avatar')) {
                $validated['avatar'] = $request->file('avatar')->store('staff', 'public');
            }

            $staff = Staff::create($validated);

            if ($request->has('services') && is_array($request->services)) {
                $staff->services()->sync($request->services);
            }
        });

        return redirect()->route('owner.staff.index')
            ->with('success', 'Nhân viên mới đã được thêm thành công!');
    }

    public function edit(Request $request, Staff $staff): View
    {
        if ($staff->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $business = $request->user()->business;
        $branches = $business ? $business->branches()->with('services')->get() : collect();
        $staff->load('services');

        return view('owner.staff.edit', compact('staff', 'branches'));
    }

    public function update(StaffRequest $request, Staff $staff): RedirectResponse
    {
        if ($staff->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($staff, $validated, $request) {
            if ($request->hasFile('avatar')) {
                if ($staff->avatar && Storage::disk('public')->exists($staff->avatar)) {
                    Storage::disk('public')->delete($staff->avatar);
                }
                $validated['avatar'] = $request->file('avatar')->store('staff', 'public');
            }

            $staff->update($validated);

            if ($request->has('services')) {
                $staff->services()->sync($request->services);
            } else {
                $staff->services()->detach();
            }
        });

        return redirect()->route('owner.staff.index')
            ->with('success', 'Thông tin nhân viên đã được cập nhật!');
    }

    public function toggleStatus(Request $request, Staff $staff): RedirectResponse
    {
        if ($staff->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $newStatus = $staff->status === 'active' ? 'inactive' : 'active';
        $staff->update(['status' => $newStatus]);

        return back()->with('success', "Đã chuyển trạng thái nhân viên sang {$newStatus}.");
    }
}
