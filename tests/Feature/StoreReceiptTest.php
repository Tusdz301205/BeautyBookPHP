<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\Finance\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

/**
 * Recording money received at the salon has one rule whichever screen is used: the booking's checkout, the transaction
 * ledger and the deposit's "received at the store" mark always agree. The deposit view's `store-receipt` action and the
 * payment screen's `collect` are two doors into that one rule.
 */
class StoreReceiptTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDirectDeposits();
    }

    /** A checked-in booking whose 30% direct deposit was received. @return array{0:int,1:object} booking id and its deposit */
    private function checkedIn(string $key = 'store-booking-0001'): array
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked($key);
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();

        return [$id, $this->deposit($id)];
    }

    private function remainder(object $deposit): float
    {
        return (float) $deposit->remaining_vnd;
    }

    private function storeReceipt(int $id, ?string $reference = 'tien mat', string $as = 'owner', array $extra = [], ?int $version = null)
    {
        return $this->act($id, 'store-receipt', ['version' => $version ?? (int) $this->deposit($id)->version, 'reference' => $reference] + $extra, $as);
    }

    private function collect(int $id, array $body = [], string $as = 'owner')
    {
        $this->actor($as);

        return $this->postJson('/api/v1/payments/collect', array_merge(['bookingId' => (string) $id, 'method' => 'CASH', 'idempotencyKey' => 'collect-'.bin2hex(random_bytes(6))], $body));
    }

    private function checkout(int $id, string $as = 'owner'): array
    {
        $this->actor($as);

        return $this->getJson("/api/v1/payments/checkout/{$id}")->assertOk()->json();
    }

    /** @return array{0:float,1:float,2:float} total, paid (deposit included) and still due, in dong */
    private function money(int $id): array
    {
        $checkout = $this->checkout($id);

        return [(float) $checkout['totalAmount'], (float) $checkout['paidAmount'], (float) $checkout['remainingAmount']];
    }

    private function transactions(int $id)
    {
        return DB::table('payment_transactions')->where('booking_id', $id);
    }

    // ---- the store-receipt door ---------------------------------------------------------------------------------------

    public function test_recording_the_remainder_at_the_store_settles_checkout_and_posts_one_transaction_and_ledger_entry(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->amount_vnd, $this->remainder($deposit)], $this->money($id), 'before: only the deposit is paid');

        $this->storeReceipt($id, 'phieu thu 0042')->assertOk()->assertJsonPath('success', true);

        // The payment screen sees the same money: nothing left to collect, and no second collection is possible.
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
        $transaction = $this->transactions($id)->first();
        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertSame(['CASH', 'VERIFIED', (int) round($this->remainder($deposit) * 100)], [$transaction->method, $transaction->status, (int) $transaction->amount_cents]);
        $this->assertSame('phieu thu 0042', json_decode($transaction->evidence, true)['reference']);
        $this->assertSame(1, DB::table('payment_ledger_entries')->where('booking_id', $id)->where('type', 'PAYMENT_RECEIVED')->where('payment_transaction_id', $transaction->id)->count());
        $after = $this->deposit($id);
        $this->assertSame(['phieu thu 0042'], [$after->store_reference]);
        $this->assertNotNull($after->store_received_at);
        $this->assertGreaterThan((int) $deposit->version, (int) $after->version);
        $audit = DB::table('audit_logs')->where('entity_type', 'Payment')->where('entity_id', (string) $transaction->id)->get();
        $this->assertNotEmpty($audit, 'the receipt is audited against the transaction it created');
        $this->assertStringContainsString('phieu thu 0042', $audit->pluck('metadata')->implode(' '));

        $this->collect($id, ['amount' => '1000'])->assertStatus(422);
        $this->collect($id)->assertStatus(422);
        $this->assertSame(1, $this->transactions($id)->count(), 'a second collection is refused, not stacked');
    }

    public function test_recording_by_bank_transfer_keeps_the_method_and_the_reference(): void
    {
        [$id, $deposit] = $this->checkedIn();

        $this->storeReceipt($id, 'FT26111200123', 'owner', ['method' => 'BANK_TRANSFER'])->assertOk();

        $transaction = $this->transactions($id)->first();
        $this->assertSame(['BANK_TRANSFER', 'VERIFIED', 'FT26111200123'], [$transaction->method, $transaction->status, $transaction->settlement_reference]);
        $this->assertEquals((float) $deposit->total_vnd, $this->money($id)[1]);
        $this->storeReceipt($id, 'x', 'owner', ['method' => 'CHEQUE'], (int) $this->deposit($id)->version)->assertStatus(422);
    }

    public function test_a_repeated_receipt_replays_and_a_different_one_for_the_same_booking_is_refused(): void
    {
        [$id] = $this->checkedIn();
        $version = (int) $this->deposit($id)->version;
        $this->storeReceipt($id, 'phieu 1', 'owner', [], $version)->assertOk();
        $after = $this->deposit($id);

        // The same request again, even with the now stale version: same answer, nothing posted twice.
        $this->storeReceipt($id, 'phieu 1', 'owner', [], $version)->assertOk();
        $this->storeReceipt($id, 'phieu 1', 'receptionist', [], (int) $after->version)->assertOk();
        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertSame(1, DB::table('payment_ledger_entries')->where('booking_id', $id)->where('type', 'PAYMENT_RECEIVED')->count());
        $this->assertEquals((int) $after->version, (int) $this->deposit($id)->version, 'a replay does not move the version');

        // Another reference is a different claim about money already recorded.
        $this->storeReceipt($id, 'phieu 2', 'owner', [], (int) $after->version)->assertStatus(409);
        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertSame('phieu 1', $this->deposit($id)->store_reference);
    }

    public function test_the_receipt_needs_the_current_version_and_a_booking_that_is_eligible(): void
    {
        [$id] = $this->checkedIn();
        $version = (int) $this->deposit($id)->version;
        $this->storeReceipt($id, 'phieu', 'owner', [], $version - 1)->assertStatus(409);
        $this->act($id, 'store-receipt', ['reference' => 'phieu'], 'owner')->assertStatus(400);
        $this->assertSame(0, $this->transactions($id)->count());

        $this->storeReceipt($id, '   ')->assertStatus(422);
        $this->assertSame(0, $this->transactions($id)->count());
    }

    // ---- the payment-screen door -------------------------------------------------------------------------------------

    public function test_collecting_from_the_payment_screen_first_closes_the_store_receipt_door(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $key = 'collect-from-screen-0001';
        $first = $this->collect($id, ['idempotencyKey' => $key])->assertOk()->json();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
        $recorded = $this->deposit($id);
        $this->assertNotNull($recorded->store_received_at);

        // The deposit view no longer offers the receipt, and asking for it anyway is refused without a second posting.
        $this->assertFalse($this->depositView($id, 'owner')->json('canRecordStore'));
        $this->storeReceipt($id, 'phieu', 'owner', [], (int) $recorded->version)->assertStatus(409);
        $this->assertSame(1, $this->transactions($id)->count());

        // The same collect request resent is the same transaction, not a second payment.
        $this->collect($id, ['idempotencyKey' => $key])->assertOk()->assertJsonPath('id', $first['id']);
        $this->assertSame(1, $this->transactions($id)->count());
    }

    public function test_a_partial_collection_then_the_store_receipt_records_only_what_is_still_due(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $remainder = $this->remainder($deposit);
        $part = round($remainder / 4);
        $this->collect($id, ['amount' => (string) $part])->assertOk();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->amount_vnd + $part, $remainder - $part], $this->money($id));

        $this->storeReceipt($id)->assertOk();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
        $this->assertSame(2, $this->transactions($id)->count());
        $this->assertEquals($remainder * 100, (float) $this->transactions($id)->sum('amount_cents'), 'the two receipts add up to the remainder, never more');
    }

    public function test_over_collecting_and_receipts_while_a_transfer_is_unverified_are_refused(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $this->collect($id, ['amount' => (string) ($this->remainder($deposit) + 1000)])->assertStatus(422);
        $this->collect($id, ['method' => 'BANK_TRANSFER'])->assertOk()->assertJsonPath('status', 'PENDING');
        $this->assertSame(0, (int) DB::table('payment_ledger_entries')->where('booking_id', $id)->count());

        // The money is claimed by a pending transfer: the store cannot also say it was received in hand.
        $this->storeReceipt($id)->assertStatus(409);
        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertNull($this->deposit($id)->store_received_at);
    }

    // ---- who may record it ---------------------------------------------------------------------------------------------

    public function test_only_the_owner_or_a_receptionist_of_that_branch_may_record_it(): void
    {
        [$id] = $this->checkedIn();
        $this->storeReceipt($id, 'phieu', 'customer')->assertForbidden();
        foreach (['staff', 'owner2', 'admin'] as $other) {
            $status = $this->storeReceipt($id, 'phieu', $other)->status();
            $this->assertContains($status, [403, 404], $other.' got '.$status);
        }
        // The receptionist is moved to a different branch (the scope key must follow, or the schema refuses the row).
        $other = Branch::where('slug', '!=', 'tan-tao')->firstOrFail();
        DB::table('role_assignments')->where('user_id', \App\Models\User::where('email', 'receptionist@beautybook.local')->value('id'))
            ->update(['business_id' => $other->business_id, 'branch_id' => $other->id, 'scope_key' => 'branch:'.$other->business_id.':'.$other->id]);
        $status = $this->storeReceipt($id, 'phieu', 'receptionist')->status();
        $this->assertContains($status, [403, 404], 'a receptionist of another branch got '.$status);
        $this->assertSame(0, $this->transactions($id)->count());
        $this->assertNull($this->deposit($id)->store_received_at);
    }

    // ---- undoing it ------------------------------------------------------------------------------------------------------

    public function test_reversing_the_recorded_payment_reopens_the_booking_for_cancelling_and_for_recording(): void
    {
        [$id] = $this->checkedIn();
        $this->storeReceipt($id)->assertOk();
        $transaction = $this->transactions($id)->first();
        $this->moveStatus($id, 'CANCELLED')->assertStatus(409);

        $this->actor('owner');
        $this->postJson("/api/v1/payments/transactions/{$transaction->id}/reverse", ['reason' => 'ghi nham so tien', 'idempotencyKey' => 'reverse-store-0001'])->assertOk();
        $this->assertSame(0.0, (float) $this->checkout($id)['paidAmount'] - (float) $this->deposit($id)->amount_vnd, 'only the deposit remains paid');
        $this->assertNull($this->deposit($id)->store_received_at);
        $this->assertTrue($this->depositView($id, 'owner')->json('canRecordStore'));

        // A correct receipt can be recorded again after the reversal, as a new transaction.
        $this->storeReceipt($id, 'phieu dung')->assertOk();
        $this->assertSame(3, $this->transactions($id)->count());
        $this->assertSame(2, DB::table('payment_ledger_entries')->where('booking_id', $id)->where('type', 'PAYMENT_RECEIVED')->count());
        $this->storeReceipt($id, 'phieu dung')->assertOk();
        $this->assertSame(3, $this->transactions($id)->count(), 'the repeat of the new receipt replays too');
    }

    public function test_reversing_it_lets_the_booking_be_cancelled_and_the_deposit_resolved(): void
    {
        [$id] = $this->checkedIn();
        $this->storeReceipt($id)->assertOk();
        $this->actor('owner');
        $this->postJson('/api/v1/payments/transactions/'.$this->transactions($id)->value('id').'/reverse', ['reason' => 'khach khong tra', 'idempotencyKey' => 'reverse-store-0002'])->assertOk();

        $this->moveStatus($id, 'CANCELLED')->assertOk();
        $this->assertNotSame('PAID', $this->deposit($id)->status, 'cancelling resolves the deposit instead of leaving it open');
    }

    public function test_refunding_the_whole_store_payment_clears_the_mark_so_the_booking_can_be_cancelled(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $this->storeReceipt($id)->assertOk();
        $transaction = $this->transactions($id)->first();

        $this->actor('owner');
        $refund = $this->postJson("/api/v1/payments/{$transaction->id}/refund-requests", ['amount' => (string) $this->remainder($deposit), 'reason' => 'tra lai tien khach'])->assertOk()->json();
        $this->actor('admin');
        $this->patchJson("/api/v1/payments/refunds/{$refund['id']}/review", ['approve' => true])->assertOk();
        $this->postJson("/api/v1/payments/refunds/{$refund['id']}/process", ['action' => 'START'])->assertOk();
        $this->assertNotNull($this->deposit($id)->store_received_at, 'a refund that has not been paid out changes nothing yet');
        $this->postJson("/api/v1/payments/refunds/{$refund['id']}/process", ['action' => 'CONFIRM', 'settlementReference' => 'RF-0001'])->assertOk();

        $this->assertNull($this->deposit($id)->store_received_at, 'the store money went back to the customer');
        // Refunded money stays due but is not collected a second time (the original payment contract), so nothing is offered to record.
        $this->assertFalse($this->depositView($id, 'owner')->json('canRecordStore'));
        $this->assertEquals((float) $this->checkout($id)['remainingAmount'], $this->remainder($deposit));
        $this->assertSame($transaction->id, $this->transactions($id)->first()->id);
        $this->moveStatus($id, 'CANCELLED')->assertOk();
    }

    // ---- data recorded before this rule ----------------------------------------------------------------------------

    public function test_a_receipt_recorded_before_this_rule_counts_as_paid_and_is_turned_into_a_transaction_on_request(): void
    {
        [$id, $deposit] = $this->checkedIn();
        // The old behaviour only stamped the deposit: no transaction, no ledger entry.
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['store_received_at' => now('UTC')->subMinutes(5)->format('Y-m-d H:i:s'), 'store_received_by_id' => \App\Models\User::where('email', 'owner@beautybook.local')->value('id'),
            'store_reference' => 'tien mat cu', 'version' => $deposit->version + 1]);
        $this->assertSame(0, $this->transactions($id)->count());

        // Checkout already treats the remainder as received, so no one is asked to collect it again.
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
        $this->collect($id)->assertStatus(422);
        $this->assertSame(0, $this->transactions($id)->count());

        // Recording it again with the original reference completes the books once, however often it is repeated.
        $this->storeReceipt($id, 'tien mat cu')->assertOk();
        $this->storeReceipt($id, 'tien mat cu')->assertOk();
        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertSame(1, DB::table('payment_ledger_entries')->where('booking_id', $id)->where('type', 'PAYMENT_RECEIVED')->count());
        $transaction = $this->transactions($id)->first();
        $this->assertSame([(int) round($this->remainder($deposit) * 100), 'VERIFIED'], [(int) $transaction->amount_cents, $transaction->status]);
        $this->assertSame('LEGACY_STORE_RECEIPT', json_decode($transaction->evidence, true)['source']);
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
    }

    public function test_the_reconcile_command_turns_every_old_receipt_into_a_transaction_once(): void
    {
        [$id, $deposit] = $this->checkedIn();
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['store_received_at' => now('UTC')->format('Y-m-d H:i:s'), 'store_received_by_id' => \App\Models\User::where('email', 'owner@beautybook.local')->value('id'), 'store_reference' => 'cu']);

        $this->artisan('beautybook:reconcile-store-receipts')->assertSuccessful();
        $this->artisan('beautybook:reconcile-store-receipts')->assertSuccessful();

        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertSame(1, DB::table('payment_ledger_entries')->where('booking_id', $id)->count());
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
        $this->assertSame(PaymentService::class, get_class(app(PaymentService::class)));
    }

    public function test_a_wallet_funded_deposit_is_recorded_by_the_same_rule(): void
    {
        $this->configureLab();
        $data = $this->bookingData();
        $terms = $this->terms($data)['terms'];
        $this->fund($terms['depositVnd']);
        $id = (int) $this->book($data, $terms['quote'], 'store-wallet-0001')->assertCreated()->json('id');
        $this->assertSame('HELD', $this->deposit($id)->status);
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
        $deposit = $this->deposit($id);

        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->amount_vnd, $this->remainder($deposit)], $this->money($id));
        $this->storeReceipt($id, 'phieu vi')->assertOk();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->total_vnd, 0.0], $this->money($id));
        $this->assertSame(1, $this->transactions($id)->count());
        $this->storeReceipt($id, 'phieu vi')->assertOk();
        $this->assertSame(1, $this->transactions($id)->count());
        $this->collect($id)->assertStatus(422);
        $this->moveStatus($id, 'CANCELLED')->assertStatus(409);
    }

    // ---- the deposit view -------------------------------------------------------------------------------------------------

    public function test_the_booking_detail_carries_the_branch_rules_and_its_check_in_gate_agrees_with_the_server(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->move($id, now('UTC')->addMinutes(60), now('UTC')->addMinutes(120));
        $detail = fn () => $this->getJson("/api/v1/bookings/{$id}")->assertOk()->json();
        $setEarly = function (int $minutes) {
            $branch = $this->branchId();
            DB::table('beautybook_branch_settings')->insertOrIgnore(['branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('beautybook_branch_settings')->where('branch_id', $branch)->update(['booking_policy' => json_encode(['earlyCheckInMinutes' => $minutes, 'gracePeriodMinutes' => 20, 'rescheduleHours' => 6])]);
        };

        $setEarly(30);
        $this->actor('owner');
        $view = $detail();
        $this->assertSame([30, 20, 6], [$view['policy']['earlyCheckInMinutes'], $view['policy']['gracePeriodMinutes'], $view['policy']['rescheduleHours']]);
        $this->assertFalse($view['transitionAvailability']['CHECKED_IN']['allowed'], 'one hour before, with a 30-minute window');
        $this->assertSame('Chỉ được check-in từ 30 phút trước giờ hẹn.', $view['transitionAvailability']['CHECKED_IN']['reason']);
        $this->moveStatus($id, 'CHECKED_IN')->assertStatus(409);

        $setEarly(90);
        $this->actor('owner');
        $view = $detail();
        $this->assertTrue($view['transitionAvailability']['CHECKED_IN']['allowed'], 'the screen offers check-in exactly when the server accepts it');
        $this->assertSame(CarbonImmutable::parse($view['appointmentStartAt'])->subMinutes(90)->toIso8601String(), CarbonImmutable::parse($view['policy']['checkInFrom'])->toIso8601String());
        $this->assertSame(CarbonImmutable::parse($view['appointmentStartAt'])->addMinutes(20)->toIso8601String(), CarbonImmutable::parse($view['policy']['noShowAfter'])->toIso8601String());
        $this->assertSame(CarbonImmutable::parse($view['appointmentStartAt'])->subHours(6)->toIso8601String(), CarbonImmutable::parse($view['policy']['rescheduleUntil'])->toIso8601String());
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
    }

    // ---- the deposit view: what is shown must be what the action will accept ---------------------------------------------------

    private function payment(int $id, string $as = 'owner'): array
    {
        return $this->depositView($id, $as)->assertOk()->json('payment');
    }

    public function test_a_partial_collection_keeps_the_record_action_offered_and_the_view_separates_each_amount(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $this->assertTrue($this->depositView($id, 'owner')->assertOk()->json('canRecordStore'));

        $this->collect($id, ['amount' => '30000'])->assertOk();
        // The deposit view used to hide the action from here on (the booking was already stamped) although 96.000 is still collectable.
        $this->assertTrue($this->depositView($id, 'owner')->assertOk()->json('canRecordStore'), 'a partial collection leaves the rest collectable, so the action stays offered');
        $view = $this->depositView($id, 'owner')->assertOk()->json();
        $this->assertEquals([30000, 0, 96000, 96000, 126000], [(float) $view['payment']['storeCollectedVnd'], (float) $view['payment']['pendingVnd'], (float) $view['payment']['dueVnd'], (float) $view['payment']['collectableVnd'],
            (float) $view['payment']['initialRemainingVnd']], 'the remainder in the deposit terms (126.000) is not the balance now (96.000)');
        $this->storeReceipt($id, 'phan con lai')->assertOk();

        $view = $this->depositView($id, 'owner')->assertOk()->json();
        $this->assertFalse($view['canRecordStore']);
        $this->assertEquals([126000, 0, 0], [(float) $view['payment']['storeCollectedVnd'], (float) $view['payment']['dueVnd'], (float) $view['payment']['collectableVnd']]);
    }

    public function test_the_action_is_offered_exactly_when_the_backend_would_accept_it(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->assertFalse($this->depositView($id, 'owner')->json('canRecordStore'), 'the deposit has not been received');
        $this->storeReceipt($id)->assertStatus(409);

        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->assertFalse($this->depositView($id, 'owner')->json('canRecordStore'), 'the customer has not arrived');
        $this->storeReceipt($id)->assertStatus(409);

        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
        $this->assertTrue($this->depositView($id, 'owner')->json('canRecordStore'));
        $this->assertFalse($this->depositView($id, 'customer')->json('canRecordStore'));
        $this->assertNull($this->depositView($id, 'customer')->json('payment'), 'the customer does not see the salon payment book');

        // A transfer waiting for verification takes the whole remainder: nothing more can be recorded, and the view says why.
        $this->collect($id, ['method' => 'BANK_TRANSFER'])->assertOk()->assertJsonPath('status', 'PENDING');
        $view = $this->depositView($id, 'owner')->json();
        $this->assertFalse($view['canRecordStore']);
        $this->assertEquals([126000, 0], [(float) $view['payment']['pendingVnd'], (float) $view['payment']['collectableVnd']]);
        $this->storeReceipt($id)->assertStatus(409);
    }

    // ---- several payments, several methods -------------------------------------------------------------------------------------

    public function test_cash_for_a_part_then_a_transfer_for_the_rest_is_summarised_per_method_with_each_own_reference(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $this->collect($id, ['amount' => '30000', 'evidence' => ['reference' => 'phieu tien mat 1']])->assertOk();
        $this->storeReceipt($id, 'FT26111200777', 'receptionist', ['method' => 'BANK_TRANSFER'])->assertOk();

        $payment = $this->payment($id);
        $this->assertEquals(126000, (float) $payment['storeCollectedVnd']);
        $by = collect($payment['breakdown'])->mapWithKeys(fn ($row) => [$row['method'] => (float) $row['amountVnd']])->all();
        $this->assertEquals(['CASH' => 30000.0, 'BANK_TRANSFER' => 96000.0], $by, 'the total is not given the method of the last payment');
        $this->assertCount(2, $payment['entries']);
        [$cash, $bank] = $payment['entries'];
        $this->assertSame(['CASH', 'BANK_TRANSFER'], [$cash['method'], $bank['method']]);
        $this->assertSame(['phieu tien mat 1', 'FT26111200777'], [$cash['reference'], $bank['reference']], 'each payment keeps its own reference');
        $this->assertEquals([30000, 96000], [(float) $cash['amountVnd'], (float) $bank['amountVnd']]);
        $this->assertSame('Chủ BeautyBook Demo', $cash['recordedBy']['name']);
        $this->assertSame('Lễ tân Demo', $bank['recordedBy']['name']);
        $this->assertNotNull($bank['recordedAt']);
        $this->assertSame([false, false], [$cash['reversed'], $bank['reversed']]);
    }

    public function test_reversing_and_refunding_a_payment_update_the_summary_and_keep_the_trail(): void
    {
        [$id, $deposit] = $this->checkedIn();
        $this->collect($id, ['amount' => '30000'])->assertOk();
        $this->storeReceipt($id, 'FT-REV-1', 'owner', ['method' => 'BANK_TRANSFER'])->assertOk();
        $cashId = (int) $this->transactions($id)->where('method', 'CASH')->value('id');
        $bankId = (int) $this->transactions($id)->where('method', 'BANK_TRANSFER')->value('id');

        // Part of the cash is refunded (the booking is paid in full, so a refund may be asked): the summary follows.
        $this->actor('owner');
        $refund = $this->postJson("/api/v1/payments/{$cashId}/refund-requests", ['amount' => '10000', 'reason' => 'giam gia'])->assertOk()->json();
        $this->actor('admin');
        $this->patchJson("/api/v1/payments/refunds/{$refund['id']}/review", ['approve' => true])->assertOk();
        $this->postJson("/api/v1/payments/refunds/{$refund['id']}/process", ['action' => 'START'])->assertOk();
        $this->postJson("/api/v1/payments/refunds/{$refund['id']}/process", ['action' => 'CONFIRM', 'settlementReference' => 'RF-9'])->assertOk();
        $payment = $this->payment($id);
        $this->assertEquals(116000, (float) $payment['storeCollectedVnd']);
        $this->assertEquals(['CASH' => 20000.0, 'BANK_TRANSFER' => 96000.0], collect($payment['breakdown'])->mapWithKeys(fn ($r) => [$r['method'] => (float) $r['amountVnd']])->all());
        $this->assertEquals(10000, (float) collect($payment['entries'])->firstWhere('id', $cashId)['refundedVnd']);

        // Then the transfer is reversed: its entry stays in the list, marked, with who undid it and when; only the cash remains.
        $this->actor('owner');
        $this->postJson("/api/v1/payments/transactions/{$bankId}/reverse", ['reason' => 'sai so tien', 'idempotencyKey' => 'reverse-bank-0001'])->assertOk();
        $payment = $this->payment($id);
        $this->assertEquals(20000, (float) $payment['storeCollectedVnd']);
        $this->assertEquals(['CASH' => 20000.0], collect($payment['breakdown'])->mapWithKeys(fn ($r) => [$r['method'] => (float) $r['amountVnd']])->all());
        $bank = collect($payment['entries'])->firstWhere('id', $bankId);
        $this->assertTrue($bank['reversed']);
        $this->assertSame('FT-REV-1', $bank['reference'], 'the reversed payment keeps its own reference');
        $this->assertSame('Chủ BeautyBook Demo', $bank['reversedBy']['name']);
        $this->assertNotNull($bank['reversedAt']);
        $this->assertEquals(96000, (float) $payment['collectableVnd'], 'the reversed transfer can be collected again; the refunded cash is not collected a second time');
        $this->assertTrue($this->depositView($id, 'owner')->json('canRecordStore'));

        // The new receipt after the reversal is a new entry with its own reference; the old reference is never attached to it.
        $this->storeReceipt($id, 'FT-NEW-2', 'owner', ['method' => 'BANK_TRANSFER'])->assertOk();
        $payment = $this->payment($id);
        $this->assertSame(['FT-REV-1', 'FT-NEW-2'], collect($payment['entries'])->where('method', 'BANK_TRANSFER')->pluck('reference')->values()->all());
        $this->assertSame([true, false], collect($payment['entries'])->where('method', 'BANK_TRANSFER')->pluck('reversed')->values()->all());
    }

    public function test_a_receipt_recorded_before_this_rule_appears_as_a_labelled_legacy_entry_with_its_own_reference(): void
    {
        [$id, $deposit] = $this->checkedIn();
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['store_received_at' => now('UTC')->subHour()->format('Y-m-d H:i:s'), 'store_received_by_id' => \App\Models\User::where('email', 'owner@beautybook.local')->value('id'), 'store_reference' => 'tien mat cu']);

        $payment = $this->payment($id);
        $this->assertCount(1, $payment['entries']);
        $this->assertSame([true, 'tien mat cu', 'CASH'], [$payment['entries'][0]['legacy'], $payment['entries'][0]['reference'], $payment['entries'][0]['method']]);
        $this->assertEquals(126000, (float) $payment['storeCollectedVnd']);
        $this->assertFalse($this->depositView($id, 'owner')->json('canRecordStore'));
    }
}
