<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\View\View;

class SalonController extends Controller
{
    public function show(string $slug): View
    {
        $business = Business::with([
            'branches.district.province',
            'branches.workingHours',
            'branches.services.category',
            'branches.reviews.customer',
            'categories.services',
        ])
        ->where('slug', $slug)
        ->where('status', 'active')
        ->firstOrFail();

        // Flatten reviews from all branches for salon overview
        $reviews = $business->branches->flatMap->reviews
            ->where('status', 'approved')
            ->sortByDesc('created_at')
            ->take(10);

        return view('salons.show', compact('business', 'reviews'));
    }
}
