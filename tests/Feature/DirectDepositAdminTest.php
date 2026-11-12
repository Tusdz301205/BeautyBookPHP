<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

/**
 * The platform admin's view of direct deposits: refunds owed back and transfers nobody could apply, paged, filtered, read-only
 * for everyone else, with the admin's review of a transfer recorded in the audit log.
 */
class DirectDepositAdminTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDirectDeposits();
    }

    /** Three receipts that need a person: two wrong amounts on two bookings and one transfer without any code. */
    private function lab(): array
    {
        [$connection, $secret] = $this->connect();
        [$first, $depositA] = $this->booked('admin-lab-0001', 8);
        [$second, $depositB] = $this->booked('admin-lab-0002', 9);
        $this->pay($connection, $secret, $depositA, 50000, ['id' => 770001])->assertOk();          // wrong amount: owed back
        $this->pay($connection, $secret, $depositB, 10000, ['id' => 770002])->assertOk();          // wrong amount: owed back
        $this->transfer($connection, $secret, 'chuyen khoan khong ma', 25000, ['id' => 770003])->assertOk(); // nothing to match

        return [$first, $second];
    }

    private function listRefunds(array $query = [], string $as = 'admin')
    {
        $this->actor($as);

        return $this->getJson('/api/v1/payments/admin/deposits/refunds?'.http_build_query($query));
    }

    private function listReceipts(array $query = [], string $as = 'admin')
    {
        $this->actor($as);

        return $this->getJson('/api/v1/payments/admin/deposits/receipts?'.http_build_query($query));
    }

    public function test_only_the_platform_admin_may_read_the_lists(): void
    {
        $this->lab();
        foreach (['owner', 'receptionist', 'staff', 'customer'] as $role) {
            $this->listRefunds([], $role)->assertForbidden();
            $this->listReceipts([], $role)->assertForbidden();
        }
        $this->listRefunds()->assertOk();
        $this->listReceipts()->assertOk();
        $this->getJson('/api/v1/payments/admin/deposits/refunds')->assertOk();
    }

    public function test_refunds_are_listed_newest_first_with_their_business_booking_status_and_masked_destination(): void
    {
        [$first, $second] = $this->lab();
        $view = $this->listRefunds()->assertOk()->json();
        $this->assertSame([2, 1, 20, 2, false], [count($view['items']), $view['page'], $view['pageSize'], $view['total'], $view['hasMore']]);
        $newest = $view['items'][0];
        $this->assertSame([(string) $second, 'AMOUNT_MISMATCH', 'PENDING', '10000'], [$newest['bookingId'], $newest['reason'], $newest['status'], $newest['amountVnd']]);
        $this->assertNotEmpty($newest['bookingCode']);
        // created_at is kept in the application time zone: the instant shown is the real one, not seven hours later.
        $this->assertLessThan(120, abs(\Carbon\CarbonImmutable::parse($newest['createdAt'])->diffInSeconds(now('UTC'), false)), 'the refund was created moments ago');
        $this->assertNotEmpty($newest['businessName']);
        $this->assertNull($newest['destination']);

        // Once the customer says where to pay back, the admin sees the account with all but its last four digits hidden.
        $refund = DB::table('deposit_refunds')->where('id', $newest['id'])->first();
        $this->act($second, 'refund-destination', ['version' => $refund->version, 'refundId' => $refund->id, 'bankCode' => 'ACB', 'accountNumber' => '1234567890', 'accountName' => 'KHACH'], 'customer')->assertOk();
        $destination = $this->listRefunds()->json('items.0.destination');
        $this->assertSame(['ACB', '••••••7890', 'KHACH'], [$destination['bankCode'], $destination['accountNumber'], $destination['accountName']]);
        $this->assertStringNotContainsString('1234567890', json_encode($this->listRefunds()->json()));
    }

    public function test_the_lists_are_paged_and_filtered(): void
    {
        $this->lab();
        $one = $this->listRefunds(['pageSize' => 1])->assertOk()->json();
        $this->assertSame([1, 2, true], [count($one['items']), $one['total'], $one['hasMore']]);
        $two = $this->listRefunds(['pageSize' => 1, 'page' => 2])->assertOk()->json();
        $this->assertSame([1, false], [count($two['items']), $two['hasMore']]);
        $this->assertNotSame($one['items'][0]['id'], $two['items'][0]['id']);
        $this->assertSame([], $this->listRefunds(['pageSize' => 1, 'page' => 3])->json('items'));

        $this->assertSame(2, $this->listRefunds(['status' => 'PENDING'])->json('total'));
        $this->assertSame(0, $this->listRefunds(['status' => 'CONFIRMED'])->json('total'));
        $this->assertSame(2, $this->listRefunds(['reason' => 'AMOUNT_MISMATCH'])->json('total'));
        $this->assertSame(0, $this->listRefunds(['reason' => 'LATE_PAYMENT'])->json('total'));
        $this->listRefunds(['status' => 'NOPE'])->assertStatus(422);
        $this->listRefunds(['reason' => 'bad reason'])->assertStatus(422);
        $this->assertCount(2, $this->listRefunds(['pageSize' => 500])->json('items'), 'the page size is capped, never unbounded');
        $this->assertSame(100, $this->listRefunds(['pageSize' => 500])->json('pageSize'));
    }

    public function test_transfers_nobody_could_apply_are_listed_with_their_status_and_filtered(): void
    {
        $this->lab();
        $view = $this->listReceipts()->assertOk()->json();
        $this->assertSame(3, $view['total']);
        $byTransaction = collect($view['items'])->keyBy('providerTransactionId');
        $this->assertSame('UNMATCHED', $byTransaction['770003']['processing']);
        $this->assertSame('REFUND_REQUIRED', $byTransaction['770001']['processing']);
        $this->assertNull($byTransaction['770003']['bookingId']);
        $this->assertNotNull($byTransaction['770001']['bookingId']);
        $this->assertSame('25000', $byTransaction['770003']['amountVnd']);

        $this->assertSame(1, $this->listReceipts(['processing' => 'UNMATCHED'])->json('total'));
        $this->assertSame(2, $this->listReceipts(['processing' => 'REFUND_REQUIRED'])->json('total'));
        $this->assertGreaterThanOrEqual(1, $this->listReceipts(['processing' => 'VERIFIED'])->json('total'), 'the verification transfer of the connection');
        $this->listReceipts(['processing' => 'WHATEVER'])->assertStatus(422);
        $this->assertCount(1, $this->listReceipts(['pageSize' => 1])->json('items'));
    }

    public function test_the_admin_review_of_a_transfer_is_recorded_in_the_audit_log_and_shown(): void
    {
        $this->lab();
        $receipt = collect($this->listReceipts()->json('items'))->firstWhere('providerTransactionId', '770003');
        $this->assertNull($receipt['reviewedAt']);
        $this->assertSame([], $receipt['reviews']);

        $this->actor('owner');
        $this->postJson("/api/v1/payments/admin/deposits/receipts/{$receipt['id']}/review", ['note' => 'khach chuyen nham'])->assertForbidden();
        $this->actor('admin');
        $this->postJson("/api/v1/payments/admin/deposits/receipts/{$receipt['id']}/review", [])->assertStatus(422);
        $this->postJson("/api/v1/payments/admin/deposits/receipts/{$receipt['id']}/review", ['note' => 'khach chuyen nham', 'extra' => 1])->assertStatus(400);
        $this->postJson('/api/v1/payments/admin/deposits/receipts/999999/review', ['note' => 'khong co'])->assertNotFound();
        $this->postJson("/api/v1/payments/admin/deposits/receipts/{$receipt['id']}/review", ['note' => 'khach chuyen nham'])->assertOk()->assertJsonPath('success', true);
        $first = DB::table('sepay_webhook_events')->where('id', $receipt['id'])->value('reviewed_at');
        $this->assertNotNull($first);

        // A second note is added to the trail; the time of the first review stays.
        $this->postJson("/api/v1/payments/admin/deposits/receipts/{$receipt['id']}/review", ['note' => 'da lien he khach'])->assertOk();
        $this->assertSame($first, DB::table('sepay_webhook_events')->where('id', $receipt['id'])->value('reviewed_at'));
        $audit = DB::table('audit_logs')->where('action', 'DEPOSIT_RECEIPT_ADMIN_REVIEW')->where('entity_id', (string) $receipt['id'])->orderBy('id')->get();
        $this->assertSame(2, $audit->count());
        $this->assertSame(['khach chuyen nham', 'da lien he khach'], $audit->map(fn ($a) => json_decode($a->metadata, true)['note'])->all());
        $this->assertSame(\App\Models\User::where('email', 'admin@beautybook.local')->value('id'), (int) $audit[0]->user_id);

        $shown = collect($this->listReceipts()->json('items'))->firstWhere('providerTransactionId', '770003');
        $this->assertNotNull($shown['reviewedAt']);
        $this->assertSame(['khach chuyen nham', 'da lien he khach'], array_column($shown['reviews'], 'note'));
    }
}
