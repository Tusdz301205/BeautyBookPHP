<?php

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\Promotion;
use App\Services\Promotions\PromotionPricingService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'beautybook_php' || ! $app->environment('local')) {
    throw new RuntimeException('Expected local PHP database.');
}
$ids = DB::table('glowme_import_ids')->where('source_table', 'public.promotions')->pluck('local_id', 'source_id');
$customer = CustomerProfile::firstOrFail();
$report = ['coded_programs' => 0, 'source_fields_match' => 0, 'valid_in_original_window' => 0, 'source_inactive' => 0, 'currently_in_window' => 0, 'errors' => []];
try {
    foreach (DB::table('glowme_import_rows')->where('source_table', 'public.promotions')->get() as $raw) {
        $source = json_decode($raw->payload, true, 512, JSON_THROW_ON_ERROR);
        if ($source['code'] === null) {
            continue;
        }
        $report['coded_programs']++;
        $promotion = Promotion::withTrashed()->findOrFail($ids[$source['id']]);
        $expected = $source['active'] && ! $source['deleted_at'] ? 'ACTIVE' : 'INACTIVE';
        if ($promotion->code !== strtoupper(trim($source['code'])) || $promotion->status !== $expected || (bool) $promotion->auto_apply !== $source['auto_apply'] || $promotion->target_mode !== $source['target_mode']) {
            $report['errors'][] = ['id' => $promotion->id, 'kind' => 'source-field-mismatch'];

            continue;
        }
        $report['source_fields_match']++;
        Carbon::setTestNow();
        if ($expected === 'ACTIVE' && ! $promotion->start_date->isFuture() && $promotion->end_date->isFuture()) {
            $report['currently_in_window']++;
        }
        if ($expected !== 'ACTIVE') {
            $report['source_inactive']++;

            continue;
        }
        // Read-only probe in the original validity window; do not extend dates or alter live eligibility.
        $midpoint = intdiv($promotion->start_date->getTimestamp() + $promotion->end_date->getTimestamp(), 2);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($midpoint));
        $branch = Branch::where('business_id', $promotion->business_id)->when($promotion->branch_ids, fn ($q) => $q->whereIn('id', $promotion->branch_ids))
            ->when($promotion->target_mode !== 'SELECTED', fn ($query) => $query->whereHas('offerings'))
            ->when($promotion->target_mode === 'SELECTED', fn ($query) => $query->where(function ($targets) use ($promotion) {
                $targets->whereHas('offerings', fn ($offerings) => $offerings->whereIn('id', $promotion->service_ids ?? []));
                if ($promotion->combo_ids) {
                    $targets->orWhereIn('id', DB::table('combos')->whereIn('id', $promotion->combo_ids)->select('branch_id'));
                }
            }))->firstOrFail();
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->when($promotion->target_mode === 'SELECTED' && $promotion->service_ids, fn ($q) => $q->whereIn('id', $promotion->service_ids))->first();
        $combo = null;
        if ((! $offerings || ($promotion->target_mode === 'SELECTED' && ! $promotion->service_ids)) && $promotion->combo_ids) {
            $combo = DB::table('combos')->where('branch_id', $branch->id)->whereIn('id', $promotion->combo_ids)->first();
        }
        try {
            if ($combo) {
                $items = DB::table('combo_items')->where('combo_id', $combo->id)->orderBy('sort_order')->get();
                $lines = $items->map(fn ($item) => ['branch_service_offering_id' => $item->branch_service_offering_id, 'allocated_amount' => bcmul($item->price_snapshot, (string) $item->quantity, 2)])->all();
                $quote = app(PromotionPricingService::class)->quoteCombo($branch, $combo->id, $lines, $customer->id, strtolower($promotion->code));
            } else {
                if (! $offerings) {
                    throw new RuntimeException('No matching imported offering.');
                }
                $quote = app(PromotionPricingService::class)->quote($branch, $offerings->id, (string) $offerings->price, $customer->id, strtolower($promotion->code));
            }
            if (! ($quote['promotion_code_valid'] ?? false)) {
                throw new RuntimeException('Code not recognized.');
            }
            $report['valid_in_original_window']++;
        } catch (Throwable $error) {
            $report['errors'][] = ['id' => $promotion->id, 'kind' => 'preview-rejected', 'reason' => $error instanceof DomainException || $error instanceof RuntimeException && ! $error instanceof QueryException ? $error->getMessage() : get_class($error)];
        }
    }
} finally {
    Carbon::setTestNow();
}
$report['passed'] = $report['coded_programs'] === $report['source_fields_match'] && $report['coded_programs'] === $report['valid_in_original_window'] + $report['source_inactive'] && ! $report['errors'];
file_put_contents(storage_path('app/private/glowme-import/promotion-codes-verification.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['passed'] ? 0 : 1);
