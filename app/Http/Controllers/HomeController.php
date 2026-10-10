<?php

namespace App\Http\Controllers;

use App\Models\ServiceCategory;
use App\Queries\MarketplaceQuery;

class HomeController extends Controller
{
    public function __invoke(MarketplaceQuery $marketplace)
    {
        return view('home', ['featured' => $marketplace->search([], 3), 'categories' => ServiceCategory::query()->orderBy('name')->get()]);
    }
}
