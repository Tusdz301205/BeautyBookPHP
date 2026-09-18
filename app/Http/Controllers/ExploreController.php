<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Province;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExploreController extends Controller
{
    public function index(Request $request): View
    {
        $query = Service::with(['branch.business', 'branch.district.province', 'category', 'coverImage'])
            ->where('status', 'active')
            ->where('is_bookable', true)
            ->whereHas('branch', function ($q) {
                $q->where('status', 'active')
                  ->whereHas('business', fn ($b) => $b->where('status', 'active'));
            });

        // Search keyword
        if ($request->filled('q')) {
            $keyword = trim($request->q);
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%")
                  ->orWhereHas('branch', function ($b) use ($keyword) {
                      $b->where('name', 'like', "%{$keyword}%")
                        ->orWhereHas('business', fn ($biz) => $biz->where('name', 'like', "%{$keyword}%"));
                  });
            });
        }

        // Category filter
        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        // Location / Province filter
        if ($request->filled('province')) {
            $query->whereHas('branch.district', function ($d) use ($request) {
                $d->where('province_id', $request->province);
            });
        }

        // Max price filter
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (int) $request->max_price);
        }

        // Sorting
        $sort = $request->get('sort', 'popular');
        match ($sort) {
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'duration_asc' => $query->orderBy('duration_minutes', 'asc'),
            default => $query->latest(),
        };

        $services = $query->paginate(9)->withQueryString();

        $featuredBusinesses = Business::with(['branches.district.province', 'owner'])
            ->where('status', 'active')
            ->has('branches')
            ->take(4)
            ->get();

        $categories = ServiceCategory::where('is_active', true)->get();
        $provinces = Province::withCount('districts')->get();

        return view('explore.index', compact(
            'services',
            'featuredBusinesses',
            'categories',
            'provinces'
        ));
    }

    public function search(Request $request): JsonResponse
    {
        $keyword = trim($request->get('q', ''));
        if (mb_strlen($keyword) < 2) {
            return response()->json(['services' => [], 'salons' => []]);
        }

        $services = Service::with(['branch.business'])
            ->where('status', 'active')
            ->where('is_bookable', true)
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%");
            })
            ->take(5)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'price_formatted' => $s->formatted_price,
                'duration' => $s->duration_minutes . ' phút',
                'salon' => $s->branch?->business?->name,
                'url' => route('services.show', $s->id),
            ]);

        $salons = Business::where('status', 'active')
            ->where('name', 'like', "%{$keyword}%")
            ->take(3)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'logo' => $b->logo_url,
                'url' => route('salons.show', $b->slug),
            ]);

        return response()->json([
            'services' => $services,
            'salons' => $salons,
        ]);
    }
}
