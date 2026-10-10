<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\CustomerProfile;
use App\Models\PaymentTransaction;
use App\Models\PaymentLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_exact_cents_partial_cash_and_pending_bank_reservations_cannot_overcollect(): void
    {
        $booking = $this->booking('0.35');
        $this->actor('owner');
        $cash = $this->send(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => 0.10, 'idempotency_key' => 'cash-one'])->assertCreated()->assertJsonPath('amount', '0.10')->assertJsonPath('status', 'VERIFIED')->json('id');
        $this->send(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => 0.10, 'idempotency_key' => 'cash-one'])->assertCreated();
        $bank = $this->send(route('finance.collect', $booking), ['method' => 'BANK_TRANSFER', 'amount' => '0.25', 'idempotency_key' => 'bank-one'])->assertCreated()->assertJsonPath('status', 'PENDING')->json('id');
        $this->send(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => '0.01', 'idempotency_key' => 'overcollect'])->assertUnprocessable();
        $this->assertDatabaseCount('payment_ledger_entries', 1);
        $this->send(route('finance.transaction', [$bank, 'verify']), ['settlement_reference' => 'BANK-123', 'evidence' => ['receipt' => 'Confirmed on statement']])->assertOk()->assertJsonPath('status', 'VERIFIED');
        $this->getJson(route('finance.show', $booking))->assertOk()->assertJsonPath('collected_cents', 35)->assertJsonPath('due_cents', 0)->assertJsonPath('pending_cents', 0);
        $this->assertDatabaseHas('payment_transactions', ['id' => $cash, 'amount_cents' => 10]);
        $this->assertSame(2, PaymentLedgerEntry::where('type','PAYMENT_RECEIVED')->count());
    }

    public function test_customer_foreign_owner_and_closed_booking_cannot_collect(): void
    {
        $booking = $this->booking();
        $data = ['method' => 'CASH', 'amount' => '1.00', 'idempotency_key' => 'access-collection'];
        $this->actor('customer');
        $this->send(route('finance.collect', $booking), $data)->assertForbidden();
        $this->actor('owner2');
        $this->send(route('finance.collect', $booking), $data)->assertForbidden();
        $this->getJson(route('finance.show', $booking))->assertForbidden();
        $this->actor('owner');
        $booking->update(['status' => 'CANCELLED']);
        $this->send(route('finance.collect', $booking), $data)->assertConflict();
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_reversal_is_append_only_once_and_idempotency_payload_is_checked(): void
    {
        $booking = $this->booking();
        $this->actor('owner');
        $id = $this->send(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => '1.00', 'idempotency_key' => 'reversal-cash'])->assertCreated()->json('id');
        $this->send(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => '0.50', 'idempotency_key' => 'reversal-cash'])->assertConflict();
        $data = ['reason' => 'Ghi nhận sai', 'idempotency_key' => 'reverse-one'];
        $this->send(route('finance.transaction', [$id, 'reverse']), $data)->assertOk()->assertJsonPath('status', 'REVERSED');
        $this->send(route('finance.transaction', [$id, 'reverse']), $data)->assertOk();
        $this->send(route('finance.transaction', [$id, 'reverse']), [...$data, 'idempotency_key' => 'reverse-again'])->assertConflict();
        $this->assertDatabaseHas('payment_transactions', ['id' => $id, 'status' => 'VERIFIED']);
        $this->assertSame(2, PaymentLedgerEntry::whereIn('type',['PAYMENT_RECEIVED','REVERSAL'])->count());
        $this->getJson(route('finance.show', $booking))->assertJsonPath('collected_cents', 0)->assertJsonPath('due_cents', 100);
        $entry = PaymentLedgerEntry::firstOrFail();
        $this->expectException(\LogicException::class);
        $entry->update(['amount_cents' => 999]);
    }

    public function test_refund_reservation_maker_checker_and_processing_are_idempotent(): void
    {
        $booking = $this->booking();
        $this->actor('owner');
        $id = $this->send(route('finance.collect', $booking), ['method' => 'CASH', 'amount' => '1.00', 'idempotency_key' => 'refund-cash'])->assertCreated()->json('id');
        $refund = $this->send(route('finance.transaction', [$id, 'refund']), ['amount' => '0.60', 'reason' => 'Hoàn một phần'])->assertOk()->json('id');
        $this->send(route('finance.transaction', [$id, 'refund']), ['amount' => '0.50', 'reason' => 'Vượt tiền giữ'])->assertUnprocessable();
        $this->send(route('finance.transaction', [$id, 'reverse']), ['reason' => 'Không được đảo tiền đang giữ', 'idempotency_key' => 'reverse-reserved'])->assertConflict();
        $this->send(route('finance.refund', [$refund, 'review']), ['approve' => true])->assertForbidden();
        $this->actor('admin');
        $this->send(route('finance.refund', [$refund, 'review']), ['approve' => true, 'note' => 'Đã kiểm tra'])->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->send(route('finance.refund', [$refund, 'process']), ['action' => 'CONFIRM', 'settlement_reference' => 'RF-1'])->assertConflict();
        $this->send(route('finance.refund', [$refund, 'process']), ['action' => 'START'])->assertOk()->assertJsonPath('status', 'PROCESSING');
        $this->send(route('finance.refund', [$refund, 'process']), ['action' => 'START'])->assertOk();
        $this->send(route('finance.refund', [$refund, 'process']), ['action' => 'CONFIRM'])->assertUnprocessable();
        $this->send(route('finance.refund', [$refund, 'process']), ['action' => 'CONFIRM', 'settlement_reference' => 'RF-1'])->assertOk()->assertJsonPath('status', 'REFUNDED');
        $this->send(route('finance.refund', [$refund, 'process']), ['action' => 'CONFIRM', 'settlement_reference' => 'RF-1'])->assertOk();
        $this->assertSame(2, PaymentLedgerEntry::whereIn('type',['PAYMENT_RECEIVED','REFUND'])->count());
        $this->getJson(route('finance.show', $booking))->assertJsonPath('net_cents', 40)->assertJsonPath('refunded_cents', 60)->assertJsonPath('due_cents',60)->assertJsonPath('collectable_cents',0);
        $this->actor('owner');
        $this->send(route('finance.transaction', [$id, 'refund']), ['amount' => '0.41', 'reason' => 'Vượt số đã thu'])->assertUnprocessable();
    }

    public function test_transfer_requires_proof_and_settlement_reference_cannot_be_reused(): void
    {
        $booking = $this->booking('2.00');
        $this->actor('receptionist');
        $first = $this->send(route('finance.collect', $booking), ['method' => 'BANK_TRANSFER', 'amount' => '1.00', 'idempotency_key' => 'bank-a'])->assertCreated()->json('id');
        $second = $this->send(route('finance.collect', $booking), ['method' => 'BANK_TRANSFER', 'amount' => '1.00', 'idempotency_key' => 'bank-b'])->assertCreated()->json('id');
        $this->send(route('finance.transaction', [$first, 'verify']), ['settlement_reference' => 'SHARED', 'evidence' => []])->assertUnprocessable();
        $data = ['settlement_reference' => 'SHARED', 'evidence' => ['receipt' => 'Bank receipt']];
        $this->send(route('finance.transaction', [$first, 'verify']), $data)->assertOk();
        $this->send(route('finance.transaction', [$second, 'verify']), $data)->assertConflict();
        $this->assertSame('PENDING', PaymentTransaction::findOrFail($second)->status);
        $this->assertSame(1, PaymentLedgerEntry::where('type','PAYMENT_RECEIVED')->count());
        $this->get(route('finance.show', $booking))->assertOk()->assertSee('Chứng từ xác minh');
    }

    private function actor(string $alias): void
    {
        $user = User::where('email', $alias.'@beautybook.local')->firstOrFail();
        $this->actingAs($user);
        $assignment = $user->roleAssignments()->first();
        $this->withSession(['workspace_assignment_id' => $assignment?->id]);
    }
    private function send(string $url, array $data)
    {
        return $this->withSession(['_token' => 'finance-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'finance-csrf'])->postJson($url, $data);
    }
    private function booking(string $amount = '1.00'): Booking
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        return Booking::create([
            'business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'customer_profile_id' => CustomerProfile::where('user_id', $customer->id)->value('id'), 'created_by_user_id' => $customer->id,
            'booking_code' => 'PAY-'.uniqid(), 'appointment_start_at' => now()->subDay(), 'appointment_end_at' => now()->subDay()->addHour(),
            'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $amount,
        ]);
    }
}
