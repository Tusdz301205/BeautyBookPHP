<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\View\View;

class PublicServiceController extends Controller
{
    public function show(Service $service): View
    {
        $service->load([
            'branch.business',
            'branch.district.province',
            'branch.workingHours',
            'category',
            'staff',
            'images',
        ]);

        $relatedServices = Service::where('branch_id', $service->branch_id)
            ->where('id', '!=', $service->id)
            ->where('status', 'active')
            ->take(3)
            ->get();

        return view('services.show', compact('service', 'relatedServices'));
    }
}
