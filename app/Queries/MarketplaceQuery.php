<?php

namespace App\Queries;

use App\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MarketplaceQuery
{
    public function search(array $filters, int $perPage = 6): LengthAwarePaginator
    {
        return Branch::query()
            ->publicReady()
            ->with([
                'business:id,name,slug,status',
                'workingHours',
                'offerings' => fn ($query) => $query->where('is_active', true)->with('service.category'),
            ])
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $needle = '%'.trim($q).'%';
                $query->where(function ($nested) use ($needle) {
                    $nested->where('public_name', 'like', $needle)
                        ->orWhere('name', 'like', $needle)
                        ->orWhere('address_line', 'like', $needle)
                        ->orWhereHas('business', fn ($business) => $business->where('name', 'like', $needle))
                        ->orWhereHas('offerings.service', fn ($service) => $service->where('name', 'like', $needle));
                });
            })
            ->when($filters['location'] ?? null, function ($query, string $location) {
                $query->where('address_line', 'like', '%'.trim($location).'%');
            })
            ->when(
                ($filters['category'] ?? null)
                    || (isset($filters['min_price']) && $filters['min_price'] !== '')
                    || (isset($filters['max_price']) && $filters['max_price'] !== ''),
                function ($query) use ($filters) {
                    $query->whereHas('offerings', function ($offering) use ($filters) {
                        $offering->where('is_active', true)
                            ->when($filters['category'] ?? null, function ($offering, string $category) {
                                $offering->whereHas(
                                    'service.category',
                                    fn ($categoryQuery) => $categoryQuery->where('slug', $category),
                                );
                            })
                            ->when(
                                isset($filters['min_price']) && $filters['min_price'] !== '',
                                fn ($offering) => $offering->where('price', '>=', $filters['min_price']),
                            )
                            ->when(
                                isset($filters['max_price']) && $filters['max_price'] !== '',
                                fn ($offering) => $offering->where('price', '<=', $filters['max_price']),
                            );
                    });
                },
            )
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(string $businessSlug, string $branchSlug): Branch
    {
        return Branch::query()
            ->publicReady()
            ->where('slug', $branchSlug)
            ->whereHas('business', fn ($business) => $business->where('slug', $businessSlug))
            ->with([
                'business:id,name,slug,description,status',
                'workingHours',
                'offerings' => fn ($query) => $query->where('is_active', true)->with('service.category'),
            ])
            ->firstOrFail();
    }
}
