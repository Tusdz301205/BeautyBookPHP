<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(Request $request): View
    {
        $favorites = $request->user()
            ->savedServices()
            ->with(['branch.business', 'branch.district.province', 'category'])
            ->latest('saved_services.created_at')
            ->paginate(9);

        return view('customer.favorites.index', compact('favorites'));
    }

    public function toggle(Request $request, Service $service): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $isAttached = $user->savedServices()->where('service_id', $service->id)->exists();

        if ($isAttached) {
            $user->savedServices()->detach($service->id);
            $saved = false;
            $message = 'Đã bỏ lưu dịch vụ khỏi danh sách yêu thích.';
        } else {
            $user->savedServices()->attach($service->id);
            $saved = true;
            $message = 'Đã lưu dịch vụ vào danh sách yêu thích!';
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'saved' => $saved,
                'message' => $message,
                'total_favorites' => $user->savedServices()->count(),
            ]);
        }

        return back()->with('success', $message);
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $request->user()->savedServices()->detach($service->id);

        return back()->with('success', 'Đã bỏ lưu dịch vụ.');
    }
}
