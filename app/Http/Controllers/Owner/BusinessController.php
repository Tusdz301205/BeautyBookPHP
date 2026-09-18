<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\BusinessUpdateRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BusinessController extends Controller
{
    public function edit(Request $request): View
    {
        $business = $request->user()->business;

        if (!$business) {
            $business = Business::create([
                'owner_id' => $request->user()->id,
                'name' => 'Salon của tôi',
                'status' => 'active',
            ]);
        }

        return view('owner.business.edit', compact('business'));
    }

    public function update(BusinessUpdateRequest $request): RedirectResponse
    {
        $business = $request->user()->business;

        if (!$business) {
            abort(404, 'Không tìm thấy thông tin doanh nghiệp.');
        }

        $validated = $request->validated();

        if ($request->hasFile('logo')) {
            if ($business->logo && Storage::disk('public')->exists($business->logo)) {
                Storage::disk('public')->delete($business->logo);
            }
            $validated['logo'] = $request->file('logo')->store('logos', 'public');
        }

        // Auto update slug if name changes
        if ($business->name !== $validated['name']) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(4);
        }

        $business->update($validated);

        return back()->with('success', 'Thông tin doanh nghiệp đã được cập nhật thành công.');
    }
}
