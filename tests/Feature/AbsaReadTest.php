<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\AbsaContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AbsaReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\IdentityDemoSeeder::class);
        config(['absa.max_attempts' => 5]);
    }

    public function test_summary_counts_mentions_without_mixing_models_or_deleted_reviews(): void
    {
        $positive = $this->review();
        $this->analysis($positive, ['service_quality' => 'POS', 'price' => 'NEG']);
        $this->analysis($positive, ['model_id' => 'old-model', 'service_quality' => 'NEG']);
        $this->review(['comment' => '   ', 'overall_rating' => 3]);
        $this->review(['overall_rating' => 4]);
        $deleted = $this->review(); $this->analysis($deleted); $deleted->delete();
        $pendingBooking = $this->review(); $pendingBooking->booking->update(['status' => 'CONFIRMED']);
        $this->review([], 'vo-van-tan');

        $this->actor('owner');
        $response = $this->getJson($this->url('summary'))->assertOk()
            ->assertJsonPath('scope.branchId', (string) $positive->booking->branch_id)
            ->assertJsonPath('totals.reviews', 3)->assertJsonPath('totals.withText', 2)
            ->assertJsonPath('totals.noText', 1)->assertJsonPath('totals.analyzed', 1)
            ->assertJsonPath('totals.notAnalyzed', 1)->assertJsonPath('totals.ratingAverage', 4)
            ->assertJsonPath('sentimentReviews.withPositive', 1)->assertJsonPath('sentimentReviews.withNegative', 1)
            ->assertJsonPath('sentimentReviews.denominator', 1)->assertJsonPath('aspects.0.counts.POS', 1)
            ->assertJsonPath('aspects.0.mentioned', 1)->assertJsonPath('aspects.0.positiveShare', 1)
            ->assertJsonPath('aspects.1.counts.NOT_MENTIONED', 1)->assertJsonPath('aspects.1.positiveShare', null);
        $this->assertSame(AbsaContract::MODEL['checkpointSha256'], $response->json('model.checkpointSha256'));
    }

    public function test_list_status_retry_budget_staleness_and_model_pin(): void
    {
        $analyzed = $this->review(['is_anonymous' => true, 'service_ratings' => [
            ['booking_service_id' => 123, 'service_name' => 'Cắt tóc', 'rating' => 4, 'comment' => 'Tốt'],
        ]]);
        $this->analysis($analyzed, ['service_quality' => 'POS', 'text_sha256' => str_repeat('0', 64)]);
        $retry = $this->review(); $this->analysis($retry, ['status' => 'FAILED', 'attempts' => 4, 'error_retryable' => true]);
        $failed = $this->review(); $this->analysis($failed, ['status' => 'FAILED', 'attempts' => 5, 'error_retryable' => true, 'error_code' => 'TIMEOUT']);
        $terminal = $this->review(); $this->analysis($terminal, ['status' => 'FAILED', 'attempts' => 1, 'error_retryable' => false, 'error_code' => 'INVALID_TYPE']);
        $missing = $this->review(); $this->analysis($missing, ['checkpoint_sha256' => str_repeat('1', 64)]);
        $blank = $this->review(['comment' => '']); $this->analysis($blank);
        $this->actor('owner');
        $items = $this->getJson($this->url('reviews'))->assertOk()->assertJsonPath('total', 6)->json('items');
        $byId = collect($items)->keyBy('id');
        $this->assertSame('ANALYZED', $byId[$analyzed->id]['absa']['status']);
        $this->assertTrue($byId[$analyzed->id]['absa']['stale']);
        $this->assertSame('Ẩn danh', $byId[$analyzed->id]['customerName']);
        $this->assertSame('Cắt tóc', $byId[$analyzed->id]['itemRatings'][0]['service']['serviceNameSnapshot']);
        $this->assertSame('PROCESSING', $byId[$retry->id]['absa']['status']);
        $this->assertNull($byId[$retry->id]['absa']['errorCode']);
        $this->assertSame('TIMEOUT', $byId[$failed->id]['absa']['errorCode']);
        $this->assertSame('FAILED', $byId[$terminal->id]['absa']['status']);
        $this->assertSame('NOT_ANALYZED', $byId[$missing->id]['absa']['status']);
        $this->assertNull($byId[$missing->id]['absa']['labels']);
        $this->assertSame('NO_TEXT', $byId[$blank->id]['absa']['status']);
        $this->assertNull($byId[$blank->id]['absa']['labels']);
        $this->getJson($this->url('summary'))->assertOk()->assertJsonPath('totals.analyzed', 1)
            ->assertJsonPath('totals.processing', 1)->assertJsonPath('totals.failed', 2)->assertJsonPath('totals.noText', 1);
    }

    public function test_vietnam_dates_are_inclusive_and_list_filters_and_pagination_run_in_sql(): void
    {
        // These native fixtures intentionally use a UTC application storage basis.
        config(['app.timezone' => 'UTC']);
        $start = $this->review(['created_at' => '2026-10-31 17:00:00', 'overall_rating' => 2, 'status' => 'HIDDEN']);
        $end = $this->review(['created_at' => '2026-11-01 16:59:59', 'overall_rating' => 2, 'status' => 'REPORTED']);
        foreach ([$start, $end] as $review) $this->analysis($review, ['price' => 'NEG']);
        $this->review(['created_at' => '2026-10-31 16:59:59']);
        $this->review(['created_at' => '2026-11-01 17:00:00']);
        $this->actor('owner');
        $filters = ['from' => '2026-11-01', 'to' => '2026-11-01', 'rating' => 2, 'visibility' => 'PRIVATE'];
        $this->getJson($this->url('summary', $filters))->assertOk()->assertJsonPath('totals.reviews', 2);
        $filters += ['aspect' => 'PRICE', 'sentiment' => 'NEG', 'status' => 'ANALYZED', 'limit' => 1];
        $this->getJson($this->url('reviews', $filters))->assertOk()->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', (string) $end->id)->assertJsonPath('items.0.visibility', 'PRIVATE');
        $this->getJson($this->url('reviews', $filters + ['page' => 2]))->assertOk()->assertJsonPath('items.0.id', (string) $start->id);
        $this->getJson($this->url('reviews', $filters + ['page' => 3]))->assertOk()->assertJsonPath('total', 2)->assertJsonCount(0, 'items');
        $this->getJson($this->url('reviews', ['aspect' => 'STAFF_ATTITUDE']))->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->url('reviews', ['sentiment' => 'NEG']))->assertOk()->assertJsonPath('total', 2);
    }

    public function test_only_the_current_actual_business_owner_can_read(): void
    {
        foreach (['summary', 'reviews'] as $endpoint) {
            foreach (['customer', 'receptionist', 'staff', 'admin'] as $name) {
                $this->actor($name);
                $this->getJson($this->url($endpoint))->assertForbidden();
            }
            $this->actor('owner');
            $this->getJson($this->url($endpoint, ['branchId' => Branch::where('slug', 'vo-van-tan')->value('id')]))->assertForbidden();
            $this->withHeader('X-Business-Id', '999999')->getJson($this->url($endpoint))->assertForbidden();
            $this->flushHeaders();
        }
        $owner = $this->actor('owner');
        $business = Branch::where('slug', 'tan-tao')->firstOrFail()->business;
        $business->update(['owner_profile_id' => User::where('email', 'owner2@beautybook.local')->firstOrFail()->businessOwnerProfile->id]);
        $this->getJson($this->url('summary'))->assertForbidden();
        $this->getJson($this->url('reviews'))->assertForbidden();
        $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->update(['expires_at' => now()->subMinute()]);
        $this->getJson($this->url('summary'))->assertForbidden();
    }

    public function test_local_and_imported_review_dates_share_vietnam_boundaries_and_serialize_their_storage_basis(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $pairs = [
            ['2026-11-01 00:00:00', '2026-10-31 17:00:00'],
            ['2026-11-01 20:00:00', '2026-11-01 13:00:00'],
            ['2026-11-01 23:59:59', '2026-11-01 16:59:59'],
        ];
        $expected = [];
        foreach ($pairs as [$local, $utc]) {
            $native = $this->review(['created_at' => $local]);
            $imported = $this->review(['created_at' => $utc]);
            DB::table('glowme_import_ids')->insert([
                ['source_table' => 'public.reviews', 'source_id' => 'absa-review-'.$imported->id, 'local_id' => $imported->id],
                // Another source table with the same local ID must not classify a native review as imported.
                ['source_table' => 'public.bookings', 'source_id' => 'absa-booking-'.$native->id, 'local_id' => $native->id],
            ]);
            $expected[(string) $native->id] = str_replace(' ', 'T', $local).'+07:00';
            $expected[(string) $imported->id] = str_replace(' ', 'T', $utc).'+00:00';
        }
        // Multiple source mappings to one local review must not multiply SQL aggregates or pages.
        DB::table('glowme_import_ids')->insert(['source_table' => 'public.reviews', 'source_id' => 'absa-review-duplicate', 'local_id' => $imported->id]);
        foreach ([['2026-10-31 23:59:59', '2026-10-31 16:59:59'], ['2026-11-02 00:00:00', '2026-11-01 17:00:00']] as [$local, $utc]) {
            $this->review(['created_at' => $local]);
            $outside = $this->review(['created_at' => $utc]);
            DB::table('glowme_import_ids')->insert(['source_table' => 'public.reviews', 'source_id' => 'absa-review-'.$outside->id, 'local_id' => $outside->id]);
        }
        $this->analysis($native, ['completed_at' => '2026-11-02 01:00:00']);
        $this->actor('owner');
        $dates = ['from' => '2026-11-01', 'to' => '2026-11-01'];
        $this->getJson($this->url('summary', $dates))->assertOk()->assertJsonPath('totals.reviews', 6);
        $items = $this->getJson($this->url('reviews', $dates))->assertOk()->assertJsonPath('total', 6)->assertJsonCount(6, 'items')->json('items');
        $this->assertEqualsCanonicalizing(array_keys($expected), array_column($items, 'id'));
        foreach ($items as $item) $this->assertSame($expected[$item['id']], $item['createdAt']);
        $byId = collect($items)->keyBy('id');
        foreach (array_chunk(array_keys($expected), 2) as [$nativeId, $importedId]) {
            $this->assertSame(strtotime($byId[$nativeId]['createdAt']), strtotime($byId[$importedId]['createdAt']));
        }
        $this->assertSame('2026-11-02T01:00:00+00:00', $byId[$native->id]['absa']['analyzedAt']);
        foreach ([['from' => '2026-11-01'], ['to' => '2026-11-01']] as $range) {
            $this->getJson($this->url('summary', $range))->assertOk()->assertJsonPath('totals.reviews', 8);
            $this->getJson($this->url('reviews', $range))->assertOk()->assertJsonPath('total', 8);
        }
    }

    public function test_local_review_dates_work_without_the_import_mapping_table(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $review = $this->review(['created_at' => '2026-11-01 20:00:00']);
        $this->actor('owner');
        Schema::shouldReceive('hasTable')->with('glowme_import_ids')->andReturn(false);
        Schema::shouldReceive('hasTable')->with('review_absa_analyses')->andReturn(true);
        $dates = ['from' => '2026-11-01', 'to' => '2026-11-01'];
        $this->getJson($this->url('summary', $dates))->assertOk()->assertJsonPath('totals.reviews', 1);
        $this->getJson($this->url('reviews', $dates))->assertOk()->assertJsonPath('items.0.id', (string) $review->id)
            ->assertJsonPath('items.0.createdAt', '2026-11-01T20:00:00+07:00');
    }

    public function test_missing_store_keeps_reviews_readable_and_does_not_create_jobs(): void
    {
        $review = $this->review();
        $this->actor('owner');
        $before = DB::table('review_absa_analyses')->count();
        Schema::shouldReceive('hasTable')->with('review_absa_analyses')->andReturn(false);
        Schema::shouldReceive('hasTable')->with('glowme_import_ids')->andReturn(true);
        $this->getJson($this->url('summary'))->assertOk()->assertJsonPath('store.ready', false)->assertJsonPath('totals.notAnalyzed', 1);
        $this->getJson($this->url('reviews'))->assertOk()->assertJsonPath('items.0.id', (string) $review->id)
            ->assertJsonPath('items.0.absa.status', 'NOT_ANALYZED')->assertJsonPath('store.ready', false);
        $this->assertSame($before, DB::table('review_absa_analyses')->count());
    }
    public function test_imported_and_native_review_dates_are_paginated_in_chronological_order(): void
    {
        config(['app.timezone'=>'Asia/Ho_Chi_Minh']);
        $native=$this->review(['created_at'=>'2026-11-01 20:00:00']);
        $imported=$this->review(['created_at'=>'2026-11-01 13:30:00']);
        $latest=$this->review(['created_at'=>'2026-11-01 21:00:00']);
        DB::table('glowme_import_ids')->insert(['source_table'=>'public.reviews','source_id'=>'absa-sort-import','local_id'=>$imported->id]);
        $this->actor('owner');
        foreach ([$latest,$imported,$native] as $index=>$review) {
            $this->getJson($this->url('reviews',['page'=>$index+1,'limit'=>1]))->assertOk()
                ->assertJsonPath('total',3)->assertJsonPath('items.0.id',(string)$review->id);
        }
    }

    public function test_invalid_dates_closed_vocabularies_and_unbounded_pages_are_rejected(): void
    {
        $this->actor('owner');
        foreach ([['from' => '2026-02-30'], ['from' => '2026-11-02', 'to' => '2026-11-01'],
            ['rating' => 6], ['visibility' => 'HIDDEN'], ['aspect' => 'AMBIENCE'], ['sentiment' => 'MIXED'],
            ['status' => 'COMPLETED'], ['page' => 0], ['limit' => 51], ['branchId' => 'abc']] as $query) {
            $this->getJson($this->url('reviews', $query))->assertUnprocessable();
        }
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id,
            'beautybook_access_hash' => hash('sha256', 'absa-read-test'), 'beautybook_access_expires' => time() + 900])
            ->withHeader('Authorization', 'Bearer absa-read-test');
        return $user;
    }

    private function url(string $endpoint, array $query = []): string
    {
        return '/api/v1/reviews/manage/absa/'.$endpoint.'?'.http_build_query($query + ['branchId' => Branch::where('slug', 'tan-tao')->value('id')]);
    }

    private function review(array $attributes = [], string $slug = 'tan-tao'): Review
    {
        $branch = Branch::where('slug', $slug)->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'ABSA-'.uniqid(), 'status' => 'COMPLETED', 'appointment_start_at' => now()->subDay(),
            'appointment_end_at' => now()->subDay()->addHour(), 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000]);
        return Review::create($attributes + ['booking_id' => $booking->id, 'customer_profile_id' => $customer->customerProfile->id,
            'overall_rating' => 5, 'comment' => 'Dịch vụ tốt nhưng giá cao', 'status' => 'APPROVED']);
    }

    private function analysis(Review $review, array $attributes = []): void
    {
        DB::table('review_absa_analyses')->insert($attributes + [
            'review_id' => $review->id, 'model_id' => AbsaContract::MODEL['id'], 'checkpoint_sha256' => AbsaContract::MODEL['checkpointSha256'],
            'taxonomy_version' => AbsaContract::MODEL['taxonomyVersion'], 'preprocessing_version' => AbsaContract::MODEL['preprocessingVersion'],
            'status' => 'COMPLETED', 'source' => 'BACKFILL', 'attempts' => 1, 'error_retryable' => true,
            'service_quality' => 'NOT_MENTIONED', 'staff_attitude' => 'NOT_MENTIONED', 'wait_time' => 'NOT_MENTIONED', 'price' => 'NOT_MENTIONED',
            'probabilities' => '{}', 'text_sha256' => hash('sha256', $review->comment), 'completed_at' => now(),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
