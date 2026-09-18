<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\BranchRequest;
use App\Models\Branch;
use App\Models\BranchWorkingHour;
use App\Models\District;
use App\Models\Province;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;

        $branches = $business ? $business->branches()
            ->with(['district.province'])
            ->withCount(['services', 'staff', 'bookings'])
            ->get() : collect();

        return view('owner.branches.index', compact('branches'));
    }

    public function create(Request $request): View
    {
        $provinces = Province::with('districts')->get();
        return view('owner.branches.create', compact('provinces'));
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $business = $request->user()->business;

        if (!$business) {
            abort(403, 'Bạn cần thiết lập doanh nghiệp trước.');
        }

        $validated = $request->validated();
        $validated['business_id'] = $business->id;
        $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(4);

        DB::transaction(function () use ($validated, &$branch) {
            $branch = Branch::create($validated);

            // Default working hours Mon-Sun 08:30 to 21:00
            for ($day = 0; $day <= 6; $day++) {
                BranchWorkingHour::create([
                    'branch_id' => $branch->id,
                    'day_of_week' => $day,
                    'open_time' => '08:30:00',
                    'close_time' => '21:00:00',
                    'is_closed' => false,
                ]);
            }
        });

        return redirect()->route('owner.branches.index')
            ->with('success', 'Đã thêm chi nhánh mới thành công!');
    }

    public function edit(Request $request, Branch $branch): View
    {
        if ($branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $branch->load(['district.province', 'workingHours']);
        $provinces = Province::with('districts')->get();

        return view('owner.branches.edit', compact('branch', 'provinces'));
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        if ($branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validated();
        if ($branch->name !== $validated['name']) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(4);
        }

        $branch->update($validated);

        // Update working hours if passed
        if ($request->has('working_hours') && is_array($request->working_hours)) {
            foreach ($request->working_hours as $day => $data) {
                BranchWorkingHour::updateOrCreate(
                    ['branch_id' => $branch->id, 'day_of_week' => (int)$day],
                    [
                        'open_time' => $data['open_time'] ?? '08:30:00',
                        'close_time' => $data['close_time'] ?? '21:00:00',
                        'is_closed' => isset($data['is_closed']),
                    ]
                );
            }
        }

        return redirect()->route('owner.branches.index')
            ->with('success', 'Chi nhánh đã được cập nhật thành công!');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        if ($branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        // Toggle status to inactive rather than hard delete to preserve bookings
        $branch->update(['status' => 'inactive']);

        return back()->with('success', 'Chi nhánh đã được chuyển sang trạng thái ngưng hoạt động.');
    }
}
