<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\CanonicalService;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Services\Beautybook\ApiScope;
use App\Services\Beautybook\PublicPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public function __construct(private PublicPresenter $presenter, private ApiScope $scope) {}

    private function offerings(bool $requiresStaff = false)
    {
        $query = BranchServiceOffering::where('is_active', true)->where('bookable', true)->whereHas('branch', fn ($q) => $q->publicReady())->whereHas('service', fn ($q) => $q->where('is_active', true)->where('status', 'ACTIVE'));
        if ($requiresStaff) $query->whereHas('staff', fn ($q) => $q->publicBookable());
        return $query;
    }

    public function categories()
    {
        return CanonicalService::where('status', 'ACTIVE')->withCount('businessServices')->orderBy('parent_id')->orderBy('name')->get()->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'slug' => $c->slug, 'code' => $c->code, 'description' => $c->description, 'parentId' => $c->parent_id ? (string) $c->parent_id : null, 'synonyms' => $c->synonyms ?? [], 'status' => $c->status, 'replacementCanonicalId' => $c->replacement_canonical_id ? (string) $c->replacement_canonical_id : null, '_count' => ['businessServices' => $c->business_services_count]]);
    }

    public function branches(Request $request)
    {
        $query = Branch::publicReady()->whereHas('offerings', fn ($q) => $q->whereIn('id', $this->offerings()->select('branch_service_offerings.id')))
            ->select('branches.*')->selectSub(Review::selectRaw('COALESCE(AVG(overall_rating),0)')->where('status', 'APPROVED')->whereHas('booking', fn ($q) => $q->whereColumn('bookings.branch_id', 'branches.id')), 'public_rating')->withCount('bookings');
        if ($request->filled('area')) $this->filterArea($query, (string) $request->input('area'));
        if ($request->filled('search')) $query->where('name', 'like', '%'.$request->string('search').'%');
        if ($request->filled('minRating')) $query->having('public_rating', '>=', (float) $request->input('minRating'));
        $sort = $request->input('sort');
        if ($sort === 'rating') $query->orderByDesc('public_rating');
        elseif ($sort === 'popular') $query->orderByDesc('bookings_count');
        else $query->orderByDesc('published_at');
        return $this->scope->paginate($query->orderByDesc('branches.id'), $request, fn ($b) => $this->presenter->branch($b));
    }

    public function branch(string $id) { return $this->presenter->branch(Branch::publicReady()->findOrFail($id), true); }

    public function districts()
    {
        return app(\App\Services\Beautybook\SourceDistrictCatalog::class)->all();
    }

    public function services(Request $request)
    {
        $request->validate(['minPrice' => 'nullable|numeric|min:0', 'maxPrice' => 'nullable|numeric|min:0', 'minRating' => 'nullable|numeric|between:0,5', 'sort' => 'nullable|in:rating,popular,price_asc,price_desc', 'serviceQuery' => 'nullable|string|max:120', 'query' => 'nullable|string|max:120', 'area' => 'nullable|string|max:120', 'location' => 'nullable|string|max:120', 'date' => 'nullable|date_format:Y-m-d']);
        $request->mergeIfMissing(['limit' => $request->is('api/v1/services/search') ? 12 : 100]);
        $search = $request->is('api/v1/services/search');
        if ($search) $request->merge(['limit' => max(1, min(50, (int) $request->input('limit', 12)))]);
        $query = $this->offerings($search)->with('service.category', 'branch.business', 'variants')->select('branch_service_offerings.*')
            ->selectSub(Review::selectRaw('COALESCE(AVG(overall_rating),0)')->where('status', 'APPROVED')->whereHas('booking.services', fn ($q) => $q->whereColumn('branch_service_offering_id', 'branch_service_offerings.id')), 'public_rating')
            ->selectSub(DB::table('booking_services')->selectRaw('COUNT(*)')->whereColumn('branch_service_offering_id', 'branch_service_offerings.id'), 'booking_count');
        if ($request->filled('branchId')) $query->where('branch_id', $request->input('branchId'));
        if ($request->filled('categoryId')) $query->whereHas('service', fn ($q) => $q->where('service_category_id', $request->input('categoryId')));
        if ($request->filled('canonicalServiceId')) {
            $canonical = CanonicalService::find($request->input('canonicalServiceId'));
            abort_unless($canonical, 400, 'Loại dịch vụ không tồn tại');
            $resolvedId = $canonical->status === 'MERGED' ? $canonical->replacement_canonical_id : $canonical->id;
            $ids = CanonicalService::whereKey($resolvedId)->orWhere('replacement_canonical_id', $resolvedId)->pluck('id');
            $query->whereHas('service', fn ($q) => $q->whereIn('canonical_service_id', $ids));
        }
        $needle = trim((string) $request->input('query', $request->input('serviceQuery', $request->input('search', ''))));
        if ($needle !== '') $query->whereHas('service', function ($q) use ($needle) {
            $pattern = '%'.$needle.'%';
            $q->where(function ($service) use ($pattern) {
                $service->where('name', 'like', $pattern)->orWhere('description', 'like', $pattern)->orWhereRaw("JSON_SEARCH(keywords, 'one', ?) IS NOT NULL", [$pattern])->orWhereHas('category', fn ($category) => $category->where('name', 'like', $pattern))->orWhereHas('canonicalService', function ($canonical) use ($pattern) {
                    $matches = fn ($row) => $row->where('name', 'like', $pattern)->orWhereRaw("JSON_SEARCH(synonyms, 'one', ?) IS NOT NULL", [$pattern]);
                    $canonical->where($matches)->orWhereHas('replacement', $matches)->orWhereIn('id', CanonicalService::where('status', 'MERGED')->where($matches)->whereNotNull('replacement_canonical_id')->select('replacement_canonical_id'));
                });
            });
        });
        $rank = $this->searchRank($needle);
        $query->selectSub($rank, 'search_rank');
        if ($request->filled('area')) $query->whereHas('branch', fn ($q) => $this->filterArea($q, (string) $request->input('area')));
        if ($request->filled('location')) $query->whereHas('branch', fn ($q) => $this->filterArea($q, (string) $request->input('location')));
        if ($request->filled('date')) $query->whereHas('branch.workingHours', fn ($q) => $q->where('day_of_week', \Carbon\CarbonImmutable::parse($request->input('date'))->dayOfWeek)->where('is_closed', false));
        foreach (['minPrice' => '>=', 'maxPrice' => '<='] as $key => $operator) if ($request->filled($key)) $query->where('price', $operator, $request->input($key));
        $sort = $request->input('sort');
        if (in_array($sort, ['price_asc', 'price_desc'], true)) $query->orderBy('price', $sort === 'price_asc' ? 'asc' : 'desc')->orderByDesc('search_rank');
        elseif ($sort === 'rating') $query->orderByDesc('public_rating')->orderByDesc('search_rank');
        else $query->orderByDesc('search_rank')->orderByDesc('public_rating')->orderByDesc('updated_at');
        if ($request->filled('minRating')) $query->having('public_rating', '>=', (float) $request->input('minRating'));
        $result = $this->scope->paginate($query->orderByDesc('branch_service_offerings.id'), $request, fn ($o) => $this->presenter->service($o));
        $result['hasMore'] = $result['page'] < $result['totalPages'];
        return $result;
    }

    public function service(string $id) { return $this->presenter->service($this->offerings()->findOrFail($id), true); }
    public function variants(string $id) { return $this->offerings()->findOrFail($id)->variants()->where('status', 'ACTIVE')->get()->map(fn ($v) => $this->presenter->variant($v)); }

    public function staff(Request $request)
    {
        abort_unless($request->filled('branchId'),400,'branchId là bắt buộc');
        Branch::publicReady()->findOrFail($request->input('branchId'));
        $query = StaffProfile::publicBookable()->whereHas('branch', fn ($q) => $q->publicReady());
        if ($request->filled('branchId')) $query->where('branch_id', $request->input('branchId'));
        foreach (array_filter(explode(',', (string) $request->input('serviceIds', ''))) as $id) $query->whereHas('offerings', fn ($q) => $q->where('branch_service_offerings.id', $id)->where('is_active', true)->where('bookable', true));
        return $query->orderBy('full_name')->get()->map(fn ($s) => $this->presenter->staff($s));
    }
    public function staffDetail(string $id) { return $this->presenter->staff(StaffProfile::publicBookable()->whereHas('branch', fn ($q) => $q->publicReady())->findOrFail($id), true); }

    public function businessReviews(Request $request, string $id)
    {
        $business = Business::publicReady()->findOrFail($id);
        return app(\App\Http\Controllers\EngagementController::class)->publicReviews($request, $business);
    }
    public function staffReviews(string $id) { return app(\App\Http\Controllers\EngagementController::class)->staffSummary(StaffProfile::findOrFail($id)); }
    public function serviceReviews(string $id): array
    {
        $offering = $this->offerings()->findOrFail($id);
        $itemIds = \App\Models\BookingService::where('branch_service_offering_id', $offering->id)->pluck('id')->map(fn ($value) => (int) $value)->all();
        $ratings = collect();
        Review::where('status', 'APPROVED')->whereHas('booking.services', fn ($q) => $q->where('branch_service_offering_id', $offering->id))->with('customerProfile.user')->latest()->get()->each(function ($review) use ($itemIds, &$ratings) {
            foreach ($review->service_ratings ?? [] as $row) {
                if (! in_array((int) ($row['booking_service_id'] ?? 0), $itemIds, true)) continue;
                $staff = isset($row['staff_profile_id']) ? StaffProfile::publicBookable()->find($row['staff_profile_id']) : null;
                $ratings->push(['rating' => $row['rating'], 'comment' => $row['comment'] ?? null, 'createdAt' => $review->created_at?->toIso8601String(), 'staff' => $staff ? ['id' => (string) $staff->id, 'fullName' => $staff->full_name] : null, 'customerName' => $review->is_anonymous ? 'Ẩn danh' : ($review->customerProfile?->user?->name ?? 'Ẩn danh')]);
            }
        });
        return ['averageRating' => round($ratings->avg('rating') ?? 0, 1), 'totalRatings' => $ratings->count(), 'ratings' => $ratings->take(50)->values()->all()];
    }

    private function filterArea($query, string $area): void
    {
        $districtIds = collect(app(\App\Services\Beautybook\SourceDistrictCatalog::class)->all())->filter(fn ($district) => mb_stripos($district['name'].' '.$district['province']['name'], $area) !== false)->pluck('id')->all();
        $query->where(function ($branch) use ($area, $districtIds) {
            $branch->where('name', 'like', '%'.$area.'%')->orWhere('address_line', 'like', '%'.$area.'%')->orWhereExists(function ($metadata) use ($area, $districtIds) {
                $metadata->select(DB::raw(1))->from('beautybook_branch_settings')->whereColumn('beautybook_branch_settings.branch_id', 'branches.id')->where(function ($location) use ($area, $districtIds) {
                    $location->where('metadata->district->name', 'like', '%'.$area.'%')->orWhere('metadata->district->provinceName', 'like', '%'.$area.'%');
                    if ($districtIds) $location->orWhereIn('metadata->districtId', $districtIds);
                });
            });
        });
    }

    private function searchRank(string $needle)
    {
        $cases = [
            ['LOWER(s.name) = LOWER(?)', $needle, 100],
            ['LOWER(resolved.name) = LOWER(?)', $needle, 90],
            ['LOWER(c.name) = LOWER(?)', $needle, 85],
            ["EXISTS (SELECT 1 FROM canonical_services alias WHERE alias.replacement_canonical_id = resolved.id AND alias.status = 'MERGED' AND LOWER(alias.name) = LOWER(?))", $needle, 82],
            ["JSON_SEARCH(LOWER(resolved.synonyms), 'one', LOWER(?)) IS NOT NULL", $needle, 80],
            ["JSON_SEARCH(LOWER(c.synonyms), 'one', LOWER(?)) IS NOT NULL", $needle, 78],
            ['LOWER(s.name) LIKE LOWER(?)', '%'.$needle.'%', 70],
            ['LOWER(resolved.name) LIKE LOWER(?)', '%'.$needle.'%', 60],
            ['LOWER(c.name) LIKE LOWER(?)', '%'.$needle.'%', 58],
            ["JSON_SEARCH(LOWER(resolved.synonyms), 'one', LOWER(?)) IS NOT NULL", '%'.$needle.'%', 55],
            ["JSON_SEARCH(LOWER(c.synonyms), 'one', LOWER(?)) IS NOT NULL", '%'.$needle.'%', 53],
            ['LOWER(s.description) LIKE LOWER(?)', '%'.$needle.'%', 40],
        ];
        $sql = 'CASE WHEN ? = ? THEN 0'; $bindings = [$needle, ''];
        foreach ($cases as [$condition, $value, $score]) { $sql .= ' WHEN '.$condition.' THEN '.$score; $bindings[] = $value; }
        $sql .= ' ELSE 30 END';
        return DB::table('business_services AS s')->leftJoin('canonical_services AS c', 'c.id', '=', 's.canonical_service_id')->leftJoin('canonical_services AS resolved', 'resolved.id', '=', DB::raw('COALESCE(c.replacement_canonical_id, c.id)'))->whereColumn('s.id', 'branch_service_offerings.business_service_id')->selectRaw($sql, $bindings);
    }
}
