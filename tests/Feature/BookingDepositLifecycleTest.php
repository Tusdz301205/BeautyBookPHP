<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Combo;
use App\Models\StaffProfile;
use App\Services\Booking\BookingMaintenanceService;
use App\Services\Booking\CustomerBookingFinalizer;
use App\Services\Wallet\WalletCore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/**
 * Booking-deposit lifecycle against the source (booking-aggregate, booking-pricing, recurring, deposit-core):
 * branch policy drives the cancellation cutoff, the pending hold and AUTO confirmation; every create path holds the
 * deposit; recurring series hold one deposit per occurrence all-or-nothing; the store pays only the remainder.
 */
class BookingDepositLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WalletFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->configureLab();
    }

    private function branchId(): int
    {
        return (int) Branch::where('slug', 'tan-tao')->value('id');
    }

    /** Stores the branch's own booking rules where the owner UI writes them (beautybook_branch_settings). */
    private function policy(array $metadata = [], array $bookingPolicy = []): void
    {
        $id = $this->branchId();
        DB::table('beautybook_branch_settings')->insertOrIgnore(['branch_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('beautybook_branch_settings')->where('branch_id', $id)->first();
        DB::table('beautybook_branch_settings')->where('branch_id', $id)->update([
            'metadata' => json_encode(array_replace(json_decode($row->metadata ?? '{}', true) ?: [], $metadata)),
            'booking_policy' => json_encode(array_replace(json_decode($row->booking_policy ?? '{}', true) ?: [], $bookingPolicy)),
        ]);
    }

    private function bookingData(int $services = 1, int $daysAhead = 8): array
    {
        $b = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offerings = BranchServiceOffering::where('branch_id', $b->id)->orderBy('id')->take($services)->get();
        $staff = StaffProfile::where('branch_id', $b->id)->where('status', 'ACTIVE')->firstOrFail();
        foreach ($offerings as $s) $staff->offerings()->syncWithoutDetaching([$s->id => ['business_id' => $b->business_id, 'branch_id' => $b->id]]);
        $at = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays($daysAhead)->setTime(10, 0);
        while ($at->dayOfWeek === 0) $at = $at->addDay();

        return ['branchId' => (string) $b->id, 'serviceIds' => $offerings->pluck('id')->map(fn ($id) => (string) $id)->all(), 'staffId' => (string) $staff->id,
            'appointmentDate' => $at->toIso8601String(), 'source' => 'ONLINE_WEB', 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true];
    }

    private function totalOf(array $data): int
    {
        $this->actor('customer');

        return (int) round((float) $this->postJson('/api/v1/bookings/preview-price', $data)->assertOk()->json('finalAmount'));
    }

    private function terms(array $data, ?int $total = null): array
    {
        $this->actor('customer');

        return $this->getJson('/api/v1/wallet/deposit-terms?branchId='.$data['branchId'].'&total='.($total ?? $this->totalOf($data)))->assertOk()->json('terms');
    }

    private function book(array $data, ?string $quote, string $key = 'deposit-lifecycle-0001')
    {
        $this->actor('customer');

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', $data + ($quote !== null ? ['walletDepositQuote' => $quote] : []));
    }

    /** Funds the wallet and books through the real API; returns [bookingId, terms]. */
    private function bookedWithDeposit(array $data, string $key = 'deposit-lifecycle-0001'): array
    {
        $terms = $this->terms($data);
        $this->fund($terms['depositVnd']);
        $id = $this->book($data, $terms['quote'], $key)->assertCreated()->json('id');

        return [(int) $id, $terms];
    }

    private function moveBooking(int $id, CarbonImmutable $start, int $minutes = 60): void
    {
        $end = $start->addMinutes($minutes);
        DB::table('bookings')->where('id', $id)->update(['appointment_start_at' => $start->utc(), 'appointment_end_at' => $end->utc()]);
        DB::table('booking_services')->where('booking_id', $id)->update(['item_start_at' => $start->utc(), 'item_end_at' => $end->utc(), 'occupied_start_at' => $start->utc(), 'occupied_end_at' => $end->utc()]);
    }

    private function heldUntil(int $bookingId): \Carbon\CarbonInterface
    {
        return Booking::findOrFail($bookingId)->pending_expires_at;
    }

    private function deposit(int $bookingId): object
    {
        return DB::table('booking_deposits')->where('booking_id', $bookingId)->firstOrFail();
    }

    private function moveStatus(string $as, int $id, string $status, array $extra = [])
    {
        $this->actor($as);

        return $this->patchJson("/api/v1/bookings/{$id}/status", ['status' => $status] + $extra);
    }

    // ---- branch policy --------------------------------------------------------------------------------------------

    public function test_the_branch_cancellation_hours_drive_the_quote_the_snapshot_and_the_direct_cancel_cutoff(): void
    {
        $this->policy([], ['cancellationHours' => 24]);
        $data = $this->bookingData();
        $terms = $this->terms($data);
        $this->assertSame(24, $terms['cancellationHours']);
        [$id] = $this->bookedWithDeposit($data);
        $this->assertSame(24, (int) $this->deposit($id)->cancellation_hours);

        $this->moveBooking($id, CarbonImmutable::now('UTC')->addHours(20));
        $this->moveStatus('customer', $id, 'CANCELLED')->assertConflict();
        $this->assertSame('HELD', $this->deposit($id)->status);

        $this->moveBooking($id, CarbonImmutable::now('UTC')->addHours(30));
        $this->moveStatus('customer', $id, 'CANCELLED')->assertOk();
        $this->assertSame('REFUNDED', $this->deposit($id)->status);
    }

    public function test_a_branch_without_a_stored_cancellation_policy_keeps_the_four_hour_cutoff(): void
    {
        $data = $this->bookingData();
        $this->assertSame(4, $this->terms($data)['cancellationHours']);
    }

    public function test_a_late_cancellation_request_is_judged_by_the_branch_hours_at_the_time_it_was_requested(): void
    {
        $this->policy([], ['cancellationHours' => 24]);
        [$id] = $this->bookedWithDeposit($this->bookingData());
        $this->moveBooking($id, CarbonImmutable::now('UTC')->addHours(10));

        $this->actor('customer');
        $request = (int) $this->postJson("/api/v1/bookings/{$id}/change-requests", ['requestType' => 'CANCEL', 'reason' => 'Bận việc đột xuất'])->assertOk()->json('id');
        $this->assertSame(1, DB::table('booking_violation_events')->where('booking_id', $id)->where('kind', 'LATE_CANCELLATION')->count());
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/change-requests/{$request}/approve", [])->assertOk();

        $deposit = $this->deposit($id);
        $this->assertSame('FORFEITED', $deposit->status);
        $this->assertSame(1, DB::table('business_payables')->where('deposit_id', $deposit->id)->count());
        $this->assertSame(0, DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->count());
    }

    public function test_a_cancellation_request_made_three_hours_before_a_four_hour_cutoff_is_late_whatever_the_server_timezone(): void
    {
        [$id] = $this->bookedWithDeposit($this->bookingData());
        $this->moveBooking($id, CarbonImmutable::now('UTC')->addHours(3));

        $this->actor('customer');
        $request = (int) $this->postJson("/api/v1/bookings/{$id}/change-requests", ['requestType' => 'CANCEL', 'reason' => 'Bận việc đột xuất'])->assertOk()->json('id');
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/change-requests/{$request}/approve", [])->assertOk();

        // The request time is stored as UTC: judging it as local time would move it seven hours into the past and refund a late cancellation.
        $this->assertSame('FORFEITED', $this->deposit($id)->status);
        $this->assertSame(0, DB::table('deposit_refunds')->where('deposit_id', $this->deposit($id)->id)->count());
    }

    public function test_a_cancellation_requested_by_the_salon_never_forfeits_the_customers_deposit(): void
    {
        [$id] = $this->bookedWithDeposit($this->bookingData());
        $this->moveBooking($id, CarbonImmutable::now('UTC')->addHour());
        $owner = $this->actor('owner');
        $request = DB::table('appointment_change_requests')->insertGetId(['booking_id' => $id, 'requested_by_user_id' => $owner->id, 'requested_by_type' => 'SALON', 'request_type' => 'CANCEL',
            'reason' => 'Cơ sở bận đột xuất', 'status' => 'PENDING', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson("/api/v1/bookings/change-requests/{$request}/approve", [])->assertOk();

        $this->assertSame('REFUNDED', $this->deposit($id)->status);
        $this->assertSame(0, DB::table('business_payables')->count());
    }

    public function test_the_pending_hold_follows_the_branch_and_the_deposit_expiry_is_the_same_instant(): void
    {
        $this->policy(['pendingHoldMinutes' => 10]);
        [$id] = $this->bookedWithDeposit($this->bookingData());
        $booking = DB::table('bookings')->find($id);
        $deposit = $this->deposit($id);

        $this->assertEqualsWithDelta(10 * 60, $this->heldUntil($id)->getTimestamp() - time(), 30);
        $this->assertSame($this->heldUntil($id)->getTimestamp(), CarbonImmutable::parse($deposit->expires_at, 'UTC')->getTimestamp());
        $this->assertSame(10, (int) $deposit->pending_hold_minutes);
    }

    public function test_a_pending_hold_never_outlives_the_appointment_and_the_booking_expires_with_its_deposit(): void
    {
        $this->policy(['pendingHoldMinutes' => 120]);
        $this->fund(60000);
        $booking = $this->booking(100000, 'PENDING');
        $start = CarbonImmutable::now('UTC')->addMinutes(20);
        $this->moveBooking($booking->id, $start);
        DB::transaction(fn () => CustomerBookingFinalizer::finalize($booking->refresh(), ['kind' => 'CUSTOMER', 'customer_profile_id' => $booking->customer_profile_id, 'actor_user_id' => $booking->created_by_user_id], Branch::find($booking->branch_id), 100000, $start, WalletCore::terms($this->branchId(), 100000, 4)['quote']));

        $this->assertSame($start->getTimestamp(), $this->heldUntil($booking->id)->getTimestamp());
        $this->assertSame($start->getTimestamp(), CarbonImmutable::parse($this->deposit($booking->id)->expires_at, 'UTC')->getTimestamp());

        $this->travelTo($start->addMinute());
        app(BookingMaintenanceService::class)->expire();
        $this->assertSame(['EXPIRED', 'REFUNDED'], [DB::table('bookings')->find($booking->id)->status, $this->deposit($booking->id)->status]);
    }

    public function test_auto_confirmation_confirms_a_funded_booking_at_once_and_manual_leaves_it_pending(): void
    {
        $this->policy(['bookingConfirmationMode' => 'AUTO_CONFIRMATION']);
        [$id] = $this->bookedWithDeposit($this->bookingData(), 'auto-confirm-0001');
        $booking = DB::table('bookings')->find($id);
        $this->assertSame(['CONFIRMED', null, 'HELD'], [$booking->status, $booking->pending_expires_at, $this->deposit($id)->status]);
        $this->assertSame('AUTO_CONFIRMATION_POLICY', DB::table('booking_status_histories')->where('booking_id', $id)->where('to_status', 'CONFIRMED')->value('reason'));
        $this->assertSame(['PENDING', 'CONFIRMED'], DB::table('booking_status_histories')->where('booking_id', $id)->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(0, app(BookingMaintenanceService::class)->expire());

        $this->policy(['bookingConfirmationMode' => 'MANUAL_CONFIRMATION']);
        // Sunday normalization can make +8 and +9 land on the same Monday.
        $second = $this->bookingData(1, 10);
        [$secondId] = $this->bookedWithDeposit($second, 'manual-confirm-0001');
        $manual = DB::table('bookings')->find($secondId);
        $this->assertSame('PENDING', $manual->status);
        $this->assertNotNull($manual->pending_expires_at);
    }

    public function test_auto_confirmation_also_applies_when_the_wallet_is_off_and_never_to_counter_bookings(): void
    {
        config(['wallet.mode' => 'OFF']);
        $this->policy(['bookingConfirmationMode' => 'AUTO_CONFIRMATION']);
        $data = $this->bookingData();
        $id = $this->book($data, null, 'auto-no-wallet-001')->assertCreated()->json('id');
        $this->assertSame('CONFIRMED', DB::table('bookings')->find($id)->status);
        $this->assertSame(0, DB::table('booking_deposits')->count());

        $this->actor('owner');
        $counter = $this->bookingData(1, 10);
        $counter['source'] = 'PHONE';
        $counterId = $this->withHeader('Idempotency-Key', 'auto-counter-0001')->postJson('/api/v1/bookings', $counter + ['guestName' => 'Khách quầy', 'guestPhone' => '0911222333'])->assertCreated()->json('id');
        $this->assertSame('PENDING', DB::table('bookings')->find($counterId)->status);
    }

    // ---- every create path ----------------------------------------------------------------------------------------

    public function test_a_combo_booking_holds_its_deposit_like_any_other_booking(): void
    {
        $data = $this->bookingData(2);
        $branch = Branch::find($this->branchId());
        $combo = Combo::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'name' => 'Combo cọc', 'combo_price' => 200000, 'status' => 'ACTIVE']);
        $offerings = BranchServiceOffering::whereIn('id', $data['serviceIds'])->get();
        foreach ($offerings as $index => $offering) $combo->items()->create(['branch_service_offering_id' => $offering->id, 'quantity' => 1, 'sort_order' => $index, 'price_snapshot' => $offering->price, 'duration_snapshot' => $offering->duration_minutes]);
        $payload = ['branchId' => $data['branchId'], 'comboId' => $combo->id, 'appointmentDate' => $data['appointmentDate'], 'source' => 'ONLINE_WEB', 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true];
        $terms = $this->terms($payload, 200000);

        $this->actor('customer');
        $this->withHeader('Idempotency-Key', 'combo-deposit-0001')->postJson('/api/v1/bookings', $payload + ['walletDepositQuote' => $terms['quote']])->assertStatus(402);
        $this->assertSame([0, 0], [DB::table('bookings')->count(), DB::table('booking_deposits')->count()]);
        $this->withHeader('Idempotency-Key', 'combo-deposit-stale1')->postJson('/api/v1/bookings', $payload)->assertStatus(409);
        $this->assertSame(0, DB::table('bookings')->count());

        $this->fund($terms['depositVnd']);
        $id = $this->book($payload, $terms['quote'], 'combo-deposit-0002')->assertCreated()->json('id');
        $deposit = $this->deposit((int) $id);
        $this->assertSame([$terms['depositVnd'], 'HELD'], [(int) $deposit->amount_vnd, $deposit->status]);
        $this->assertSame('0', $this->wallet()['availableVnd']);
    }

    // ---- recurring ------------------------------------------------------------------------------------------------

    private function series(int $count = 3, array $extra = []): array
    {
        $data = $this->bookingData();

        return ['branchId' => $data['branchId'], 'serviceIds' => $data['serviceIds'], 'staffMode' => 'ANY_AVAILABLE', 'frequency' => 'WEEKLY', 'startDate' => substr($data['appointmentDate'], 0, 10),
            'preferredTime' => '10:00', 'occurrenceCount' => $count, 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true] + $extra;
    }

    private function recurringPreview(array $series): array
    {
        $this->actor('customer');

        return $this->postJson('/api/v1/recurring/preview', $series)->assertOk()->json();
    }

    private function createSeries(array $series, string $key = 'series-deposit-0001')
    {
        $this->actor('customer');

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/recurring', $series);
    }

    public function test_the_recurring_preview_quotes_every_occurrence_and_the_whole_series_deposit(): void
    {
        $preview = $this->recurringPreview($this->series(3));

        $this->assertTrue($preview['walletMode']);
        $this->assertCount(3, $preview['occurrences']);
        $each = $preview['occurrences'][0]['walletDeposit'];
        foreach ($preview['occurrences'] as $occurrence) {
            $this->assertSame($each['depositVnd'], $occurrence['walletDeposit']['depositVnd']);
            $this->assertNotEmpty($occurrence['walletDeposit']['quote']);
        }
        $this->assertSame($each['depositVnd'] * 3, $preview['walletDepositTotalVnd']);
    }

    public function test_a_recurring_series_holds_one_deposit_per_occurrence_and_a_replay_holds_nothing_more(): void
    {
        $series = $this->series(3);
        $preview = $this->recurringPreview($series);
        $this->fund($preview['walletDepositTotalVnd'] + 5000);
        $body = $series + ['walletDepositQuotes' => array_column(array_column($preview['occurrences'], 'walletDeposit'), 'quote')];

        $created = $this->createSeries($body)->assertOk()->json();
        $this->assertCount(3, $created['bookings']);
        $this->assertSame(3, DB::table('booking_deposits')->where('status', 'HELD')->count());
        $this->assertSame(['5000', (string) $preview['walletDepositTotalVnd']], [$this->wallet()['availableVnd'], $this->wallet()['heldVnd']]);
        $this->assertSame(3, DB::table('wallet_transfers')->where('kind', 'HOLD')->count());

        $this->createSeries($body)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(3, DB::table('wallet_transfers')->where('kind', 'HOLD')->count());
        $this->assertSame('5000', $this->wallet()['availableVnd']);
    }

    public function test_a_recurring_series_without_enough_balance_or_with_a_stale_quote_creates_nothing(): void
    {
        $series = $this->series(3);
        $preview = $this->recurringPreview($series);
        $quotes = array_column(array_column($preview['occurrences'], 'walletDeposit'), 'quote');
        $each = $preview['occurrences'][0]['walletDeposit']['depositVnd'];
        $this->fund($each * 2 + 500);
        $before = [$this->wallet()['availableVnd'], $this->wallet()['heldVnd']];

        $this->createSeries($series + ['walletDepositQuotes' => $quotes], 'series-short-0001')->assertStatus(402);
        $this->createSeries($series + ['walletDepositQuotes' => ['stale', 'stale', 'stale']], 'series-stale-0001')->assertStatus(409);
        $this->createSeries($series, 'series-missing-0001')->assertStatus(409);

        $this->assertSame([0, 0, 0, 0], [DB::table('recurring_booking_series')->count(), DB::table('bookings')->count(), DB::table('booking_deposits')->count(), DB::table('wallet_transfers')->where('kind', 'HOLD')->count()]);
        $this->assertSame($before, [$this->wallet()['availableVnd'], $this->wallet()['heldVnd']]);

        $this->fund($each);
        $this->createSeries($series + ['walletDepositQuotes' => $quotes], 'series-short-0001')->assertOk();
        $this->assertSame(3, DB::table('booking_deposits')->count());
    }

    public function test_skipping_a_conflicting_occurrence_holds_no_deposit_for_it_and_a_failure_still_rolls_everything_back(): void
    {
        $series = $this->series(3, ['skipConflicts' => true]);
        $preview = $this->recurringPreview($series);
        $each = $preview['occurrences'][0]['walletDeposit']['depositVnd'];
        $quotes = array_column(array_column($preview['occurrences'], 'walletDeposit'), 'quote');

        // The customer already has an appointment at the second occurrence, so only that one conflicts.
        $second = CarbonImmutable::parse($preview['occurrences'][1]['appointmentDate']);
        $clash = $this->booking(100000, 'CONFIRMED');
        $this->moveBooking($clash->id, $second->utc());
        $conflicted = $this->recurringPreview($series);
        $this->assertSame([true, false, true], array_column($conflicted['occurrences'], 'available'));
        $this->assertSame($each * 2, $conflicted['walletDepositTotalVnd']);

        $this->fund($each + 500);
        $this->createSeries($series + ['walletDepositQuotes' => $quotes], 'series-skip-0001')->assertStatus(402);
        $this->assertSame([0, 0], [DB::table('recurring_booking_series')->count(), DB::table('booking_deposits')->count()]);

        $this->fund($each);
        $created = $this->createSeries($series + ['walletDepositQuotes' => $quotes], 'series-skip-0002')->assertOk()->json();
        $this->assertSame(['BOOKED', 'SKIPPED', 'BOOKED'], array_column($created['occurrences'], 'status'));
        $this->assertSame(2, DB::table('booking_deposits')->count());
        $this->assertSame(2, DB::table('wallet_transfers')->where('kind', 'HOLD')->count());
    }

    public function test_cancelling_one_occurrence_refunds_only_its_own_deposit(): void
    {
        $series = $this->series(2);
        $preview = $this->recurringPreview($series);
        $this->fund($preview['walletDepositTotalVnd']);
        $created = $this->createSeries($series + ['walletDepositQuotes' => array_column(array_column($preview['occurrences'], 'walletDeposit'), 'quote')])->assertOk()->json();
        $first = (int) $created['bookings'][0]['id'];

        $this->actor('customer');
        $this->deleteJson("/api/v1/recurring/{$created['id']}/occurrences/{$first}")->assertOk();
        $this->assertSame('REFUNDED', $this->deposit($first)->status);
        $this->assertSame('HELD', $this->deposit((int) $created['bookings'][1]['id'])->status);
        $this->assertSame(1, DB::table('wallet_transfers')->where('kind', 'REFUND')->count());
    }

    // ---- every way a booking can end ------------------------------------------------------------------------------

    public function test_rejection_and_an_owner_cancellation_inside_the_cutoff_refund_in_full(): void
    {
        [$first] = $this->bookedWithDeposit($this->bookingData(), 'end-reject-000001');
        $this->moveStatus('owner', $first, 'REJECTED', ['note' => 'Hết chuyên viên'])->assertOk();
        $this->assertSame('REFUNDED', $this->deposit($first)->status);

        [$second] = $this->bookedWithDeposit($this->bookingData(1, 9), 'end-owner-cancel1');
        $this->moveStatus('owner', $second, 'CONFIRMED')->assertOk();
        $this->moveBooking($second, CarbonImmutable::now('UTC')->addHour());
        $this->moveStatus('owner', $second, 'CANCELLED')->assertOk();
        $this->assertSame('REFUNDED', $this->deposit($second)->status);
        $this->assertSame(0, DB::table('business_payables')->count());
        $this->assertSame(2, DB::table('wallet_transfers')->where('kind', 'REFUND')->count());
    }

    public function test_completion_releases_the_deposit_to_the_business_and_a_no_show_forfeits_it(): void
    {
        [$done] = $this->bookedWithDeposit($this->bookingData(), 'end-complete-0001');
        $this->moveStatus('owner', $done, 'CONFIRMED')->assertOk();
        $this->moveBooking($done, CarbonImmutable::now('UTC')->subHours(2));
        foreach (['CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'] as $step) $this->moveStatus('owner', $done, $step)->assertOk();
        $this->assertSame('APPLIED', $this->deposit($done)->status);
        $this->assertSame('COMPLETED_SERVICE', DB::table('business_payables')->where('deposit_id', $this->deposit($done)->id)->value('reason'));

        [$absent] = $this->bookedWithDeposit($this->bookingData(1, 9), 'end-no-show-0001');
        $this->moveStatus('owner', $absent, 'CONFIRMED')->assertOk();
        $this->moveBooking($absent, CarbonImmutable::now('UTC')->subMinutes(40));
        $this->moveStatus('owner', $absent, 'NO_SHOW', ['noShowConfirmed' => true])->assertOk();
        $this->assertSame('FORFEITED', $this->deposit($absent)->status);
        $this->assertSame('CUSTOMER_FORFEITURE', DB::table('business_payables')->where('deposit_id', $this->deposit($absent)->id)->value('reason'));
        $this->assertSame(2, DB::table('wallet_transfers')->where('kind', 'RELEASE')->count());
    }

    // ---- the remainder at the store -------------------------------------------------------------------------------

    private function collect(int $bookingId, array $body)
    {
        $this->actor('owner');

        return $this->postJson('/api/v1/payments/collect', ['bookingId' => (string) $bookingId, 'method' => 'CASH', 'idempotencyKey' => 'store-'.bin2hex(random_bytes(6))] + $body);
    }

    private function checkedIn(string $key = 'store-pay-00000001'): int
    {
        [$id] = $this->bookedWithDeposit($this->bookingData(), $key);
        $this->moveStatus('owner', $id, 'CONFIRMED')->assertOk();
        $this->moveBooking($id, CarbonImmutable::now('UTC')->subMinutes(10));
        $this->moveStatus('owner', $id, 'CHECKED_IN')->assertOk();

        return $id;
    }

    public function test_the_deposit_counts_as_paid_so_the_store_collects_only_the_remainder(): void
    {
        $id = $this->checkedIn();
        $deposit = $this->deposit($id);
        $this->actor('owner');
        $checkout = $this->getJson("/api/v1/payments/checkout/{$id}")->assertOk()->json();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->amount_vnd, (float) $deposit->remaining_vnd], [(float) $checkout['totalAmount'], (float) $checkout['paidAmount'], (float) $checkout['remainingAmount']]);

        $this->collect($id, ['amount' => (float) $deposit->total_vnd])->assertStatus(422);
        $this->assertSame(0, DB::table('payment_transactions')->count());

        $this->collect($id, [])->assertOk();
        $this->assertEquals((float) $deposit->remaining_vnd, (float) DB::table('payment_transactions')->sum('amount_cents') / 100);
        $this->actor('owner');
        $after = $this->getJson("/api/v1/payments/checkout/{$id}")->json();
        $this->assertEquals([(float) $deposit->total_vnd, 0.0], [(float) $after['paidAmount'], (float) $after['remainingAmount']]);
        $this->collect($id, ['amount' => 1])->assertStatus(422);
    }

    public function test_a_recorded_store_payment_blocks_cancelling_and_clears_when_it_is_reversed(): void
    {
        $id = $this->checkedIn('store-block-0000001');
        $transaction = (int) $this->collect($id, [])->assertOk()->json('id');
        $this->assertNotNull($this->deposit($id)->store_received_at);

        $this->moveStatus('owner', $id, 'CANCELLED')->assertConflict();
        $this->assertSame('HELD', $this->deposit($id)->status);

        $this->actor('owner');
        $this->postJson("/api/v1/payments/transactions/{$transaction}/reverse", ['reason' => 'Ghi nhận nhầm', 'idempotencyKey' => 'reverse-store-1'])->assertOk();
        $this->assertNull($this->deposit($id)->store_received_at);
        $this->moveStatus('owner', $id, 'CANCELLED')->assertOk();
        $this->assertSame('REFUNDED', $this->deposit($id)->status);
    }

    public function test_the_store_cannot_record_the_remainder_before_the_customer_arrives(): void
    {
        [$id] = $this->bookedWithDeposit($this->bookingData(), 'store-early-000001');
        $this->moveStatus('owner', $id, 'CONFIRMED')->assertOk();
        $this->collect($id, [])->assertStatus(409);
        $this->assertSame(0, DB::table('payment_transactions')->count());
    }
}
