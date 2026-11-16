<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Combo;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

/**
 * A recurring series quotes, holds and displays one deposit per occurrence, each from the price that occurrence will really
 * have (its own date and its own staff member), through the same pricing the booking itself uses. The series deposit is the
 * sum over the occurrences that can really be booked, and the quote of occurrence N always belongs to occurrence N.
 */
class RecurringDepositQuoteTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDirectDeposits();
    }

    private function branch(): Branch
    {
        return Branch::where('slug', 'tan-tao')->firstOrFail();
    }

    /** @return list<StaffProfile> the branch's bookable staff, every one able to do the first offerings */
    private function staffFor(int $services): array
    {
        $branch = $this->branch();
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->take($services)->get();
        $query = fn () => StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->where('is_bookable', true)->where('public_visible', true)->orderBy('id');
        if ($query()->count() < 2) {
            // The demo branch has one provider; a second one makes the "different staff, different price" cases possible.
            app(\App\Services\Staff\StaffManagementService::class)->create($branch->business, ['branch_id' => $branch->id, 'full_name' => 'Chuyên viên thứ hai', 'position' => 'Chuyên viên dịch vụ', 'employee_code' => 'DEMO-TAN-TAO-2',
                'status' => 'ACTIVE', 'is_bookable' => true, 'public_visible' => true, 'hired_at' => '2025-01-01']);
        }
        $staff = $query()->get();
        $this->assertGreaterThanOrEqual(2, $staff->count());
        foreach ($staff as $member) foreach ($offerings as $offering) $member->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $branch->business_id, 'branch_id' => $branch->id]]);

        return $staff->all();
    }

    private function series(int $count = 3, int $services = 1, array $extra = []): array
    {
        $this->staffFor($services);
        $branch = $this->branch();
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->take($services)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $first = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(10, 0);
        while ($first->dayOfWeek === 0) $first = $first->addDay();

        return array_merge(['branchId' => (string) $branch->id, 'serviceIds' => $offerings, 'staffMode' => 'ANY_AVAILABLE', 'frequency' => 'WEEKLY', 'startDate' => $first->toDateString(), 'preferredTime' => '10:00',
            'occurrenceCount' => $count, 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true], $extra);
    }

    private function preview(array $series): array
    {
        $this->actor('customer');

        return $this->postJson('/api/v1/recurring/preview', $series)->assertOk()->json();
    }

    private function createSeries(array $series, array $preview, string $key = 'series-quote-0001')
    {
        $this->actor('customer');
        $quotes = array_map(fn ($o) => $o['walletDeposit']['quote'] ?? null, $preview['occurrences']);

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/recurring', $series + ['walletDepositQuotes' => $quotes]);
    }

    /** A rule that adds `$amount` dong to an offering from `$from` on, optionally only for one staff member. */
    private function rule(int $offeringId, int $amount, ?CarbonImmutable $from = null, ?int $staffId = null): void
    {
        DB::table('service_price_rules')->insert(['branch_service_offering_id' => $offeringId, 'name' => 'Phụ thu '.$amount, 'priority' => 1, 'adjustment_type' => 'FIXED_AMOUNT', 'adjustment_value' => $amount.'.00',
            'conditions' => json_encode($staffId ? ['staffId' => (string) $staffId] : []), 'valid_from' => $from?->utc()->format('Y-m-d H:i:s'), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<array{0:string,1:int,2:int}> start, booking total and deposit, in occurrence order, of what was really created */
    private function stored(): array
    {
        return DB::table('bookings as b')->join('booking_deposits as d', 'd.booking_id', '=', 'b.id')->orderBy('b.appointment_start_at')->get(['b.appointment_start_at', 'b.total_amount', 'd.amount_vnd'])
            ->map(fn ($r) => [$r->appointment_start_at, (int) round((float) $r->total_amount), (int) $r->amount_vnd])->all();
    }

    private function occurrenceStart(array $series, int $index): CarbonImmutable
    {
        return CarbonImmutable::parse($series['startDate'].' '.$series['preferredTime'], 'Asia/Ho_Chi_Minh')->addWeeks($index);
    }

    /** The preview's deposit table must be exactly what a create with those quotes stores. */
    private function assertStoredMatches(array $preview): void
    {
        $bookable = array_values(array_filter($preview['occurrences'], fn ($o) => $o['available']));
        $stored = $this->stored();
        $this->assertCount(count($bookable), $stored);
        foreach ($bookable as $i => $occurrence) {
            $this->assertSame([(int) $occurrence['walletDeposit']['totalVnd'], (int) $occurrence['walletDeposit']['depositVnd']], [$stored[$i][1], $stored[$i][2]], 'occurrence '.($i + 1));
        }
        $this->assertSame((int) $preview['walletDepositTotalVnd'], array_sum(array_column($stored, 2)), 'the series deposit is the sum of what is held');
    }

    // ---- price by date ---------------------------------------------------------------------------------------------------------

    public function test_occurrences_whose_price_changes_by_date_each_get_their_own_quote_and_the_series_total_is_their_sum(): void
    {
        $this->configureLab();
        $series = $this->series(3);
        $offering = (int) $series['serviceIds'][0];
        $this->rule($offering, 40000, $this->occurrenceStart($series, 1)->subHour());

        $preview = $this->preview($series);
        $totals = array_map(fn ($o) => (int) $o['walletDeposit']['totalVnd'], $preview['occurrences']);
        $this->assertSame($totals[0] + 40000, $totals[1], json_encode($totals));
        $this->assertSame($totals[1], $totals[2]);
        $deposits = array_map(fn ($o) => (int) $o['walletDeposit']['depositVnd'], $preview['occurrences']);
        $this->assertNotSame($deposits[0], $deposits[1]);
        $this->assertSame(array_sum($deposits), (int) $preview['walletDepositTotalVnd']);
        $this->assertNotSame($preview['occurrences'][0]['walletDeposit']['quote'], $preview['occurrences'][1]['walletDeposit']['quote']);

        $this->fund((int) $preview['walletDepositTotalVnd']);
        $this->createSeries($series, $preview)->assertOk();
        $this->assertStoredMatches($preview);
        $this->assertSame('0', $this->wallet()['availableVnd']);
        $this->assertSame((string) $preview['walletDepositTotalVnd'], $this->wallet()['heldVnd']);
    }

    public function test_a_series_in_direct_deposit_mode_quotes_each_occurrence_the_same_way(): void
    {
        [$connection] = $this->connect();
        $series = $this->series(3);
        $this->rule((int) $series['serviceIds'][0], 40000, $this->occurrenceStart($series, 2)->subHour());

        $preview = $this->preview($series);
        $this->assertSame('DIRECT', $preview['depositMode']);
        $totals = array_map(fn ($o) => (int) $o['walletDeposit']['totalVnd'], $preview['occurrences']);
        $this->assertSame([$totals[0], $totals[0], $totals[0] + 40000], $totals);
        $this->assertSame(array_sum(array_map(fn ($o) => (int) $o['walletDeposit']['depositVnd'], $preview['occurrences'])), (int) $preview['walletDepositTotalVnd']);

        $this->createSeries($series, $preview)->assertOk();
        $this->assertStoredMatches($preview);
        $this->assertSame($connection['id'], (string) DB::table('booking_deposits')->value('connection_id'));
    }

    // ---- price by staff member -----------------------------------------------------------------------------------------------

    public function test_occurrences_assigned_to_different_staff_are_quoted_at_each_staff_members_price(): void
    {
        $this->configureLab();
        $series = $this->series(3);
        [$first, $second] = $this->staffFor(1);
        $this->rule((int) $series['serviceIds'][0], 30000, null, $second->id);
        // The first staff member is busy at the second occurrence, so the branch hands that one to the second member.
        $this->actor('owner');
        $this->withHeader('Idempotency-Key', 'block-first-staff-0001')->postJson('/api/v1/bookings', ['branchId' => $series['branchId'], 'serviceIds' => $series['serviceIds'], 'staffId' => (string) $first->id,
            'appointmentDate' => $this->occurrenceStart($series, 1)->toIso8601String(), 'source' => 'PHONE', 'customerName' => 'Khách quầy', 'customerPhone' => '0901234500', 'contactConsent' => true])->assertCreated();

        $preview = $this->preview($series);
        $staff = array_column($preview['occurrences'], 'staffId');
        $this->assertSame([(string) $first->id, (string) $second->id, (string) $first->id], $staff);
        $totals = array_map(fn ($o) => (int) $o['walletDeposit']['totalVnd'], $preview['occurrences']);
        $this->assertSame([$totals[0], $totals[0] + 30000, $totals[0]], $totals);

        $this->fund((int) $preview['walletDepositTotalVnd']);
        $this->createSeries($series, $preview)->assertOk();
        $created = DB::table('bookings as b')->join('booking_deposits as d', 'd.booking_id', '=', 'b.id')->orderBy('b.appointment_start_at')->get(['b.total_amount', 'd.amount_vnd']);
        $this->assertSame(array_map(fn ($o) => (int) $o['walletDeposit']['totalVnd'], $preview['occurrences']), $created->map(fn ($r) => (int) round((float) $r->total_amount))->all());
    }

    // ---- carts and combos ------------------------------------------------------------------------------------------------------

    public function test_a_multi_service_series_is_quoted_from_the_whole_cart_at_each_date(): void
    {
        $this->configureLab();
        $series = $this->series(2, 2);
        $this->assertCount(2, $series['serviceIds']);
        $this->rule((int) $series['serviceIds'][1], 25000, $this->occurrenceStart($series, 1)->subHour());

        $preview = $this->preview($series);
        $totals = array_map(fn ($o) => (int) $o['walletDeposit']['totalVnd'], $preview['occurrences']);
        $this->assertSame($totals[0] + 25000, $totals[1]);
        $this->fund((int) $preview['walletDepositTotalVnd']);
        $this->createSeries($series, $preview)->assertOk();
        $this->assertStoredMatches($preview);
    }

    public function test_a_combo_series_is_quoted_from_the_combo_price_for_every_occurrence(): void
    {
        $this->configureLab();
        $series = $this->series(2, 2);
        $branch = $this->branch();
        $combo = Combo::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'name' => 'Combo định kỳ', 'combo_price' => 200000, 'status' => 'ACTIVE']);
        foreach (BranchServiceOffering::whereIn('id', $series['serviceIds'])->get() as $index => $offering) {
            $combo->items()->create(['branch_service_offering_id' => $offering->id, 'quantity' => 1, 'sort_order' => $index, 'price_snapshot' => $offering->price, 'duration_snapshot' => $offering->duration_minutes]);
        }
        $series = ['comboId' => $combo->id] + array_diff_key($series, ['serviceIds' => 1]);

        $preview = $this->preview($series);
        $this->assertSame([200000, 200000], array_map(fn ($o) => (int) $o['walletDeposit']['totalVnd'], $preview['occurrences']));
        $this->assertSame(120000, (int) $preview['walletDepositTotalVnd']);
        $this->fund(120000);
        $this->createSeries($series, $preview)->assertOk();
        $this->assertStoredMatches($preview);
    }

    // ---- skipped occurrences, funds and stale quotes -------------------------------------------------------------------------

    public function test_a_skipped_occurrence_leaves_its_quote_unused_and_later_occurrences_keep_their_own(): void
    {
        $this->configureLab();
        $series = $this->series(3, 1, ['skipConflicts' => true]);
        $this->rule((int) $series['serviceIds'][0], 40000, $this->occurrenceStart($series, 2)->subHour());
        // The customer already has an appointment at the second occurrence.
        $clash = $this->booking(100000, 'CONFIRMED');
        $start = $this->occurrenceStart($series, 1)->utc();
        DB::table('bookings')->where('id', $clash->id)->update(['appointment_start_at' => $start, 'appointment_end_at' => $start->addHour()]);
        DB::table('booking_services')->where('booking_id', $clash->id)->update(['item_start_at' => $start, 'item_end_at' => $start->addHour(), 'occupied_start_at' => $start, 'occupied_end_at' => $start->addHour()]);

        $preview = $this->preview($series);
        $this->assertSame([true, false, true], array_column($preview['occurrences'], 'available'));
        $this->assertNull($preview['occurrences'][1]['walletDeposit']);
        $totals = [(int) $preview['occurrences'][0]['walletDeposit']['totalVnd'], (int) $preview['occurrences'][2]['walletDeposit']['totalVnd']];
        $this->assertSame($totals[0] + 40000, $totals[1]);
        $this->assertSame((int) $preview['occurrences'][0]['walletDeposit']['depositVnd'] + (int) $preview['occurrences'][2]['walletDeposit']['depositVnd'], (int) $preview['walletDepositTotalVnd']);

        $this->fund((int) $preview['walletDepositTotalVnd']);
        $created = $this->createSeries($series, $preview)->assertOk()->json();
        $this->assertSame(['BOOKED', 'SKIPPED', 'BOOKED'], array_column($created['occurrences'], 'status'));
        $stored = DB::table('booking_deposits as d')->join('bookings as b', 'b.id', '=', 'd.booking_id')->where('b.id', '!=', $clash->id)->orderBy('b.appointment_start_at')->get(['d.amount_vnd', 'd.total_vnd']);
        $this->assertSame([(int) $preview['occurrences'][0]['walletDeposit']['depositVnd'], (int) $preview['occurrences'][2]['walletDeposit']['depositVnd']], $stored->map(fn ($r) => (int) $r->amount_vnd)->all());
    }

    public function test_a_wallet_that_cannot_fund_the_whole_series_a_stale_quote_and_a_resend_leave_no_half_series(): void
    {
        $this->configureLab();
        $series = $this->series(3);
        $this->rule((int) $series['serviceIds'][0], 40000, $this->occurrenceStart($series, 1)->subHour());
        $preview = $this->preview($series);
        $needed = (int) $preview['walletDepositTotalVnd'];

        $this->fund($needed - 1000);
        $this->createSeries($series, $preview, 'series-quote-short-1')->assertStatus(402);
        $this->assertSame([0, 0, 0], [DB::table('recurring_booking_series')->count(), DB::table('bookings')->count(), DB::table('booking_deposits')->count()]);

        // A quote built from one flat price (what the old preview produced) is refused whole.
        $stale = array_fill(0, 3, $preview['occurrences'][0]['walletDeposit']['quote']);
        $this->fund(1000);
        $this->actor('customer');
        $this->withHeader('Idempotency-Key', 'series-quote-stale-1')->postJson('/api/v1/recurring', $series + ['walletDepositQuotes' => $stale])->assertStatus(409);
        $this->assertSame([0, 0], [DB::table('bookings')->count(), DB::table('booking_deposits')->count()]);

        $this->createSeries($series, $preview, 'series-quote-ok-0001')->assertOk();
        $this->assertSame(3, DB::table('booking_deposits')->count());
        $this->createSeries($series, $preview, 'series-quote-ok-0001')->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame([3, 3], [DB::table('bookings')->count(), DB::table('wallet_transfers')->where('kind', 'HOLD')->count()]);
        $this->assertSame('0', $this->wallet()['availableVnd']);
    }

    public function test_a_price_that_changes_between_preview_and_create_is_refused_and_a_fresh_preview_succeeds(): void
    {
        $this->configureLab();
        $series = $this->series(2);
        $preview = $this->preview($series);
        $this->fund(500000);

        $this->rule((int) $series['serviceIds'][0], 40000, $this->occurrenceStart($series, 1)->subHour());
        $this->createSeries($series, $preview, 'series-quote-moved-1')->assertStatus(409);
        $this->assertSame([0, 0, 0, 0], [DB::table('recurring_booking_series')->count(), DB::table('bookings')->count(), DB::table('booking_deposits')->count(), DB::table('wallet_transfers')->where('kind', 'HOLD')->count()]);
        $this->assertSame('500000', $this->wallet()['availableVnd']);

        $fresh = $this->preview($series);
        $this->assertNotSame($preview['walletDepositTotalVnd'], $fresh['walletDepositTotalVnd']);
        $this->createSeries($series, $fresh, 'series-quote-moved-2')->assertOk();
        $this->assertStoredMatches($fresh);
    }

    public function test_source_recipe_direct_deposits_keep_each_dates_signed_quote(): void
    {
        $this->connect();
        config(['beautybook.legacy_ui'=>false]);
        $series=$this->series(2);
        $serviceId=$series['serviceIds'][0];
        $this->rule((int)$serviceId,40000,$this->occurrenceStart($series,1)->subHour());
        unset($series['serviceIds']);
        $series['selections']=[['serviceId'=>$serviceId,'quantity'=>2]];
        $series['contact']=['name'=>'Khách định kỳ có cọc','phone'=>'0901234567'];
        $preview=$this->preview($series);
        $this->assertSame('DIRECT',$preview['depositMode']);
        $this->assertSame(80000,(int)$preview['occurrences'][1]['quote']['finalAmount']-(int)$preview['occurrences'][0]['quote']['finalAmount']);
        $this->assertNotSame($preview['occurrences'][0]['walletDeposit']['quote'],$preview['occurrences'][1]['walletDeposit']['quote']);
        $this->createSeries($series,$preview,'source-direct-series')->assertOk()->assertJsonCount(2,'bookings');
        $this->assertStoredMatches($preview);
    }

    public function test_source_recipe_wallet_requires_enough_for_the_entire_series(): void
    {
        $this->configureLab();
        config(['beautybook.legacy_ui'=>false]);
        $series=$this->series(2);
        $serviceId=$series['serviceIds'][0];unset($series['serviceIds']);
        $series['selections']=[['serviceId'=>$serviceId,'quantity'=>2]];
        $series['contact']=['name'=>'Khách định kỳ qua ví','phone'=>'0901234567'];
        $preview=$this->preview($series);
        $this->fund((int)$preview['walletDepositTotalVnd']-1);
        $this->createSeries($series,$preview,'source-wallet-short')->assertStatus(402);
        $this->assertDatabaseCount('bookings',0);
        $this->fund(1000);
        $this->createSeries($series,$preview,'source-wallet-funded')->assertOk();
        $this->assertSame((string)$preview['walletDepositTotalVnd'],$this->wallet()['heldVnd']);
        $this->assertStoredMatches($preview);
    }
}
