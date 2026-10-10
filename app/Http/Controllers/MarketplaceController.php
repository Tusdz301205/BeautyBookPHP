<?php

namespace App\Http\Controllers;

use App\Models\ServiceCategory;
use App\Queries\MarketplaceQuery;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    public function index(Request $request, MarketplaceQuery $marketplace)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return view('marketplace.index', [
            'branches' => $marketplace->search($filters),
            'categories' => ServiceCategory::query()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(string $businessSlug, string $branchSlug, MarketplaceQuery $marketplace)
    {
        return view('marketplace.show', ['branch' => $marketplace->detail($businessSlug, $branchSlug)]);
    }
}
