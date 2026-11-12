<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Services\Booking\BookingMaintenanceService;
use App\Services\Wallet\WalletCore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/** 30% wallet deposit: hold in the booking transaction, then release / forfeit / refund by booking outcome (wallet-core.ts). */
class WalletDepositTest extends TestCase
{
    use RefreshDatabase;
    use WalletFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->configureLab();
    }

    private function bookingData(): array
    {
        $b = Branch::where('slug', 'tan-tao')->firstOrFail();
        $services = BranchServiceOffering::where('branch_id', $b->id)->orderBy('id')->take(1)->get();
        $staff = StaffProfile::where('branch_id', $b->id)->where('status', 'ACTIVE')->firstOrFail();
        foreach ($services as $s) $staff->offerings()->syncWithoutDetaching([$s->id => ['business_id' => $b->business_id, 'branch_id' => $b->id]]);
        $at = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(10, 0);
        while ($at->dayOfWeek === 0) $at = $at->addDay();

        return ['branchId' => (string) $b->id, 'serviceIds' => $services->pluck('id')->map(fn ($id) => (string) $id)->all(), 'staffId' => (string) $staff->id,
            'appointmentDate' => $at->toIso8601String(), 'source' => 'ONLINE_WEB', 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true];
    }

    private function totalOf(array $data): int
    {
        $this->actor('customer');

        return (int) round((float) $this->postJson('/api/v1/bookings/preview-price', $data)->assertOk()->json('finalAmount'));
    }

    private function book(array $data, ?string $quote, string $key = 'deposit-booking-0001')
    {
        $this->actor('customer');

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', $data + ($quote !== null ? ['walletDepositQuote' => $quote] : []));
    }

    public function test_the_booking_holds_the_deposit_from_the_wallet_in_its_own_transaction_and_a_failure_changes_nothing(): void
    {
        $data = $this->bookingData();
        $total = $this->totalOf($data);
        $terms = $this->getJson('/api/v1/wallet/deposit-terms?branchId='.$data['branchId'].'&total='.$total)->assertOk()->json('terms');
        $this->assertSame([$total, intdiv($total * 3 + 9, 10), $total - intdiv($total * 3 + 9, 10), 3000, 4], [$terms['totalVnd'], $terms['depositVnd'], $terms['remainingVnd'], $terms['rateBps'], $terms['cancellationHours']]);
        $funded = min(200000, $terms['depositVnd'] + 5000);
        $this->fund($funded);

        // No quote, or a stale one, means the customer has not accepted the current deposit: nothing is booked or held.
        $this->book($data, null)->assertStatus(409);
        $this->book($data, str_repeat('a', 64))->assertStatus(409);
        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertSame(0, DB::table('booking_deposits')->count());

        $created = $this->book($data, $terms['quote'])->assertCreated()->json();
        $deposit = DB::table('booking_deposits')->first();
        $this->assertSame([(int) $created['id'], 'HELD', $terms['depositVnd'], $terms['remainingVnd'], $terms['totalVnd'], 3000], [(int) $deposit->booking_id, $deposit->status, (int) $deposit->amount_vnd, (int) $deposit->remaining_vnd, (int) $deposit->total_vnd, (int) $deposit->rate_bps]);
        $this->assertStringStartsWith('WM', $deposit->payment_code);
        $wallet = $this->wallet();
        $this->assertSame([(string) ($funded - $terms['depositVnd']), (string) $terms['depositVnd']], [$wallet['availableVnd'], $wallet['heldVnd']]);
        $hold = DB::table('wallet_transfers')->where('kind', 'HOLD')->first();
        $this->assertSame(['wallet:'.$hold->wallet_id.':available', 'wallet:'.$hold->wallet_id.':held', 'hold:'.$deposit->id], [$hold->from_account, $hold->to_account, $hold->operation_key]);

        // A replay of the same booking command returns the booking and holds nothing more.
        $this->book($data, $terms['quote'])->assertCreated()->assertJsonPath('id', $created['id']);
        $this->assertSame(1, DB::table('booking_deposits')->count());
        $this->assertSame(1, DB::table('wallet_transfers')->where('kind', 'HOLD')->count());

        // A second booking (another day) cannot be paid with what is left: 402 and the booking transaction rolls back completely.
        $before = DB::table('bookings')->count();
        $next = CarbonImmutable::parse($data['appointmentDate'])->addDay();
        if ($next->dayOfWeek === 0) $next = $next->addDay();
        $this->book(['appointmentDate' => $next->toIso8601String()] + $data, $terms['quote'], 'deposit-booking-0002')->assertStatus(402);
        $this->assertSame($before, DB::table('bookings')->count());
        $this->assertSame(1, DB::table('booking_deposits')->count());
        $this->assertSame((string) ($funded - $terms['depositVnd']), $this->wallet()['availableVnd']);
    }

    public function test_accounts_outside_the_lab_cannot_book_with_a_deposit_and_staff_bookings_are_untouched(): void
    {
        $data = $this->bookingData();
        $total = $this->totalOf($data);
        $this->actor('customer');
        $quote = WalletCore::terms((int) $data['branchId'], $total, 4)['quote'];
        $this->configureLab(['user_ids' => '999999']);
        $this->book($data, $quote)->assertForbidden();
        $this->assertSame(0, DB::table('bookings')->count());
        $this->configureLab(['business_ids' => '999999']);
        $this->book($data, $quote)->assertForbidden();
        $this->assertSame(0, DB::table('bookings')->count());
        // Wallet mode off: today's behaviour, no deposit at all.
        config(['wallet.mode' => '']);
        $this->book($data, null)->assertCreated();
        $this->assertSame(0, DB::table('booking_deposits')->count());
        $this->assertFalse($this->getJson('/api/v1/wallet/deposit-terms?branchId='.$data['branchId'].'&total=100000')->json('walletMode'));
    }

    public function test_customer_cancellation_through_the_booking_api_refunds_the_whole_deposit_once(): void
    {
        $data = $this->bookingData();
        $total = $this->totalOf($data);
        $terms = WalletCore::terms((int) $data['branchId'], $total, 4);
        $this->fund(min(200000, $terms['depositVnd']));
        $id = $this->book($data, $terms['quote'])->assertCreated()->json('id');
        $this->assertSame('0', $this->wallet()['availableVnd']);

        $this->actor('customer');
        $this->patchJson("/api/v1/bookings/{$id}/status", ['status' => 'CANCELLED'])->assertOk();
        $deposit = DB::table('booking_deposits')->first();
        $this->assertSame(['REFUNDED', 1], [$deposit->status, (int) $deposit->version]);
        $this->assertSame([(string) $terms['depositVnd'], '0'], [$this->wallet()['availableVnd'], $this->wallet()['heldVnd']]);
        $this->assertSame(['REFUND'], DB::table('wallet_transfers')->where('operation_key', 'resolve:'.$deposit->id)->pluck('kind')->all());
        $refund = DB::table('deposit_refunds')->first();
        $this->assertSame(['CONFIRMED', 'BOOKING_CANCELLED', $terms['depositVnd']], [$refund->status, $refund->reason, (int) $refund->amount_vnd]);
        $this->assertSame(0, DB::table('business_payables')->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'WALLET_REFUNDED')->count());
        $this->patchJson("/api/v1/bookings/{$id}/status", ['status' => 'CANCELLED'])->assertOk();
        $this->assertSame(1, DB::table('wallet_transfers')->where('kind', 'REFUND')->count());
    }

    public function test_each_booking_outcome_settles_the_held_deposit_in_exactly_one_way(): void
    {
        $this->fund(200000);
        $cases = [
            // target, options, deposit status, kind, payable reason
            ['COMPLETED', [], 'APPLIED', 'RELEASE', 'COMPLETED_SERVICE'],
            ['NO_SHOW', [], 'FORFEITED', 'RELEASE', 'CUSTOMER_FORFEITURE'],
            ['REJECTED', [], 'REFUNDED', 'REFUND', null],
            ['EXPIRED', [], 'REFUNDED', 'REFUND', null],
            ['CANCELLED', ['customerCancelled' => false], 'REFUNDED', 'REFUND', null],
            // The customer asked more than 4 hours ahead; a slow review must not make it late.
            ['CANCELLED', ['customerCancelled' => true, 'requestedAt' => now('UTC')->addHour()], 'REFUNDED', 'REFUND', null],
            // The customer asked inside the 4 hours before the appointment: the deposit is forfeited to the business.
            ['CANCELLED', ['customerCancelled' => true, 'requestedAt' => now('UTC')->addHours(46)], 'FORFEITED', 'RELEASE', 'CUSTOMER_FORFEITURE'],
        ];
        foreach ($cases as [$target, $options, $status, $kind, $payable]) {
            $booking = $this->booking(100000, 'PENDING');
            $deposit = $this->heldDeposit($booking);
            $before = $this->wallet();
            DB::transaction(fn () => WalletCore::transition($booking->id, $target, $options + ['now' => now('UTC')->addHours(46)]));
            DB::transaction(fn () => WalletCore::transition($booking->id, $target, $options + ['now' => now('UTC')->addHours(46)]));
            $row = DB::table('booking_deposits')->find($deposit->id);
            $this->assertSame($status, $row->status, $target.json_encode($options));
            $this->assertSame(1, DB::table('wallet_transfers')->where('operation_key', 'resolve:'.$deposit->id)->count());
            $this->assertSame($kind, DB::table('wallet_transfers')->where('operation_key', 'resolve:'.$deposit->id)->value('kind'));
            $after = $this->wallet();
            $this->assertSame((int) $before['heldVnd'] - 30000, (int) $after['heldVnd'], 'held falls by the deposit');
            $this->assertSame((int) $before['availableVnd'] + ($kind === 'REFUND' ? 30000 : 0), (int) $after['availableVnd'], 'only a refund returns the deposit to available');
            $this->assertSame($payable, DB::table('business_payables')->where('deposit_id', $deposit->id)->value('reason'));
            $this->assertSame($payable === null ? 1 : 0, DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->count());
        }
        $this->assertSame(3, DB::table('business_payables')->count(), 'COMPLETED + NO_SHOW + late cancellation');
        $this->assertSame('0', $this->wallet()['heldVnd']);
    }

    public function test_a_confirmation_is_refused_when_the_deposit_is_no_longer_held_and_a_store_payment_blocks_a_refund(): void
    {
        $this->fund(60000);
        $booking = $this->booking(100000, 'PENDING');
        $deposit = $this->heldDeposit($booking);
        DB::transaction(fn () => WalletCore::transition($booking->id, 'REJECTED'));
        foreach (['CONFIRMED', 'CHECKED_IN', 'COMPLETED', 'NO_SHOW'] as $target) {
            $this->expectedConflict(fn () => DB::transaction(fn () => WalletCore::transition($booking->id, $target)), $target);
        }
        $second = $this->booking(100000, 'PENDING');
        $held = $this->heldDeposit($second);
        DB::table('booking_deposits')->where('id', $held->id)->update(['store_received_at' => now('UTC')]);
        $this->expectedConflict(fn () => DB::transaction(fn () => WalletCore::transition($second->id, 'CANCELLED')), 'CANCELLED');
        $this->assertSame('HELD', DB::table('booking_deposits')->where('id', $held->id)->value('status'));
        $this->assertSame('REFUNDED', DB::table('booking_deposits')->where('id', $deposit->id)->value('status'));
    }

    private function expectedConflict(callable $work, string $label): void
    {
        try {
            $work();
            $this->fail('expected a 409 for '.$label);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode(), $label);
        }
    }

    public function test_the_expiry_worker_refunds_the_deposit_of_a_pending_booking_that_nobody_confirmed(): void
    {
        $this->fund(60000);
        $booking = $this->booking(100000, 'PENDING');
        $deposit = $this->heldDeposit($booking);
        $this->assertSame(0, app(BookingMaintenanceService::class)->expire());
        $booking->update(['pending_expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(BookingMaintenanceService::class)->expire());
        $this->assertSame(0, app(BookingMaintenanceService::class)->expire());
        $this->assertSame(['EXPIRED', 'REFUNDED'], [$booking->fresh()->status, DB::table('booking_deposits')->find($deposit->id)->status]);
        $this->assertSame(['60000', '0'], [$this->wallet()['availableVnd'], $this->wallet()['heldVnd']]);
    }
}
