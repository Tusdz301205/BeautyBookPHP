<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

/**
 * The state of the deposit decides whether the salon may record money for a booking. While a direct deposit still awaits its
 * bank transfer nobody may collect or verify a payment (the receipt that follows would pay the same price twice); money that
 * was recorded anyway (data from before this rule) is never deleted: the late receipt is owed back and the case is listed.
 */
class DepositPaymentGateTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDirectDeposits();
    }

    private function collect(int $id, array $body = [], string $as = 'owner')
    {
        $this->actor($as);

        return $this->postJson('/api/v1/payments/collect', array_merge(['bookingId' => (string) $id, 'method' => 'CASH', 'idempotencyKey' => 'gate-'.bin2hex(random_bytes(6))], $body));
    }

    private function transactions(int $id)
    {
        return DB::table('payment_transactions')->where('booking_id', $id);
    }

    /** What the old behaviour allowed: a payment recorded while the deposit still awaits its transfer. */
    private function legacyPayment(int $id, string $method = 'CASH', string $status = 'VERIFIED', int $amount = 10000000): PaymentTransaction
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $tx = PaymentTransaction::create(['booking_id' => $id, 'amount_cents' => $amount, 'currency' => 'VND', 'method' => $method, 'status' => $status, 'idempotency_key' => 'legacy-'.bin2hex(random_bytes(6)),
            'created_by' => $owner->id, 'verified_by' => $status === 'VERIFIED' ? $owner->id : null, 'verified_at' => $status === 'VERIFIED' ? now() : null]);
        if ($status === 'VERIFIED') {
            DB::table('payment_ledger_entries')->insert(['booking_id' => $id, 'payment_transaction_id' => $tx->id, 'type' => 'PAYMENT_RECEIVED', 'direction' => 'CREDIT', 'amount_cents' => $amount,
                'idempotency_key' => 'ledger:PAYMENT_RECEIVED:'.$tx->id, 'actor_id' => $owner->id, 'created_at' => now()]);
        }

        return $tx;
    }

    public function test_nothing_can_be_collected_while_the_direct_deposit_awaits_its_transfer(): void
    {
        $this->connect();
        [$id, $deposit] = $this->booked();
        $this->assertSame('AWAITING_PAYMENT', $deposit->status);
        $this->assertSame('PENDING', DB::table('bookings')->where('id', $id)->value('status'));

        // The whole price, a part of it, by cash or by transfer, as owner or as receptionist: none of it is allowed yet.
        $this->collect($id)->assertStatus(409);
        $this->collect($id, ['amount' => '100000'])->assertStatus(409);
        $this->collect($id, ['method' => 'BANK_TRANSFER'])->assertStatus(409);
        $this->collect($id, [], 'receptionist')->assertStatus(409);
        $this->assertSame(0, $this->transactions($id)->count());
        $this->assertSame(0, DB::table('payment_ledger_entries')->where('booking_id', $id)->count());
        $this->assertSame('AWAITING_PAYMENT', $this->deposit($id)->status);
    }

    public function test_a_transfer_left_pending_before_the_rule_cannot_be_verified_while_the_deposit_awaits(): void
    {
        $this->connect();
        [$id] = $this->booked();
        $pending = $this->legacyPayment($id, 'BANK_TRANSFER', 'PENDING', 5000000);

        $this->actor('owner');
        $this->postJson("/api/v1/payments/transactions/{$pending->id}/verify", ['settlementReference' => 'FT-0001', 'evidence' => ['note' => 'sao ke']])->assertStatus(409);
        $this->assertSame('PENDING', $pending->fresh()->status, 'the pending transfer is kept as it was, for the owner to settle after the deposit');
        $this->assertSame(0, DB::table('payment_ledger_entries')->where('booking_id', $id)->count());
    }

    public function test_a_receipt_after_a_payment_recorded_before_this_rule_is_owed_back_and_nothing_is_deleted(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $stored = $this->legacyPayment($id);

        $this->pay($connection, $secret, $deposit, null, ['id' => 880001])->assertOk();

        // The deposit is not applied on top of a price already paid in full: the transfer is owed back, whole.
        $after = $this->deposit($id);
        $this->assertSame('AWAITING_PAYMENT', $after->status);
        $event = DB::table('sepay_webhook_events')->where('provider_transaction_id', 880001)->first();
        $this->assertSame('REFUND_REQUIRED', $event->processing);
        $refund = DB::table('deposit_refunds')->where('event_id', $event->id)->first();
        $this->assertSame(['STORE_PAYMENT_RECORDED', 'PENDING', (int) $deposit->amount_vnd], [$refund->reason, $refund->status, (int) $refund->amount_vnd]);
        $this->assertSame(1, $this->transactions($id)->count());
        $this->assertSame('VERIFIED', $stored->fresh()->status);
        $this->assertSame(1, DB::table('payment_ledger_entries')->where('booking_id', $id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'DEPOSIT_REFUND_REQUIRED')->where('entity_id', (string) $deposit->id)->count());

        // The same receipt delivered again changes nothing.
        $this->pay($connection, $secret, $deposit, null, ['id' => 880001])->assertOk();
        $this->assertSame(1, DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->count());
        $this->assertSame('AWAITING_PAYMENT', $this->deposit($id)->status);

        // The customer is told, and the owner sees the receipt among those to settle.
        $this->actor('owner');
        $listed = collect($this->getJson($this->conn())->assertOk()->json('receipts'))->firstWhere('providerTransactionId', '880001');
        $this->assertSame('REFUND_REQUIRED', $listed['processing']);
    }

    public function test_a_pending_transfer_recorded_before_this_rule_also_keeps_the_deposit_from_being_applied(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->legacyPayment($id, 'BANK_TRANSFER', 'PENDING', 5000000);

        $this->pay($connection, $secret, $deposit, null, ['id' => 880002])->assertOk();

        $this->assertSame('AWAITING_PAYMENT', $this->deposit($id)->status);
        $this->assertSame('REFUND_REQUIRED', DB::table('sepay_webhook_events')->where('provider_transaction_id', 880002)->value('processing'));
    }

    public function test_the_audit_command_lists_bookings_whose_money_and_deposit_disagree(): void
    {
        $this->connect();
        [$id] = $this->booked();
        $this->legacyPayment($id);

        $this->artisan('beautybook:payment-inconsistencies')->expectsOutputToContain('booking '.$id)->assertSuccessful();
        $this->assertSame(1, $this->transactions($id)->count(), 'the report never changes or deletes a transaction');
    }

    public function test_a_valid_receipt_still_pays_the_deposit_and_the_store_then_collects_only_the_remainder_after_arrival(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->assertSame('PAID', $this->deposit($id)->status);

        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->collect($id)->assertStatus(409);
        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
        $this->collect($id)->assertOk();
        $this->assertEquals((float) $deposit->remaining_vnd * 100, (float) $this->transactions($id)->sum('amount_cents'));
    }

    public function test_a_booking_without_a_deposit_is_collected_exactly_as_before(): void
    {
        // With direct deposits disabled, the booking holds no deposit at all.
        // Missing account setup in enabled mode must still block paid online bookings.
        config(['wallet.direct.enabled' => false]);
        $data = $this->bookingData(9);
        $id = (int) $this->book($data, null, 'gate-no-deposit-0001')->assertCreated()->json('id');
        $this->assertNull(DB::table('booking_deposits')->where('booking_id', $id)->first());

        $this->collect($id, ['amount' => '50000'])->assertSuccessful();
        $this->collect($id, ['amount' => '1000000'])->assertStatus(422);
        $this->assertSame(1, $this->transactions($id)->count());
    }

    public function test_a_booking_whose_deposit_expired_or_was_voided_cannot_be_collected_and_a_late_receipt_is_owed_back(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['expires_at' => now('UTC')->subMinute()]);
        DB::table('bookings')->where('id', $id)->update(['pending_expires_at' => now()->subMinute()]);
        app(\App\Services\Booking\BookingMaintenanceService::class)->expire();
        $this->assertSame('EXPIRED', $this->deposit($id)->status);

        $this->collect($id)->assertStatus(409);
        $this->pay($connection, $secret, $deposit, null, ['id' => 880003])->assertOk();
        $this->assertSame('LATE_PAYMENT', DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->value('reason'));
        $this->assertSame(0, $this->transactions($id)->count());
    }
}
