<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $business = $request->user()->business;

        $categories = $business ? $business->categories()
            ->withCount('services')
            ->orderBy('sort_order')
            ->get() : collect();

        return view('owner.categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $business = $request->user()->business;
        if (!$business) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['business_id'] = $business->id;
        $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(4);
        $validated['is_active'] = true;

        ServiceCategory::create($validated);

        return back()->with('success', 'Đã thêm danh mục dịch vụ mới!');
    }

    public function update(Request $request, ServiceCategory $category): RedirectResponse
    {
        if ($category->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $category->update($validated);

        return back()->with('success', 'Danh mục dịch vụ đã được cập nhật.');
    }

    public function destroy(Request $request, ServiceCategory $category): RedirectResponse
    {
        if ($category->business->owner_id !== $request->user()->id) {
            abort(403);
        }

        $category->delete();

        return back()->with('success', 'Danh mục dịch vụ đã được xóa.');
    }
}
