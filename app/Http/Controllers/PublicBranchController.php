<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\View\View;

class PublicBranchController extends Controller
{
    public function show(Branch $branch): View
    {
        $branch->load([
            'business',
            'district.province',
            'workingHours',
            'services.category',
            'staff.services',
            'reviews.customer',
            'images',
        ]);

        $reviews = $branch->reviews()
            ->where('status', 'approved')
            ->latest()
            ->paginate(6);

        return view('branches.show', compact('branch', 'reviews'));
    }
}
