<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/** Ordinary SePay endpoint: authenticated durable inbox only. It never credits a wallet or a booking (sepay-webhook.service.ts). */
class SepayInboxTest extends TestCase
{
    use RefreshDatabase;
    use WalletFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->configureLab();
    }

    private function body(array $patch = []): array
    {
        return array_merge(['id' => 5001, 'gateway' => 'ACB', 'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'), 'accountNumber' => '0000000001', 'subAccount' => null, 'code' => null,
            'content' => 'khach chuyen khoan', 'transferType' => 'in', 'transferAmount' => 150000, 'referenceCode' => 'FT1', 'description' => 'thu nghiem', 'accumulated' => 0], $patch);
    }

    public function test_a_valid_receipt_is_stored_once_for_review_and_credits_nothing_even_when_redelivered(): void
    {
        $invoice = $this->invoice(50000);
        $payload = $this->body(['content' => $invoice['code'].' nap vi', 'transferAmount' => 50000]);
        $this->deliver(self::ORDINARY_URL, $payload)->assertOk()->assertExactJson(['success' => true])->assertHeader('Cache-Control');
        $row = DB::table('sepay_webhook_events')->first();
        $this->assertSame(['LEGACY', 'REVIEW_REQUIRED', 'UNMATCHED', 'test'], [$row->channel, $row->status, $row->processing, $row->environment]);
        // Even a memo that carries a valid wallet code is only stored here: no credit, no ledger, invoice untouched.
        $this->assertSame('PENDING', DB::table('wallet_topups')->value('status'));
        $this->assertSame('0', $this->wallet()['availableVnd']);
        $this->assertSame(0, DB::table('wallet_transfers')->count());
        $this->assertSame(0, DB::table('payment_ledger_entries')->count());

        $this->deliver(self::ORDINARY_URL, $payload)->assertOk();
        $this->deliver(self::ORDINARY_URL, $payload, timestamp: time() + 5)->assertOk();
        $this->assertSame(1, DB::table('sepay_webhook_events')->count());
        // The same provider id with other content is an identity conflict and changes nothing.
        $this->deliver(self::ORDINARY_URL, array_merge($payload, ['transferAmount' => 49999]))->assertStatus(409);
        $this->assertSame(1, DB::table('sepay_webhook_events')->count());
    }

    public function test_outgoing_transfers_are_stored_as_ignored(): void
    {
        $this->deliver(self::ORDINARY_URL, $this->body(['transferType' => 'out']))->assertOk();
        $this->assertSame(['IGNORED_OUTGOING', 'UNMATCHED'], [DB::table('sepay_webhook_events')->value('status'), DB::table('sepay_webhook_events')->value('processing')]);
    }

    public function test_bad_signatures_stale_timestamps_wrong_receivers_and_wrong_formats_are_rejected_before_any_write(): void
    {
        $payload = $this->body();
        $raw = json_encode($payload);
        $this->deliver(self::ORDINARY_URL, $payload, secret: 'another-secret-another-secret-another-secret')->assertUnauthorized();
        $this->deliver(self::ORDINARY_URL, $payload, timestamp: time() - 301)->assertUnauthorized();
        $this->deliver(self::ORDINARY_URL, $payload, timestamp: time() + 301)->assertUnauthorized();
        $this->rawCall(self::ORDINARY_URL, $raw, ['CONTENT_TYPE' => 'application/json'])->assertUnauthorized();
        $this->rawCall(self::ORDINARY_URL, $raw, ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SEPAY_TIMESTAMP' => (string) time(), 'HTTP_X_SEPAY_SIGNATURE' => 'sha256='.str_repeat('0', 64)])->assertUnauthorized();
        $this->deliver(self::ORDINARY_URL, $payload, contentType: 'text/plain')->assertStatus(415);
        $this->deliver(self::ORDINARY_URL, ['accountNumber' => '9999999999'] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['gateway' => 'MBBank'] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['transferAmount' => 0] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['transferAmount' => '100000'] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['transferType' => 'sideways'] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['transactionDate' => '2026-02-30 10:00:00'] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['id' => 0] + $payload)->assertStatus(400);
        $this->deliver(self::ORDINARY_URL, ['content' => ''] + $payload)->assertStatus(400);
        $this->signedRaw(self::ORDINARY_URL, '[1,2,3]')->assertStatus(400);
        $this->signedRaw(self::ORDINARY_URL, '{not json')->assertStatus(400);
        $this->signedRaw(self::ORDINARY_URL, json_encode($payload + ['padding' => str_repeat('x', 33000)]))->assertStatus(413);
        $this->assertSame(0, DB::table('sepay_webhook_events')->count());
    }

    public function test_both_endpoints_fail_closed_when_they_are_not_configured(): void
    {
        foreach ([['enabled' => false], ['environment' => 'staging'], ['secret' => 'too-short'], ['gateway' => ''], ['account_number' => '']] as $override) {
            config(['services.sepay' => $override + config('services.sepay')]);
            $this->deliver(self::ORDINARY_URL, $this->body())->assertStatus(503);
            $this->configureLab();
        }
        foreach ([['isolated' => false], ['webhook_secret' => 'short'], ['bank_code' => 'Techcombank'], ['account_name' => ''], ['max_topup_vnd' => '5000000000']] as $override) {
            $this->configureLab($override);
            $this->deliver(self::LAB_URL, $this->body())->assertStatus(503);
        }
        $this->configureLab();
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';
        $this->deliver(self::LAB_URL, $this->body())->assertStatus(503);
        $this->assertSame(0, DB::table('sepay_webhook_events')->count());
    }

    public function test_the_two_channels_keep_separate_receipts_and_the_lab_never_adopts_an_ordinary_one(): void
    {
        config(['services.sepay.environment' => 'live']);
        $payload = $this->body(['id' => 77]);
        $this->deliver(self::ORDINARY_URL, $payload)->assertOk();
        // The same live bank transaction arriving on the private channel finds the ordinary receipt and refuses to reuse it.
        $this->deliver(self::LAB_URL, $payload)->assertStatus(409);
        $this->assertSame(1, DB::table('sepay_webhook_events')->count());
        $this->assertSame('LEGACY', DB::table('sepay_webhook_events')->value('channel'));
    }
}
