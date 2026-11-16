<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\BookingService;
use App\Models\StaffProfile;
use App\Services\Beautybook\ApiScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owner-only reads; inference and queue mutation never run in these requests. */
class AbsaReadService
{
    public const STATUSES = ['ANALYZED', 'PROCESSING', 'FAILED', 'NOT_ANALYZED', 'NO_TEXT'];
    private const COLUMNS = ['SERVICE_QUALITY' => 'service_quality', 'STAFF_ATTITUDE' => 'staff_attitude', 'WAIT_TIME' => 'wait_time', 'PRICE' => 'price'];

    public function __construct(private ApiScope $scope) {}

    private function authorize(Request $request, string $branchId): array
    {
        // ApiScope::owner may select another grant; require the current role too.
        $current = app(\App\Services\Identity\SessionContext::class)->current($request);
        abort_unless($current, 403);
        abort_unless($current->role_name === 'BUSINESS_OWNER' && $current->branch_id === null, 403);
        $business = $this->scope->owner($request);
        abort_unless($business->ownerProfile()->where('user_id', $request->user()->id)->exists(), 403);
        $branch = $this->scope->branch($request, $branchId);
        abort_unless((string) $branch->business_id === (string) $business->id, 403);

        return ['businessId' => (string) $business->id, 'branchId' => (string) $branch->id];
    }

    private function status(): string
    {
        $max = max(1, (int) config('absa.max_attempts', 5));

        return "(CASE WHEN TRIM(COALESCE(r.comment, '')) = '' THEN 'NO_TEXT' WHEN a.id IS NULL THEN 'NOT_ANALYZED'
            WHEN a.status = 'COMPLETED' THEN 'ANALYZED'
            WHEN a.status = 'FAILED' AND NOT (a.error_retryable = 1 AND a.attempts < {$max}) THEN 'FAILED' ELSE 'PROCESSING' END)";
    }

    private function base(array $q, array $scope, bool $ready): Builder
    {
        $query = DB::table('reviews as r')->join('bookings as b', 'b.id', '=', 'r.booking_id')
            ->whereNull('r.deleted_at')->whereNull('b.deleted_at')->where('b.status', 'COMPLETED')
            ->where('b.branch_id', $scope['branchId'])->where('b.business_id', $scope['businessId']);
        // ImportContext stores source timestamps as UTC; native Eloquent reviews use app-local wall time.
        // DISTINCT prevents duplicate archive mappings from multiplying review counts or page rows.
        if (Schema::hasTable('glowme_import_ids')) {
            $imports = DB::table('glowme_import_ids')->where('source_table', 'public.reviews')->select('local_id')->distinct();
            $query->leftJoinSub($imports, 'review_import', 'review_import.local_id', '=', 'r.id');
        } else {
            $query->leftJoin(DB::raw('(SELECT NULL AS local_id) as review_import'), 'review_import.local_id', '=', 'r.id');
        }
        if ($ready) {
            $query->leftJoin('review_absa_analyses as a', fn ($join) => $join->on('a.review_id', '=', 'r.id')
                ->where('a.model_id', AbsaContract::MODEL['id'])->where('a.checkpoint_sha256', AbsaContract::MODEL['checkpointSha256']));
        } else {
            $columns = ['id', 'review_id', 'status', 'error_retryable', 'attempts', 'probabilities', 'completed_at', 'error_code', 'text_sha256', ...array_values(self::COLUMNS)];
            $empty = '(SELECT '.implode(', ', array_map(fn ($c) => "NULL AS {$c}", $columns)).') as a';
            $query->leftJoin(DB::raw($empty), 'a.review_id', '=', 'r.id');
        }
        if (isset($q['rating'])) $query->where('r.overall_rating', $q['rating']);
        if (isset($q['visibility'])) {
            if ($q['visibility'] === 'PUBLIC') $query->where('r.status', 'APPROVED');
            else $query->where('r.status', '<>', 'APPROVED');
        }
        // Compare each storage basis with its own bounds, preserving inclusive Vietnam calendar dates.
        $bounds = [];
        if (isset($q['from'])) $bounds[] = ['>=', CarbonImmutable::createFromFormat('!Y-m-d', $q['from'], 'Asia/Ho_Chi_Minh')];
        if (isset($q['to'])) $bounds[] = ['<', CarbonImmutable::createFromFormat('!Y-m-d', $q['to'], 'Asia/Ho_Chi_Minh')->addDay()];
        if ($bounds) $query->where(function ($where) use ($bounds) {
            foreach ([false, true] as $imported) $where->orWhere(function ($range) use ($bounds, $imported) {
                if ($imported) $range->whereNotNull('review_import.local_id');
                else $range->whereNull('review_import.local_id');
                $timezone = $imported ? 'UTC' : config('app.timezone', 'UTC');
                foreach ($bounds as [$operator, $date]) $range->where('r.created_at', $operator, $date->setTimezone($timezone)->format('Y-m-d H:i:s'));
            });
        });

        return $query;
    }

    public function summary(Request $request, array $q): array
    {
        return DB::transaction(function () use ($request, $q) {
            $scope = $this->authorize($request, (string) $q['branchId']);
            $ready = Schema::hasTable('review_absa_analyses');
            $query = $this->base($q, $scope, $ready);
            $status = $this->status();
            $analyzed = "{$status} = 'ANALYZED'";
            $count = fn ($condition, $alias) => "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$alias}";
            $select = ['COUNT(*) AS reviews', 'AVG(r.overall_rating) AS rating_average'];
            foreach (self::STATUSES as $state) $select[] = $count("{$status} = '{$state}'", strtolower($state));
            foreach (['POS' => 'with_positive', 'NEG' => 'with_negative'] as $label => $alias) {
                $any = implode(' OR ', array_map(fn ($c) => "a.{$c} = '{$label}'", self::COLUMNS));
                $select[] = $count("{$analyzed} AND ({$any})", $alias);
            }
            $none = implode(' AND ', array_map(fn ($c) => "a.{$c} = 'NOT_MENTIONED'", self::COLUMNS));
            $select[] = $count("{$analyzed} AND {$none}", 'no_aspect');
            foreach (self::COLUMNS as $aspect => $column) foreach (AbsaContract::LABELS as $label) {
                $select[] = $count("{$analyzed} AND a.{$column} = '{$label}'", strtolower($aspect.'_'.$label));
            }
            $row = $query->selectRaw(implode(', ', $select))->first();
            $aspects = [];
            foreach (AbsaContract::ASPECTS as $aspect) {
                $counts = [];
                foreach (AbsaContract::LABELS as $label) $counts[$label] = (int) $row->{strtolower($aspect.'_'.$label)};
                $mentioned = $counts['POS'] + $counts['NEG'] + $counts['NEU'];
                $aspects[] = ['aspect' => $aspect, 'counts' => $counts, 'mentioned' => $mentioned,
                    'positiveShare' => $mentioned ? $counts['POS'] / $mentioned : null,
                    'negativeShare' => $mentioned ? $counts['NEG'] / $mentioned : null,
                    'neutralShare' => $mentioned ? $counts['NEU'] / $mentioned : null];
            }

            return ['scope' => $scope, 'filters' => ['from' => $q['from'] ?? null, 'to' => $q['to'] ?? null,
                'rating' => isset($q['rating']) ? (int) $q['rating'] : null, 'visibility' => $q['visibility'] ?? null],
                'store' => ['ready' => $ready], 'model' => [...AbsaContract::MODEL, 'kind' => 'AI_PREDICTION', 'validation' => 'SYNTHETIC_TEST_ONLY'],
                'totals' => ['reviews' => (int) $row->reviews, 'withText' => (int) $row->reviews - (int) $row->no_text,
                    'noText' => (int) $row->no_text, 'analyzed' => (int) $row->analyzed, 'processing' => (int) $row->processing,
                    'failed' => (int) $row->failed, 'notAnalyzed' => (int) $row->not_analyzed,
                    'ratingAverage' => $row->rating_average === null ? null : (float) $row->rating_average],
                'sentimentReviews' => ['withPositive' => (int) $row->with_positive, 'withNegative' => (int) $row->with_negative,
                    'noAspect' => (int) $row->no_aspect, 'denominator' => (int) $row->analyzed], 'aspects' => $aspects,
                'definitions' => [
                    'aspectCounts' => 'Per aspect, over AI-analysed reviews only; one review can mention several aspects. NOT_MENTIONED is not negative.',
                    'shares' => 'Shares use the reviews that mention that aspect (POS+NEG+NEU) as the denominator.',
                    'sentimentReviews' => 'Reviews with at least one positive / negative aspect, over analysed reviews; a review can be in both.',
                ]];
        });
    }

    public function reviews(Request $request, array $q): array
    {
        return DB::transaction(function () use ($request, $q) {
            $scope = $this->authorize($request, (string) $q['branchId']);
            $ready = Schema::hasTable('review_absa_analyses');
            $query = $this->base($q, $scope, $ready);
            $status = $this->status();
            if (isset($q['status'])) $query->whereRaw("{$status} = ?", [$q['status']]);
            if (isset($q['aspect']) || isset($q['sentiment'])) {
                $query->where('a.status', 'COMPLETED')->where(function ($where) use ($q) {
                    $columns = isset($q['aspect']) ? [self::COLUMNS[$q['aspect']]] : array_values(self::COLUMNS);
                    foreach ($columns as $column) {
                        if (isset($q['sentiment'])) $where->orWhere('a.'.$column, $q['sentiment']);
                        else $where->orWhere('a.'.$column, '<>', 'NOT_MENTIONED');
                    }
                });
            }
            $total = (clone $query)->count();
            $page = (int) ($q['page'] ?? 1); $limit = (int) ($q['limit'] ?? 10);
            $rows = $query->select('r.id', 'a.attempts', 'a.error_code', 'a.text_sha256', 'a.completed_at', 'a.probabilities',
                ...array_map(fn ($c) => 'a.'.$c, array_values(self::COLUMNS)))
                ->selectRaw("{$status} AS absa_status, CASE WHEN review_import.local_id IS NULL THEN 0 ELSE 1 END AS is_imported")
                ->orderByRaw('CASE WHEN review_import.local_id IS NOT NULL THEN r.created_at ELSE DATE_SUB(r.created_at, INTERVAL ? MINUTE) END DESC', [(int) CarbonImmutable::now(config('app.timezone', 'UTC'))->utcOffset()])->orderByDesc('r.id')
                ->offset(($page - 1) * $limit)->limit($limit)->get();
            $reviews = Review::with(['customerProfile.user', 'photos'])->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
            // Bound related metadata to the selected page; do not read every review in PHP.
            $staffIds = $reviews->flatMap(fn ($r) => array_column($r->service_ratings ?? [], 'staff_profile_id'))->filter()->unique();
            $staff = StaffProfile::withTrashed()->whereIn('id', $staffIds)->get()->keyBy('id');
            $itemIds = $reviews->flatMap(fn ($r) => array_column($r->service_ratings ?? [], 'booking_service_id'))->filter()->unique();
            $services = BookingService::whereIn('id', $itemIds)->whereIn('booking_id', $reviews->pluck('booking_id'))->get()->keyBy('id');
            $items = $rows->map(function ($row) use ($reviews, $staff, $services) {
                $review = $reviews[$row->id];
                $labels = null;
                if ($row->absa_status === 'ANALYZED') foreach (self::COLUMNS as $aspect => $column) $labels[$aspect] = $row->{$column};
                return ['id' => (string) $review->id, 'bookingId' => (string) $review->booking_id,
                    'rating' => (int) $review->overall_rating, 'comment' => $review->comment,
                    'createdAt' => CarbonImmutable::parse($review->getRawOriginal('created_at'), $row->is_imported ? 'UTC' : config('app.timezone', 'UTC'))->toIso8601String(), 'visibility' => $review->status === 'APPROVED' ? 'PUBLIC' : 'PRIVATE',
                    'customerName' => $review->is_anonymous ? 'Ẩn danh' : ($review->customerProfile?->user?->name ?? 'Khách hàng'),
                    'photos' => $review->photoData(),
                    'itemRatings' => array_map(function ($item) use ($staff, $services, $review) {
                        $person = $staff[$item['staff_profile_id'] ?? null] ?? null;
                        $service = $services[$item['booking_service_id'] ?? null] ?? null;
                        if ($service && (string) $service->booking_id !== (string) $review->booking_id) $service = null;
                        return ['rating' => (int) $item['rating'], 'comment' => $item['comment'] ?? null,
                            'staff' => $person ? ['id' => (string) $person->id, 'name' => $person->full_name] : null,
                            'service' => ['serviceId' => isset($item['business_service_id']) ? (string) $item['business_service_id'] : null,
                                'serviceNameSnapshot' => $item['service_name'] ?? $service?->service_name_snapshot ?? 'Dịch vụ',
                                'variantNameSnapshot' => $item['variant_name'] ?? $service?->variant_name_snapshot,
                                'comboNameSnapshot' => $item['combo_name'] ?? null]];
                    }, $review->service_ratings ?? []),
                    'absa' => ['status' => $row->absa_status, 'labels' => $labels,
                        'probabilities' => $labels ? json_decode($row->probabilities ?? 'null', true) : null,
                        'analyzedAt' => $labels && $row->completed_at ? CarbonImmutable::parse($row->completed_at, 'UTC')->toIso8601String() : null,
                        'attempts' => $row->attempts === null ? null : (int) $row->attempts,
                        'errorCode' => $row->absa_status === 'FAILED' ? $row->error_code : null,
                        'stale' => (bool) ($labels && $review->comment && $row->text_sha256 !== hash('sha256', $review->comment))]];
            })->all();

            return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit, 'store' => ['ready' => $ready]];
        });
    }
}
