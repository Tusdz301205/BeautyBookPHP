<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Services\Booking\BookingMaintenanceService;
use App\Services\Payments\DirectDeposit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

/**
 * Direct business deposits (deposit-core.ts, direct-deposit.{controller,service}.ts): the owner's receiving account is
 * verified by a signed transfer, the customer pays it after booking, one signed receipt pays one deposit, and every
 * other outcome (wrong amount, repeat, late, unavailable) becomes a refund owed back to the payer. The platform holds nothing.
 */
class DirectDepositTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['beautybook.legacy_ui' => true, 'wallet.public_wallet' => false, 'wallet.booking_deposit_enabled' => false]);
        $this->prepareDirectDeposits();
    }

    // ---- connections ----------------------------------------------------------------------------------------------

    public function test_the_owner_creates_a_connection_whose_secret_is_shown_once_and_stored_encrypted(): void
    {
        config(['wallet.direct.enabled' => false]);
        $this->create()->assertStatus(503);
        config(['wallet.direct.enabled' => true]);
        $created = $this->create()->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $created['secret']);
        $this->assertSame('/api/v1/payments/sepay/connections/'.$created['id'].'/webhook', $created['webhookPath']);
        $this->assertSame(['SETUP', false, 'CONG TY THU NGHIEM', 'test'], [$created['status'], $created['enabled'], $created['accountName'], $created['environment']]);
        $this->assertMatchesRegularExpression('/^GS[A-F0-9]{24}$/', $created['verificationCode']);

        $stored = DB::table('payment_connections')->where('id', $created['id'])->value('encrypted_secret');
        $this->assertStringNotContainsString($created['secret'], $stored);
        $this->assertSame($created['secret'], DirectDeposit::open($stored, (int) $created['id']), 'the secret opens with the right row id');
        $this->assertSame(503, $this->catchStatus(fn () => DirectDeposit::open($stored, (int) $created['id'] + 1)), 'a sealed secret cannot be moved to another connection');
        config(['wallet.direct.encryption_key' => '']);
        $this->create()->assertStatus(503);
        config(['wallet.direct.encryption_key' => self::KEY]);

        $this->actor('owner');
        $list = $this->getJson($this->conn())->assertOk()->json();
        $this->assertStringNotContainsString($created['secret'], json_encode($list), 'the list never returns the secret');
        $this->assertSame(DirectDeposit::BANKS, $list['banks']);
    }

    private function catchStatus(callable $call): int
    {
        try {
            $call();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode();
        }

        return 0;
    }

    public function test_only_the_approved_business_owner_manages_connections_and_input_is_validated(): void
    {
        foreach (['customer', 'receptionist', 'owner2', 'admin'] as $role) {
            $this->create([], $role)->assertForbidden();
            $this->actor($role);
            $this->getJson($this->conn())->assertForbidden();
        }
        $this->create(['bankCode' => 'Unknown Bank'])->assertStatus(400);
        $this->create(['environment' => 'staging'])->assertStatus(400);
        $this->create(['unexpected' => 'x'])->assertStatus(400);
        $this->create(['accountNumber' => 'a b'])->assertUnprocessable();
        $this->create(['accountName' => 'TÊN CÓ DẤU'])->assertUnprocessable();
        $this->assertSame(0, DB::table('payment_connections')->count());
        foreach (range(1, 5) as $_) $this->create()->assertCreated();
        $this->create()->assertStatus(409);
    }

    public function test_a_connection_is_verified_by_a_signed_transfer_with_its_own_code_before_it_can_be_enabled_and_only_one_takes_deposits(): void
    {
        $connection = $this->create()->assertCreated()->json();
        $secret = $connection['secret'];
        $this->actor('owner');
        $this->patchJson($this->conn('/'.$connection['id']), ['enabled' => true, 'version' => $connection['version']])->assertStatus(409);

        // A wrong secret, another connection's secret, a wrong account, a stale timestamp and a plain GM-like code verify nothing.
        $other = $this->create()->assertCreated()->json();
        $this->transfer($connection, ($secret[0] === 'f' ? 'e' : 'f').substr($secret, 1), $connection['verificationCode'], 1000)->assertUnauthorized();
        $this->transfer($connection, $other['secret'], $connection['verificationCode'], 1000)->assertUnauthorized();
        $this->transfer($connection, $secret, $connection['verificationCode'], 1000, ['accountNumber' => '999999'])->assertStatus(400);
        $this->transfer($connection, $secret, 'GS'.str_repeat('0', 24), 1000)->assertOk();
        $this->transfer($other, $other['secret'], $connection['verificationCode'], 1000)->assertOk();
        $this->assertSame('SETUP', DB::table('payment_connections')->where('id', $connection['id'])->value('status'), 'the code only counts on its own connection');
        $this->actor('owner');
        $this->postJson($this->webhookUrl(['id' => 999999]), [])->assertNotFound();

        $this->transfer($connection, $secret, 'xac minh '.$connection['verificationCode'], 1000, ['transferType' => 'out'])->assertOk();
        $this->assertSame('SETUP', DB::table('payment_connections')->where('id', $connection['id'])->value('status'), 'an outgoing transfer verifies nothing');
        $this->transfer($connection, $secret, 'xac minh '.$connection['verificationCode'], 1000)->assertOk();
        $this->assertSame(['READY', 'VERIFIED'], [DB::table('payment_connections')->where('id', $connection['id'])->value('status'), DB::table('sepay_webhook_events')->where('connection_id', $connection['id'])->where('processing', 'VERIFIED')->value('processing')]);

        // Enabling needs the current version; enabling a second verified connection switches the first one off.
        [$second] = [$this->verified($other)];
        $this->actor('owner');
        $first = $this->verified($connection);
        $this->patchJson($this->conn('/'.$first['id']), ['enabled' => true, 'version' => $first['version'] - 1])->assertStatus(409);
        $this->patchJson($this->conn('/'.$first['id']), ['enabled' => true, 'version' => $first['version']])->assertOk();
        $this->patchJson($this->conn('/'.$second['id']), ['enabled' => true, 'version' => $second['version']])->assertOk();
        $this->assertSame([0, 1], [(int) DB::table('payment_connections')->where('id', $first['id'])->value('enabled'), (int) DB::table('payment_connections')->where('id', $second['id'])->value('enabled')]);
        $this->assertSame(1, DB::table('payment_connections')->where('enabled', 1)->count());
        config(['wallet.direct.enabled' => false]);
        $this->patchJson($this->conn('/'.$first['id']), ['enabled' => true, 'version' => 99])->assertStatus(503);
        $this->patchJson($this->conn('/'.$second['id']), ['enabled' => false, 'version' => DB::table('payment_connections')->where('id', $second['id'])->value('version')])->assertOk()->assertJsonPath('enabled', false);
    }

    private function verified(array $connection): array
    {
        $this->transfer($connection, $connection['secret'], $connection['verificationCode'], 1000)->assertOk();
        $this->actor('owner');

        return collect($this->getJson($this->conn())->json('connections'))->firstWhere('id', $connection['id']) + ['secret' => $connection['secret']];
    }

    // ---- booking ---------------------------------------------------------------------------------------------------

    public function test_the_quote_names_the_receiving_account_and_a_booking_needs_the_current_one(): void
    {
        $data = $this->bookingData();
        $none = $this->terms($data);
        $this->assertSame([false, null, null], [$none['walletMode'], $none['depositMode'], $none['terms']]);
        $this->assertFalse($none['readiness']['ok']);
        $this->book($this->bookingData(10), null, 'direct-no-account-0001')->assertConflict();
        $this->assertDatabaseCount('bookings', 0);

        [$connection] = $this->connect();
        $terms = $this->terms($data);
        $this->assertSame('DIRECT', $terms['depositMode']);
        $t = $terms['terms'];
        $this->assertSame(['DIRECT_SEPAY', 'ACB', 'CONG TY THU NGHIEM', 'test', 3000], [$t['fundingSource'], $t['bankCode'], $t['accountName'], $t['environment'], $t['rateBps']]);
        $this->assertSame($t['totalVnd'], $t['depositVnd'] + $t['remainingVnd']);
        $this->assertSame(intdiv($t['totalVnd'] * 3 + 9, 10), $t['depositVnd'], 'the deposit is 30% of the total, rounded up');
        $this->assertArrayNotHasKey('connectionId', $t);

        // No quote, a stale quote and a quote of another account are refused, and nothing is created.
        $before = DB::table('bookings')->count();
        $this->book($data, null, 'direct-quote-0001')->assertStatus(409);
        $this->book($data, str_repeat('a', 64), 'direct-quote-0002')->assertStatus(409);
        $this->assertSame($before, DB::table('bookings')->count());

        $id = (int) $this->book($data, $t['quote'], 'direct-quote-0003')->assertCreated()->json('id');
        $deposit = $this->deposit($id);
        $this->assertSame(['DIRECT_SEPAY', 'AWAITING_PAYMENT', (int) $connection['id'], $t['depositVnd'], 'ACB', '0000000001', null], [$deposit->funding_source, $deposit->status, (int) $deposit->connection_id, (int) $deposit->amount_vnd, $deposit->bank_code, $deposit->account_number, $deposit->wallet_id]);
        $this->assertMatchesRegularExpression('/^GM[A-F0-9]{24}$/', $deposit->payment_code);
        $booking = DB::table('bookings')->find($id);
        $this->assertSame('PENDING', $booking->status);
        $this->assertSame(strtotime($deposit->expires_at.' UTC'), strtotime($booking->pending_expires_at.' '.config('app.timezone')), 'the booking hold and the deposit expire together');
        $this->assertSame(0, DB::table('wallets')->count(), 'no wallet is involved');
        $replay = $this->book($data, $t['quote'], 'direct-quote-0003')->assertSuccessful();
        $this->assertSame($id, (int) $replay->json('id'));
        $this->assertSame(1, DB::table('booking_deposits')->where('booking_id', $id)->count());

        // Enabling another account asks the customer again; a deposit that exists keeps the account it was booked with.
        $second = $this->verified($this->create(['accountNumber' => '5555555555'])->assertCreated()->json());
        $this->actor('owner');
        $this->patchJson($this->conn('/'.$second['id']), ['enabled' => true, 'version' => $second['version']])->assertOk();
        $this->assertSame('0000000001', $this->deposit($id)->account_number);
        $fresh = $this->terms($this->bookingData(11))['terms'];
        $this->assertNotSame($t['quote'], $fresh['quote']);
        $this->book($this->bookingData(11), $t['quote'], 'direct-quote-0004')->assertStatus(409);
        $this->assertSame(201, $this->book($this->bookingData(11), $fresh['quote'], 'direct-quote-0005')->status());
        $this->assertSame((int) $second['id'], (int) DB::table('booking_deposits')->orderByDesc('id')->value('connection_id'));
    }

    public function test_the_deposit_view_belongs_to_the_booking_parties_only_and_a_qr_exists_only_for_live_accounts(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $customer = $this->depositView($id, 'customer')->assertOk()->json();
        $this->assertSame(['CUSTOMER', 'AWAITING_PAYMENT', true, null], [$customer['role'], $customer['deposit']['status'], $customer['deposit']['canPay'], $customer['deposit']['qrUrl']]);
        $this->assertSame($deposit->payment_code, $customer['deposit']['paymentCode']);
        $this->assertSame([false], [$customer['canRecordStore']]);
        $this->assertSame('OWNER', $this->depositView($id, 'owner')->assertOk()->json('role'));
        $this->assertSame('RECEPTIONIST', $this->depositView($id, 'receptionist')->assertOk()->json('role'));
        foreach (['owner2', 'staff'] as $role) $this->depositView($id, $role)->assertNotFound();
        $this->actor('customer');
        $this->getJson('/api/v1/payments/bookings/999999/deposit')->assertNotFound();

        DB::table('booking_deposits')->where('id', $deposit->id)->update(['environment' => 'live']);
        $live = $this->depositView($id, 'customer')->json('deposit.qrUrl');
        $this->assertStringStartsWith('https://vietqr.app/img?', $live);
        $this->assertStringContainsString('des='.$deposit->payment_code, $live);
        $this->assertStringContainsString('amount='.$deposit->amount_vnd, $live);
    }

    public function test_a_recurring_series_creates_one_awaiting_deposit_per_occurrence_all_or_nothing(): void
    {
        $this->connect();
        $data = $this->bookingData();
        $series = ['branchId' => $data['branchId'], 'serviceIds' => $data['serviceIds'], 'staffMode' => 'ANY_AVAILABLE', 'frequency' => 'WEEKLY', 'startDate' => substr($data['appointmentDate'], 0, 10),
            'preferredTime' => '10:00', 'occurrenceCount' => 3, 'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true];
        $this->actor('customer');
        $preview = $this->postJson('/api/v1/recurring/preview', $series)->assertOk()->json();
        $this->assertSame([false, 'DIRECT'], [$preview['walletMode'], $preview['depositMode']]);
        $quotes = array_column(array_column($preview['occurrences'], 'walletDeposit'), 'quote');
        $this->assertCount(3, $quotes);
        $this->assertSame($preview['occurrences'][0]['walletDeposit']['depositVnd'] * 3, $preview['walletDepositTotalVnd']);

        // One stale quote refuses the whole series.
        $stale = $quotes;
        $stale[1] = str_repeat('a', 64);
        $this->withHeader('Idempotency-Key', 'series-direct-0001')->postJson('/api/v1/recurring', $series + ['walletDepositQuotes' => $stale])->assertStatus(409);
        $this->assertSame([0, 0], [DB::table('bookings')->count(), DB::table('booking_deposits')->count()]);

        $created = $this->withHeader('Idempotency-Key', 'series-direct-0002')->postJson('/api/v1/recurring', $series + ['walletDepositQuotes' => $quotes])->assertOk()->json();
        $this->assertCount(3, $created['bookings']);
        $deposits = DB::table('booking_deposits')->get();
        $this->assertCount(3, $deposits);
        $this->assertSame(['AWAITING_PAYMENT'], $deposits->pluck('status')->unique()->values()->all());
        $this->assertCount(3, $deposits->pluck('payment_code')->unique(), 'every occurrence has its own payment code');
        $this->withHeader('Idempotency-Key', 'series-direct-0002')->postJson('/api/v1/recurring', $series + ['walletDepositQuotes' => $quotes])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(3, DB::table('booking_deposits')->count());
    }

    // ---- receipts ---------------------------------------------------------------------------------------------------

    public function test_one_signed_receipt_pays_the_deposit_once_and_replays_change_nothing(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit, null, ['id' => 777001])->assertOk();
        $paid = $this->deposit($id);
        $this->assertSame('PAID', $paid->status);
        $this->assertNotNull($paid->paid_at);
        $receipt = DB::table('sepay_webhook_events')->where('provider_transaction_id', 777001)->first();
        $this->assertSame(['APPLIED', (int) $deposit->id, (int) $connection['id'], 'DIRECT_DEPOSIT'], [$receipt->processing, (int) $receipt->deposit_id, (int) $receipt->connection_id, $receipt->channel]);
        $booking = DB::table('bookings')->find($id);
        $this->assertSame('PENDING', $booking->status, 'with MANUAL confirmation the owner still approves');
        $this->assertGreaterThan(time(), strtotime($booking->pending_expires_at.' '.config('app.timezone')));
        $this->assertSame(1, DB::table('notifications')->where('type', 'DEPOSIT_PAID')->count());

        // The same bank transaction delivered again, by retries and in parallel, is stored and applied once.
        foreach (range(1, 12) as $_) $this->pay($connection, $secret, $deposit, null, ['id' => 777001])->assertOk();
        $this->assertSame(1, DB::table('sepay_webhook_events')->where('provider_transaction_id', 777001)->count());
        $this->assertSame([1, 1], [DB::table('notifications')->where('type', 'DEPOSIT_PAID')->count(), DB::table('audit_logs')->where('action', 'DEPOSIT_PAID')->count()]);
        // The same id with other content is an identity conflict.
        $this->pay($connection, $secret, $deposit, (int) $deposit->amount_vnd + 1, ['id' => 777001])->assertStatus(409);
        $this->assertSame(0, DB::table('deposit_refunds')->count());
    }

    public function test_an_auto_confirmation_branch_confirms_the_booking_when_the_deposit_arrives(): void
    {
        $this->policy(['bookingConfirmationMode' => 'AUTO_CONFIRMATION']);
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->assertSame('PENDING', DB::table('bookings')->find($id)->status, 'an unpaid deposit never confirms a booking');
        $this->pay($connection, $secret, $deposit)->assertOk();
        $booking = DB::table('bookings')->find($id);
        $this->assertSame(['CONFIRMED', null], [$booking->status, $booking->pending_expires_at]);
        $history = DB::table('booking_status_histories')->where('booking_id', $id)->where('to_status', 'CONFIRMED')->first();
        $this->assertSame(['DEPOSIT_RECEIVED_AUTO_CONFIRMATION', 'SYSTEM'], [$history->reason, $history->actor_kind]);
        $this->assertSame('PAID', $this->deposit($id)->status);
    }

    public function test_a_receipt_that_does_not_match_is_kept_for_reconciliation_and_money_that_cannot_count_is_owed_back(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $amount = (int) $deposit->amount_vnd;

        // No code, an unknown code, two codes, another connection's receipt for this code, and an outgoing transfer: nothing is paid.
        $this->transfer($connection, $secret, 'chuyen khoan khong ma', $amount)->assertOk();
        $this->transfer($connection, $secret, 'GM'.str_repeat('A', 24), $amount)->assertOk();
        $this->transfer($connection, $secret, $deposit->payment_code.' GM'.str_repeat('B', 24), $amount)->assertOk();
        $this->transfer($connection, $secret, $deposit->payment_code, $amount, ['transferType' => 'out'])->assertOk();
        $this->assertSame('AWAITING_PAYMENT', $this->deposit($id)->status);
        $this->actor('owner');
        $receipts = $this->getJson($this->conn())->assertOk()->json('receipts');
        $this->assertCount(3, $receipts, 'unmatched incoming receipts wait for the owner');
        $this->assertSame(['UNMATCHED'], array_values(array_unique(array_column($receipts, 'processing'))));

        // A wrong amount is owed back and the deposit stays open for the right one.
        $this->pay($connection, $secret, $deposit, $amount - 1000, ['id' => 880001])->assertOk();
        $this->assertSame('AWAITING_PAYMENT', $this->deposit($id)->status);
        $short = DB::table('deposit_refunds')->first();
        $this->assertSame([$amount - 1000, 'AMOUNT_MISMATCH', 'PENDING', 'DIRECT_SEPAY', null], [(int) $short->amount_vnd, $short->reason, $short->status, $short->source, $short->operation_key]);
        $this->assertSame('REFUND_REQUIRED', DB::table('sepay_webhook_events')->where('provider_transaction_id', 880001)->value('processing'));
        $this->pay($connection, $secret, $deposit, null, ['id' => 880002])->assertOk();
        $this->assertSame('PAID', $this->deposit($id)->status);

        // Paying again (any amount) is a duplicate, owed back; the paid deposit is untouched.
        $this->pay($connection, $secret, $deposit, null, ['id' => 880003])->assertOk();
        $this->assertSame(['PAID', 'DUPLICATE_PAYMENT'], [$this->deposit($id)->status, DB::table('deposit_refunds')->orderByDesc('id')->value('reason')]);
        $this->assertSame(2, DB::table('deposit_refunds')->count());
        $this->assertSame(1, DB::table('sepay_webhook_events')->where('deposit_id', $deposit->id)->where('processing', 'APPLIED')->count());
        $this->actor('owner');
        $this->assertCount(5, $this->getJson($this->conn())->json('receipts'));
    }

    public function test_money_that_arrives_after_the_hold_or_for_an_unavailable_booking_is_never_applied(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        DB::table('booking_deposits')->where('id', $deposit->id)->update(['expires_at' => now('UTC')->subMinute()]);
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->assertSame(['EXPIRED', 'PENDING', 'LATE_PAYMENT'], [$this->deposit($id)->status, DB::table('bookings')->find($id)->status, DB::table('deposit_refunds')->value('reason')], 'a late payment does not revive anything');

        [$second, $deposit2] = $this->booked('direct-booking-0002', 9);
        DB::table('branches')->where('id', $this->branchId())->update(['status' => 'INACTIVE']);
        $this->pay($connection, $secret, $deposit2)->assertOk();
        $this->assertSame(['VOIDED', 'BOOKING_UNAVAILABLE'], [$this->deposit($second)->status, DB::table('deposit_refunds')->orderByDesc('id')->value('reason')]);
    }

    public function test_disabling_direct_deposits_stops_new_ones_but_never_abandons_existing_ones(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        config(['wallet.direct.enabled' => false]);
        $data = $this->bookingData(9);
        $this->assertNull($this->terms($data)['terms']);
        $this->assertSame(201, $this->book($data, null, 'direct-off-0001')->status());
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->assertSame('PAID', $this->deposit($id)->status, 'receipts of existing deposits still apply');
    }

    public function test_wallet_mode_takes_precedence_over_direct_deposits(): void
    {
        $this->connect();
        $this->configureLab();
        $terms = $this->terms($this->bookingData());
        $this->assertTrue($terms['walletMode']);
        $this->assertSame('SIMULATED_WALLET', $terms['terms']['fundingSource']);
    }

    // ---- lifecycle --------------------------------------------------------------------------------------------------

    public function test_a_booking_cannot_move_on_until_its_deposit_is_paid_and_completion_applies_it(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->moveStatus($id, 'CONFIRMED')->assertStatus(409);
        $this->assertSame('PENDING', DB::table('bookings')->find($id)->status);
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->move($id, now('UTC')->subHours(2), now('UTC')->subHour());
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
        $this->moveStatus($id, 'IN_PROGRESS')->assertOk();
        $this->moveStatus($id, 'COMPLETED')->assertOk();
        $this->assertSame('APPLIED', $this->deposit($id)->status);
        $this->assertSame(0, DB::table('business_payables')->count(), 'the business already holds the money: nothing is owed to it');
        $this->assertSame(0, DB::table('deposit_refunds')->count());
    }

    public function test_cancelling_before_payment_voids_the_deposit_and_expiry_expires_it(): void
    {
        [$connection, $secret] = $this->connect();
        [$id] = $this->booked();
        $this->moveStatus($id, 'CANCELLED', 'customer')->assertOk();
        $this->assertSame('VOIDED', $this->deposit($id)->status);
        $this->assertSame(0, DB::table('deposit_refunds')->count());

        [$second] = $this->booked('direct-booking-0002', 9);
        DB::table('bookings')->where('id', $second)->update(['pending_expires_at' => now()->subMinute()]);
        app(BookingMaintenanceService::class)->expire();
        $this->assertSame(['EXPIRED', 'EXPIRED'], [DB::table('bookings')->find($second)->status, $this->deposit($second)->status]);
    }

    public function test_a_paid_deposit_is_owed_back_when_the_salon_rejects_cancels_or_lets_the_booking_expire_and_forfeited_on_no_show(): void
    {
        [$connection, $secret] = $this->connect();
        $cases = [];
        foreach ([['reject', 'REJECTED', 'owner'], ['cancel', 'CANCELLED', 'owner'], ['early', 'CANCELLED', 'customer']] as $i => [$name, $target, $as]) {
            [$id, $deposit] = $this->booked('direct-booking-100'.$i, 8 + $i);
            $this->pay($connection, $secret, $deposit)->assertOk();
            $this->moveStatus($id, $target, $as, $target === 'REJECTED' ? ['note' => 'Hết chuyên viên'] : [])->assertOk();
            $cases[$name] = [$this->deposit($id)->status, DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->value('reason')];
        }
        $this->assertSame(['REFUND_DUE', 'BOOKING_REJECTED'], $cases['reject']);
        $this->assertSame(['REFUND_DUE', 'BOOKING_CANCELLED'], $cases['cancel']);
        $this->assertSame(['REFUND_DUE', 'BOOKING_CANCELLED'], $cases['early'], 'a customer cancelling well before the cutoff is refunded');
        $this->assertSame(3, DB::table('notifications')->where('type', 'DEPOSIT_REFUND_DUE')->count());

        [$expiring, $deposit] = $this->booked('direct-booking-1003', 11);
        $this->pay($connection, $secret, $deposit)->assertOk();
        DB::table('bookings')->where('id', $expiring)->update(['pending_expires_at' => now()->subMinute()]);
        app(BookingMaintenanceService::class)->expire();
        $this->assertSame(['EXPIRED', 'REFUND_DUE', 'BOOKING_EXPIRED'], [DB::table('bookings')->find($expiring)->status, $this->deposit($expiring)->status, DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->value('reason')], 'a paid booking nobody approved is refunded');

        [$gone, $deposit] = $this->booked('direct-booking-1004', 12);
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($gone, 'CONFIRMED')->assertOk();
        $this->move($gone, now('UTC')->subMinutes(40), now('UTC')->addMinutes(20));
        $this->moveStatus($gone, 'NO_SHOW', 'owner', ['noShowConfirmed' => true])->assertOk();
        $this->assertSame(['FORFEITED', 0], [$this->deposit($gone)->status, DB::table('deposit_refunds')->where('deposit_id', $deposit->id)->count()]);
    }

    public function test_a_late_customer_cancellation_forfeits_and_a_salon_requested_one_does_not(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked('direct-booking-2001', 8);
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->move($id, now('UTC')->addHour(), now('UTC')->addHours(2));
        $this->actor('customer');
        $request = (int) $this->postJson("/api/v1/bookings/{$id}/change-requests", ['requestType' => 'CANCEL', 'reason' => 'Bận việc đột xuất'])->assertOk()->json('id');
        $this->actor('owner');
        $this->patchJson("/api/v1/bookings/change-requests/{$request}/approve", [])->assertOk();
        $this->assertSame('FORFEITED', $this->deposit($id)->status, 'inside the branch cutoff the deposit stays with the business');
    }

    // ---- refunds ----------------------------------------------------------------------------------------------------

    public function test_a_refund_is_declared_by_the_customer_reported_by_the_owner_and_confirmed_by_the_customer(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'REJECTED', 'owner', ['note' => 'Hết chuyên viên'])->assertOk();
        $d = $this->depositView($id, 'customer')->json('deposit');
        $refund = $d['refunds'][0];
        $this->assertSame(['REFUND_DUE', 'PENDING', null], [$d['status'], $refund['status'], $refund['destination']]);
        $destination = ['version' => $refund['version'], 'refundId' => $refund['id'], 'bankCode' => 'Vietcombank', 'accountNumber' => '0123456789', 'accountName' => 'Nguyễn Văn A'];

        // The owner cannot report before the customer says where, and cannot declare for them.
        $this->act($id, 'refund-report', ['version' => $refund['version'], 'refundId' => $refund['id'], 'reference' => 'FT0001'], 'owner')->assertStatus(409);
        $this->act($id, 'refund-destination', $destination, 'owner')->assertForbidden();
        $this->act($id, 'refund-destination', array_merge($destination, ['bankCode' => 'bad<>code']), 'customer')->assertUnprocessable();
        $this->act($id, 'refund-destination', $destination, 'customer')->assertOk();
        $this->act($id, 'refund-destination', $destination, 'customer')->assertStatus(409);

        $refund = $this->depositView($id, 'owner')->json('deposit.refunds.0');
        $this->assertEquals(['bankCode' => 'Vietcombank', 'accountNumber' => '0123456789', 'accountName' => 'Nguyễn Văn A'], $refund['destination']);
        $report = ['version' => $refund['version'], 'refundId' => $refund['id'], 'reference' => 'FT0001'];
        $this->act($id, 'refund-report', $report, 'customer')->assertForbidden();
        $this->act($id, 'refund-report', ['version' => $refund['version'] - 1] + $report, 'owner')->assertStatus(409);
        $this->act($id, 'refund-report', $report, 'owner')->assertOk();
        $this->assertSame('REFUND_DUE', $this->deposit($id)->status, 'reported is not finished');
        $this->act($id, 'refund-report', $report, 'owner')->assertStatus(409);

        $refund = $this->depositView($id, 'customer')->json('deposit.refunds.0');
        $this->assertSame(['REPORTED', 'FT0001'], [$refund['status'], $refund['reference']]);
        $confirm = ['version' => $refund['version'], 'refundId' => $refund['id']];
        $this->act($id, 'refund-confirm', $confirm, 'owner')->assertForbidden();
        $this->act($id, 'refund-confirm', $confirm, 'customer')->assertOk();
        $this->assertSame(['REFUNDED', 'CONFIRMED'], [$this->deposit($id)->status, DB::table('deposit_refunds')->value('status')]);
        $this->act($id, 'refund-confirm', $confirm, 'customer')->assertStatus(409);
        $current = $this->depositView($id, 'customer')->json('deposit.refunds.0');
        $this->act($id, 'refund-unknown', ['version' => $current['version'], 'refundId' => $current['id']], 'customer')->assertStatus(400);
        $this->assertSame(1, DB::table('notifications')->where('type', 'DEPOSIT_REFUND_REPORTED')->count());
    }

    public function test_the_deposit_is_refunded_only_after_every_refund_owed_for_it_is_confirmed(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit, null, ['id' => 990001])->assertOk();
        $this->pay($connection, $secret, $deposit, null, ['id' => 990002])->assertOk();
        $this->moveStatus($id, 'CANCELLED')->assertOk();
        $refunds = $this->depositView($id, 'customer')->json('deposit.refunds');
        $this->assertCount(2, $refunds);
        foreach ($refunds as $i => $refund) {
            $this->act($id, 'refund-destination', ['version' => $refund['version'], 'refundId' => $refund['id'], 'bankCode' => 'ACB', 'accountNumber' => '123456', 'accountName' => 'KHACH'], 'customer')->assertOk();
            $fresh = collect($this->depositView($id, 'owner')->json('deposit.refunds'))->firstWhere('id', $refund['id']);
            $this->act($id, 'refund-report', ['version' => $fresh['version'], 'refundId' => $refund['id'], 'reference' => 'FT'.$i], 'owner')->assertOk();
            $fresh = collect($this->depositView($id, 'customer')->json('deposit.refunds'))->firstWhere('id', $refund['id']);
            $this->act($id, 'refund-confirm', ['version' => $fresh['version'], 'refundId' => $refund['id']], 'customer')->assertOk();
            $this->assertSame($i === 0 ? 'REFUND_DUE' : 'REFUNDED', $this->deposit($id)->status);
        }
    }

    public function test_the_store_records_the_remainder_only_after_check_in_and_a_recorded_payment_blocks_cancelling(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $version = fn () => (int) $this->deposit($id)->version;
        $this->act($id, 'store-receipt', ['version' => $version(), 'reference' => 'tien mat'], 'owner')->assertStatus(409);
        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
        $this->assertTrue($this->depositView($id, 'owner')->json('canRecordStore'));
        $this->assertFalse($this->depositView($id, 'customer')->json('canRecordStore'));
        $this->act($id, 'store-receipt', ['version' => $version(), 'reference' => 'tien mat'], 'customer')->assertForbidden();
        $this->act($id, 'store-receipt', ['version' => $version() - 1, 'reference' => 'tien mat'], 'owner')->assertStatus(409);
        $this->act($id, 'store-receipt', ['version' => $version(), 'reference' => 'tien mat'], 'receptionist')->assertOk();
        $this->assertNotNull($this->deposit($id)->store_received_at);
        $this->assertFalse($this->depositView($id, 'owner')->json('canRecordStore'));
        $this->moveStatus($id, 'CANCELLED')->assertStatus(409);
    }

    public function test_the_store_collects_only_the_remainder_when_a_direct_deposit_was_received(): void
    {
        [$connection, $secret] = $this->connect();
        [$id, $deposit] = $this->booked();
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->moveStatus($id, 'CONFIRMED')->assertOk();
        $this->move($id, now('UTC')->subMinutes(10), now('UTC')->addMinutes(50));
        $this->moveStatus($id, 'CHECKED_IN')->assertOk();
        $this->actor('owner');
        $checkout = $this->getJson("/api/v1/payments/checkout/{$id}")->assertOk()->json();
        $this->assertEquals([(float) $deposit->total_vnd, (float) $deposit->amount_vnd, (float) $deposit->remaining_vnd], [(float) $checkout['totalAmount'], (float) $checkout['paidAmount'], (float) $checkout['remainingAmount']]);
        $this->postJson('/api/v1/payments/collect', ['bookingId' => (string) $id, 'method' => 'CASH', 'idempotencyKey' => 'direct-store-pay-0001'])->assertOk();
        $this->assertNotNull($this->deposit($id)->store_received_at);
        $this->assertEquals((float) $deposit->remaining_vnd, (float) DB::table('payment_transactions')->where('booking_id', $id)->sum('amount_cents') / 100);
    }
}
