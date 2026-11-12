<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

/**
 * What changes when direct deposits take real money: only live connections (SePay Test mode stays out of production), an https
 * address for the webhook, an optional allowlist of SePay's source IPs, the deposit code still found when a bank glues it to its
 * own text, and a go-live check that says what is missing without ever printing a secret.
 */
class DirectDepositProductionTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDirectDeposits();
    }

    private function production(string $url = 'https://beautybook.example.test'): void
    {
        // The framework treats the `testing` environment specially (CSRF), so production is switched on by the setting the code reads.
        config(['wallet.direct.production' => true, 'wallet.direct.allow_test' => false, 'app.url' => $url, 'app.debug' => false]);
    }

    // ---- connections in production ------------------------------------------------------------------------------------------

    public function test_in_production_only_live_connections_may_be_created_unless_test_mode_is_explicitly_allowed(): void
    {
        $this->production();
        $this->create(['environment' => 'test'])->assertStatus(422);
        $this->assertSame(0, DB::table('payment_connections')->count());

        $this->create(['environment' => 'live'])->assertCreated()->assertJsonPath('environment', 'live');

        config(['wallet.direct.allow_test' => true]);
        $this->create(['environment' => 'test'])->assertCreated();
    }

    public function test_the_list_tells_the_screen_whether_test_connections_are_allowed(): void
    {
        $this->actor('owner');
        $this->assertTrue($this->getJson($this->conn())->assertOk()->json('allowTest'), 'outside production test mode is the way to try it');
        $this->production();
        $this->actor('owner');
        $this->assertFalse($this->getJson($this->conn())->assertOk()->json('allowTest'));
    }

    public function test_a_live_connection_in_production_needs_an_https_address_for_its_webhook(): void
    {
        $this->production('http://beautybook.example.test');
        $this->create(['environment' => 'live'])->assertStatus(503);
        $this->assertSame(0, DB::table('payment_connections')->count());

        config(['app.url' => 'https://beautybook.example.test']);
        $created = $this->create(['environment' => 'live'])->assertCreated()->json();
        $this->assertSame('https://beautybook.example.test/api/v1/payments/sepay/connections/'.$created['id'].'/webhook', $created['webhookUrl'], 'the exact address to paste into SePay');
    }

    public function test_a_live_connection_goes_through_verification_and_takes_a_deposit_in_production(): void
    {
        $this->production();
        $connection = $this->create(['environment' => 'live'])->assertCreated()->json();
        $this->transfer($connection, $connection['secret'], $connection['verificationCode'].' xac minh', 10000)->assertOk();
        $this->actor('owner');
        $listed = collect($this->getJson($this->conn())->json('connections'))->firstWhere('id', $connection['id']);
        $this->assertSame('READY', $listed['status']);
        $this->patchJson($this->conn('/'.$connection['id']), ['enabled' => true, 'version' => $listed['version']])->assertOk();

        [$id, $deposit] = $this->booked('prod-booking-0001');
        $this->assertSame('live', $deposit->environment);
        $this->pay($connection + ['bankCode' => 'ACB'], $connection['secret'], $deposit)->assertOk();
        $this->assertSame('PAID', $this->deposit($id)->status);
        $this->actor('customer');
        $view = $this->getJson("/api/v1/payments/bookings/{$id}/deposit")->assertOk()->json('deposit');
        $this->assertSame('PAID', $view['status']);
    }

    // ---- SePay source addresses -----------------------------------------------------------------------------------------------

    private function deliverFrom(string $ip, array $connection, string $secret)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->transfer($connection, $secret, 'noi dung', 1000, ['id' => random_int(100000, 99999999)]);
    }

    public function test_the_webhook_can_be_limited_to_the_published_sepay_addresses(): void
    {
        [$connection, $secret] = $this->connect();
        $connection['accountNumber'] = '0000000001';

        // Nothing configured: open, as before.
        $this->deliverFrom('198.51.100.9', $connection, $secret)->assertOk();

        config(['services.sepay.allowed_ips' => '203.0.113.7, 2001:db8::1, 198.51.100.0/28']);
        $before = DB::table('sepay_webhook_events')->count();
        $this->deliverFrom('203.0.113.8', $connection, $secret)->assertForbidden();
        $this->deliverFrom('198.51.100.99', $connection, $secret)->assertForbidden();
        $this->assertSame($before, DB::table('sepay_webhook_events')->count(), 'a refused address stores nothing');

        $this->deliverFrom('203.0.113.7', $connection, $secret)->assertOk();
        $this->deliverFrom('2001:db8::1', $connection, $secret)->assertOk();
        $this->deliverFrom('198.51.100.5', $connection, $secret)->assertOk();            // inside the /28
        $this->assertSame($before + 3, DB::table('sepay_webhook_events')->count());

        // The same rule guards the other two webhook addresses, before anything about them is revealed.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/api/v1/payments/sepay/webhook', [])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/api/v1/payments/sepay/wallet-live-lab/webhook', [])->assertForbidden();
    }

    public function test_an_unreadable_allowlist_refuses_every_receipt_rather_than_opening_the_door(): void
    {
        [$connection, $secret] = $this->connect();
        config(['services.sepay.allowed_ips' => 'not-an-ip, 999.1.1.1']);
        $this->deliverFrom('203.0.113.7', $connection, $secret)->assertForbidden();
    }

    // ---- reading the deposit code out of a bank's wording -----------------------------------------------------------------

    private function paidBy(string $content, array $connection, string $secret, int $amount, int $id): void
    {
        $this->transfer($connection, $secret, $content, $amount, ['id' => $id])->assertOk();
    }

    public function test_the_deposit_code_is_found_when_a_bank_glues_it_to_its_own_text_or_changes_its_case(): void
    {
        [$connection, $secret] = $this->connect();
        [$first, $depositA] = $this->booked('prod-glue-0001', 8);
        [$second, $depositB] = $this->booked('prod-glue-0002', 9);

        $this->paidBy('MBVCB.3278907687.'.$depositA->payment_code.'.CT tu NGUYEN VAN A', $connection, $secret, (int) $depositA->amount_vnd, 910001);
        $this->paidBy('thanh toan '.strtolower($depositB->payment_code).'coc', $connection, $secret, (int) $depositB->amount_vnd, 910002);

        $this->assertSame('PAID', $this->deposit($first)->status);
        $this->assertSame('PAID', $this->deposit($second)->status);
    }

    public function test_two_different_codes_in_one_transfer_are_never_guessed_between(): void
    {
        [$connection, $secret] = $this->connect();
        [$first, $depositA] = $this->booked('prod-two-0001', 8);
        [$second, $depositB] = $this->booked('prod-two-0002', 9);

        $this->paidBy($depositA->payment_code.' va '.$depositB->payment_code, $connection, $secret, (int) $depositA->amount_vnd, 910003);

        $this->assertSame(['AWAITING_PAYMENT', 'AWAITING_PAYMENT'], [$this->deposit($first)->status, $this->deposit($second)->status]);
        $this->assertSame('UNMATCHED', DB::table('sepay_webhook_events')->where('provider_transaction_id', 910003)->value('processing'));
    }

    // ---- the go-live check ------------------------------------------------------------------------------------------------

    private function goodProduction(): void
    {
        $this->production();
        config(['wallet.mode' => '', 'wallet.direct.enabled' => true, 'wallet.direct.encryption_key' => self::KEY, 'wallet.live_lab.isolated' => false, 'wallet.live_lab.enabled' => false,
            'services.sepay.allowed_ips' => '203.0.113.7']);
    }

    public function test_the_go_live_check_passes_a_correct_production_setup_and_names_nothing_secret(): void
    {
        $this->goodProduction();
        $this->artisan('beautybook:payments-check')->expectsOutputToContain('Direct deposits are enabled')->doesntExpectOutputToContain(self::KEY)->assertSuccessful();
    }

    public function test_the_go_live_check_fails_with_a_reason_for_each_thing_that_would_lose_or_expose_money(): void
    {
        $this->goodProduction();
        config(['app.debug' => true, 'app.url' => 'http://beautybook.example.test', 'wallet.direct.encryption_key' => 'short', 'wallet.mode' => 'INTERNAL_WALLET']);
        $this->artisan('beautybook:payments-check')
            ->expectsOutputToContain('FAIL APP_DEBUG')
            ->expectsOutputToContain('FAIL APP_URL')
            ->expectsOutputToContain('FAIL SEPAY_CONNECTION_ENCRYPTION_KEY')
            ->expectsOutputToContain('FAIL BEAUTYBOOK_PAYMENT_MODE')
            ->assertFailed();

        $this->goodProduction();
        config(['wallet.direct.enabled' => false]);
        $this->artisan('beautybook:payments-check')->expectsOutputToContain('FAIL DIRECT_DEPOSIT_ENABLED')->assertFailed();
    }

    public function test_the_go_live_check_reports_businesses_without_a_verified_live_connection(): void
    {
        $this->goodProduction();
        $this->artisan('beautybook:payments-check')->expectsOutputToContain('No business has an enabled live connection yet')->assertSuccessful();
        $connection = $this->create(['environment' => 'live'])->assertCreated()->json();
        // goodProduction() restricts webhooks to SePay's address: the verification transfer comes from there.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->transfer($connection, $connection['secret'], $connection['verificationCode'].' xac minh', 10000)->assertOk();
        $this->actor('owner');
        $listed = collect($this->getJson($this->conn())->json('connections'))->firstWhere('id', $connection['id']);
        $this->patchJson($this->conn('/'.$connection['id']), ['enabled' => true, 'version' => $listed['version']])->assertOk();
        $this->artisan('beautybook:payments-check')->expectsOutputToContain('1 business(es) take live deposits')->assertSuccessful();
    }
}
