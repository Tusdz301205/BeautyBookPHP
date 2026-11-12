<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/** LIVE_LAB wallet: invoices, the private SePay channel, review reasons, expiry, history and scopes. Receipts are locally signed fixtures. */
class WalletLiveLabTest extends TestCase
{
    use RefreshDatabase;
    use WalletFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->configureLab();
    }

    public function test_a_top_up_is_credited_exactly_once_to_the_ledger_and_balance_even_when_redelivered(): void
    {
        $invoice = $this->invoice(50000);
        $this->assertSame('PENDING', $invoice['status']);
        $this->assertStringStartsWith('WL', $invoice['code']);
        $this->assertStringContainsString('vietqr.app/img', $invoice['qrUrl']);
        $this->assertStringContainsString('acc=0000000001', $invoice['qrUrl']);
        $this->assertStringContainsString('des='.$invoice['code'], $invoice['qrUrl']);
        $this->assertSame('live', $invoice['receiverSnapshot']['environment']);

        $payload = $this->payload($invoice, ['id' => 9001]);
        $this->deliver(self::LAB_URL, $payload)->assertOk()->assertExactJson(['success' => true]);
        $this->deliver(self::LAB_URL, $payload)->assertOk();
        $this->deliver(self::LAB_URL, $payload, timestamp: time() + 3)->assertOk();
        $row = DB::table('sepay_webhook_events')->first();
        $this->assertSame(['WALLET_LIVE_LAB', 'TOPUP_APPLIED', 'live'], [$row->channel, $row->processing, $row->environment]);
        $this->assertSame(1, DB::table('sepay_webhook_events')->count());
        $this->assertSame(1, DB::table('wallet_transfers')->where('kind', 'TOPUP')->count());
        $this->assertSame(['SUCCESS', 'sepay:live:acb:0000000001:9001'], [DB::table('wallet_topups')->value('status'), DB::table('wallet_topups')->value('receipt_identity')]);
        $this->assertSame($row->id, (int) DB::table('wallet_topups')->value('bank_event_id'));
        $wallet = $this->wallet();
        $this->assertSame(['50000', '0', 'LIVE_LAB'], [$wallet['availableVnd'], $wallet['heldVnd'], $wallet['scope']]);

        // Another bank transaction paying the same, already settled invoice is a second payment for review, never a second credit.
        $this->deliver(self::LAB_URL, $this->payload($invoice, ['id' => 9002]))->assertOk();
        $this->assertSame('ADDITIONAL_BANK_PAYMENT', DB::table('sepay_webhook_events')->where('provider_transaction_id', 9002)->value('review_reason'));
        $this->assertSame('50000', $this->wallet()['availableVnd']);
        // The ledger entry is an equal pair: clearing -> available.
        $transfer = DB::table('wallet_transfers')->where('kind', 'TOPUP')->first();
        $this->assertSame(['lab:credit-clearing', 'wallet:'.$transfer->wallet_id.':available', 50000, 50000], [$transfer->from_account, $transfer->to_account, (int) $transfer->amount_vnd, (int) $transfer->available_after_vnd]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'LIVE_LAB_WALLET_TOPUP')->count());
    }

    public function test_receipts_that_do_not_match_an_invoice_go_to_manual_review_and_credit_nothing(): void
    {
        $invoice = $this->invoice(50000);
        $cases = [
            ['id' => 1, 'content' => 'chuyen khoan khong ma', 'reason' => 'MISSING_OR_AMBIGUOUS_CODE', 'topUp' => false],
            ['id' => 2, 'content' => 'WL'.str_repeat('A', 24), 'reason' => 'INVOICE_NOT_FOUND', 'topUp' => false],
            ['id' => 3, 'content' => $invoice['code'].' WL'.str_repeat('B', 24), 'reason' => 'MISSING_OR_AMBIGUOUS_CODE', 'topUp' => false],
            ['id' => 4, 'transferAmount' => 49999, 'reason' => 'UNDERPAID', 'topUp' => true],
            ['id' => 5, 'transferAmount' => 50001, 'reason' => 'OVERPAID', 'topUp' => true],
            ['id' => 6, 'transactionDate' => now('Asia/Ho_Chi_Minh')->subMinutes(10)->format('Y-m-d H:i:s'), 'reason' => 'BANK_TRANSACTION_TIME_MISMATCH', 'topUp' => true],
            ['id' => 7, 'transactionDate' => now('Asia/Ho_Chi_Minh')->addMinutes(10)->format('Y-m-d H:i:s'), 'reason' => 'BANK_TRANSACTION_TIME_MISMATCH', 'topUp' => true],
            ['id' => 8, 'transferType' => 'out', 'reason' => 'OUTGOING_TRANSFER', 'topUp' => false],
            ['id' => 9, 'subAccount' => 'VA01', 'reason' => 'RECEIVING_ACCOUNT_MISMATCH', 'topUp' => false],
        ];
        $sent = [];
        foreach ($cases as $case) {
            $reason = $case['reason'];
            $expectsTopUp = $case['topUp'];
            unset($case['reason'], $case['topUp']);
            $sent[$case['id']] = $this->payload($invoice, $case);
            $this->deliver(self::LAB_URL, $sent[$case['id']])->assertOk();
            $event = DB::table('sepay_webhook_events')->where('provider_transaction_id', $case['id'])->first();
            $this->assertSame(['MANUAL_REVIEW', $reason], [$event->processing, $event->review_reason], 'case '.$case['id']);
            $this->assertSame($expectsTopUp ? (int) $invoice['id'] : null, $event->top_up_id === null ? null : (int) $event->top_up_id, 'case '.$case['id']);
        }
        $this->assertSame('PENDING', DB::table('wallet_topups')->value('status'));
        $this->assertSame('0', $this->wallet()['availableVnd']);
        $this->assertSame(0, DB::table('wallet_transfers')->count());
        // The customer sees the receipts waiting for reconciliation next to the invoice, and is told once per receipt.
        $listed = $this->getJson('/api/v1/wallet')->assertOk()->json('topUps.0.receipts');
        $this->assertEqualsCanonicalizing(['UNDERPAID', 'OVERPAID', 'BANK_TRANSACTION_TIME_MISMATCH', 'BANK_TRANSACTION_TIME_MISMATCH'], array_column($listed, 'reviewReason'));
        $this->assertSame(4, DB::table('notifications')->where('type', 'WALLET_TOPUP_REVIEW')->count());
        $this->deliver(self::LAB_URL, $sent[4])->assertOk();
        $this->assertSame(4, DB::table('notifications')->where('type', 'WALLET_TOPUP_REVIEW')->count());
        // A mismatching payload for the same bank id is an identity conflict.
        $this->deliver(self::LAB_URL, ['transferAmount' => 49998] + $sent[4])->assertStatus(409);
    }

    public function test_a_closed_account_an_unauthorised_user_and_a_disabled_lab_cannot_receive_money(): void
    {
        $invoice = $this->invoice(30000);
        User::where('email', 'customer@beautybook.local')->update(['is_active' => false]);
        // The bank calls without any browser session: drop the test's authenticated user so only the receipt rules apply.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->deliver(self::LAB_URL, $this->payload($invoice, ['id' => 21]))->assertOk();
        $this->assertSame('TEST_ACCOUNT_NOT_AUTHORIZED', DB::table('sepay_webhook_events')->where('provider_transaction_id', 21)->value('review_reason'));
        User::where('email', 'customer@beautybook.local')->update(['is_active' => true]);

        $this->configureLab(['enabled' => false]);
        $this->deliver(self::LAB_URL, $this->payload($invoice, ['id' => 22]))->assertOk();
        $this->assertSame('LIVE_LAB_DISABLED', DB::table('sepay_webhook_events')->where('provider_transaction_id', 22)->value('review_reason'));
        $this->configureLab(['user_ids' => '999999']);
        $this->deliver(self::LAB_URL, $this->payload($invoice, ['id' => 23]))->assertOk();
        $this->assertSame('TEST_ACCOUNT_NOT_AUTHORIZED', DB::table('sepay_webhook_events')->where('provider_transaction_id', 23)->value('review_reason'));
        $this->assertSame('PENDING', DB::table('wallet_topups')->value('status'));
        $this->assertSame(0, DB::table('wallet_transfers')->count());
    }

    public function test_a_receipt_for_an_expired_invoice_is_reviewed_and_a_later_reconcile_by_an_admin_applies_nothing_it_should_not(): void
    {
        $invoice = $this->invoice(40000);
        DB::table('wallet_topups')->update(['expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s')]);
        $this->deliver(self::LAB_URL, $this->payload($invoice, ['id' => 31]))->assertOk();
        $event = DB::table('sepay_webhook_events')->first();
        $this->assertSame(['MANUAL_REVIEW', 'INVOICE_EXPIRED_OR_CLOSED'], [$event->processing, $event->review_reason]);
        $this->assertSame('0', $this->wallet()['availableVnd']);
        // The customer's own read marks the stale invoice EXPIRED; late money is still not credited by reconciling.
        $this->assertSame('EXPIRED', $this->getJson('/api/v1/wallet')->json('topUps.0.status'));
        $this->assertNull($this->getJson('/api/v1/wallet')->json('topUps.0.qrUrl'));
        $this->actor('admin');
        $this->postJson('/api/v1/wallet/admin/receipts/'.$event->id.'/reconcile')->assertOk()->assertExactJson(['applied' => false]);
        $this->assertSame(0, DB::table('wallet_transfers')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'LIVE_LAB_RECEIPT_RECONCILE')->count());
        foreach (['customer', 'owner', 'receptionist'] as $actor) {
            $this->actor($actor);
            $this->postJson('/api/v1/wallet/admin/receipts/'.$event->id.'/reconcile')->assertForbidden();
        }
        $this->actor('admin');
        $this->postJson('/api/v1/wallet/admin/receipts/999999/reconcile')->assertNotFound();
        $overview = $this->getJson('/api/v1/wallet/admin')->assertOk()->json();
        $this->assertSame(['INVOICE_EXPIRED_OR_CLOSED'], array_column($overview['webhooks'], 'reviewReason'));
    }

    public function test_the_maintenance_job_expires_due_invoices_once_and_notifies(): void
    {
        $this->invoice(10000);
        $this->invoice(12000);
        DB::table('wallet_topups')->where('amount_vnd', 10000)->update(['expires_at' => now('UTC')->subSeconds(5)->format('Y-m-d H:i:s')]);
        $this->assertSame(1, \App\Services\Wallet\LiveWalletInbox::expireDue());
        $this->assertSame(0, \App\Services\Wallet\LiveWalletInbox::expireDue());
        $this->assertSame(['EXPIRED', 'PENDING'], DB::table('wallet_topups')->orderBy('amount_vnd')->pluck('status')->all());
        $this->assertSame(1, DB::table('notifications')->where('type', 'WALLET_TOPUP_EXPIRED')->count());
    }

    public function test_invoice_rules_idempotency_limits_ownership_and_validation(): void
    {
        $this->actor('customer');
        $key = $this->key();
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/wallet/topups', ['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE'])->assertOk()->json('topUp');
        $again = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/wallet/topups', ['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE'])->assertOk()->json('topUp');
        $this->assertSame($first['id'], $again['id']);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/wallet/topups', ['amountVnd' => '60000', 'provider' => 'SEPAY_LIVE'])->assertStatus(409);
        $this->assertSame(1, DB::table('wallet_topups')->count());

        $post = fn (array $body, ?string $k = null) => $this->withHeader('Idempotency-Key', $k ?? $this->key())->postJson('/api/v1/wallet/topups', $body);
        $post(['amountVnd' => '999', 'provider' => 'SEPAY_LIVE'])->assertStatus(400);
        $post(['amountVnd' => '200001', 'provider' => 'SEPAY_LIVE'])->assertStatus(400);
        $post(['amountVnd' => '50000', 'provider' => 'DEMO'])->assertStatus(400);
        $post(['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE', 'extra' => 1])->assertStatus(400);
        $post(['amountVnd' => '05000', 'provider' => 'SEPAY_LIVE'])->assertStatus(422);
        $post(['amountVnd' => 50000, 'provider' => 'SEPAY_LIVE'])->assertStatus(422);
        $this->withHeader('Idempotency-Key', 'short')->postJson('/api/v1/wallet/topups', ['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE'])->assertStatus(422);
        // Total pending + funded limit: 50.000 is committed, each 200.000 passes the per-top-up cap, the fifth exceeds the 1.000.000 total.
        foreach (range(1, 4) as $_) $post(['amountVnd' => '200000', 'provider' => 'SEPAY_LIVE'])->assertOk();
        $post(['amountVnd' => '200000', 'provider' => 'SEPAY_LIVE'])->assertStatus(400);

        // Only the owner of an invoice can read it; accounts outside the lab cannot create one.
        $this->getJson('/api/v1/wallet/topups/'.$first['id'])->assertOk()->assertJsonPath('topUp.code', $first['code']);
        $this->getJson('/api/v1/wallet/topups/999999')->assertNotFound();
        foreach (['owner', 'receptionist', 'admin'] as $actor) {
            $this->actor($actor);
            $this->getJson('/api/v1/wallet')->assertForbidden();
            $this->getJson('/api/v1/wallet/topups/'.$first['id'])->assertForbidden();
            $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/v1/wallet/topups', ['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE'])->assertForbidden();
        }
        $this->configureLab(['user_ids' => '999999']);
        $this->actor('customer');
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/v1/wallet/topups', ['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE'])->assertForbidden();
        $mine = $this->getJson('/api/v1/wallet')->assertOk()->json();
        $this->assertFalse($mine['liveLabAvailable']);
        $this->assertFalse($mine['liveLabEligible']);
        $this->assertNull($mine['topUps'][0]['qrUrl'], 'no QR when the lab is not available to this account');
    }

    public function test_demo_and_live_lab_wallets_are_separate_and_history_is_paginated_newest_first(): void
    {
        $this->fund(20000);
        $this->fund(30000);
        $this->actor('customer');
        $live = $this->getJson('/api/v1/wallet?scope=LIVE_LAB')->assertOk()->json();
        $this->assertSame(['50000', '2'], [$live['wallet']['availableVnd'], (string) $live['total']]);
        $this->assertSame(['TOPUP', 'TOPUP'], array_column($live['history'], 'kind'));
        $this->assertSame(['50000', '20000'], array_column($live['history'], 'availableAfterVnd'));
        $demo = $this->getJson('/api/v1/wallet?scope=DEMO')->assertOk()->json();
        $this->assertSame(['DEMO', '0', true], [$demo['wallet']['scope'], $demo['wallet']['availableVnd'], $demo['simulation']]);
        $this->assertSame([], $demo['history']);
        $this->assertNull($demo['topUps'][0] ?? null);
        $this->getJson('/api/v1/wallet?scope=OTHER')->assertStatus(400);
        $this->getJson('/api/v1/wallet?page=0')->assertStatus(400);
        $this->assertSame([], $this->getJson('/api/v1/wallet?page=2')->json('history'));
    }
}
