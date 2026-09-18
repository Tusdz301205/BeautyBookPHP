<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ServiceRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;
        $branchIds = $business ? $business->branches()->pluck('id') : collect();

        $query = Service::with(['branch', 'category', 'coverImage'])
            ->whereIn('branch_id', $branchIds);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('q')) {
            $keyword = trim($request->q);
            $query->where('name', 'like', "%{$keyword}%");
        }

        $services = $query->latest()->paginate(10)->withQueryString();

        $branches = $business ? $business->branches : collect();
        $categories = $business ? $business->categories : collect();

        return view('owner.services.index', compact('services', 'branches', 'categories'));
    }

    public function create(Request $request): View
    {
        $business = $request->user()->business;
        $branches = $business ? $business->branches : collect();
        $categories = $business ? $business->categories : collect();

        return view('owner.services.create', compact('branches', 'categories'));
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $branch = Branch::with('business')->findOrFail($request->branch_id);
        if ($branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validated();
        $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(4);
        $validated['is_bookable'] = $request->boolean('is_bookable', true);

        DB::transaction(function () use ($validated, $request) {
            $service = Service::create($validated);

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('services', 'public');
                ServiceImage::create([
                    'service_id' => $service->id,
                    'image_path' => $path,
                    'sort_order' => 1,
                ]);
            }
        });

        return redirect()->route('owner.services.index')
            ->with('success', 'Dịch vụ mới đã được tạo thành công!');
    }

    public function edit(Request $request, Service $service): View
    {
        if ($service->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $business = $request->user()->business;
        $branches = $business ? $business->branches : collect();
        $categories = $business ? $business->categories : collect();

        return view('owner.services.edit', compact('service', 'branches', 'categories'));
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        if ($service->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validated();
        $validated['is_bookable'] = $request->boolean('is_bookable', true);

        DB::transaction(function () use ($service, $validated, $request) {
            $service->update($validated);

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('services', 'public');
                ServiceImage::create([
                    'service_id' => $service->id,
                    'image_path' => $path,
                    'sort_order' => 1,
                ]);
            }
        });

        return redirect()->route('owner.services.index')
            ->with('success', 'Dịch vụ đã được cập nhật thành công!');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        if ($service->branch->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $service->update(['status' => 'inactive', 'is_bookable' => false]);

        return back()->with('success', 'Dịch vụ đã được chuyển sang trạng thái ngưng hoạt động.');
    }
}
