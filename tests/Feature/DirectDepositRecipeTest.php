<?php

namespace Tests\Feature;

use App\Models\BranchServiceOffering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DirectDepositFixtures;
use Tests\TestCase;

class DirectDepositRecipeTest extends TestCase
{
    use RefreshDatabase;
    use DirectDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDirectDeposits();
        config(['beautybook.legacy_ui' => false, 'wallet.public_wallet' => false,
            'wallet.booking_deposit_enabled' => false, 'wallet.central.enabled' => false]);
    }

    private function recipe(): array
    {
        $legacy = $this->bookingData();

        return ['branchId' => $legacy['branchId'],
            'selections' => [['serviceId' => $legacy['serviceIds'][0], 'staffId' => $legacy['staffId'], 'quantity' => 1]],
            'startAt' => $legacy['appointmentDate'], 'contact' => ['name' => 'Khách đặt cọc', 'phone' => '0901234567'], 'contactConsent' => true];
    }

    private function preview(array $recipe)
    {
        $this->actor('customer');

        return $this->postJson('/api/v1/bookings/preview-price', $recipe);
    }

    private function accept(array $recipe): array
    {
        $total = (int) round((float) $this->preview($recipe)->assertOk()->json('finalAmount'));

        return $this->getJson('/api/v1/wallet/deposit-terms?branchId='.$recipe['branchId'].'&total='.$total)->assertOk()->json();
    }

    public function test_missing_connection_previews_a_reason_and_blocks_creation_without_any_payment_writes(): void
    {
        $recipe = $this->recipe();
        $preview = $this->preview($recipe)->assertOk()->assertJsonPath('readiness.direct', true)
            ->assertJsonPath('readiness.ok', false)->assertJsonPath('depositOk', false)->json();
        $this->assertStringContainsString('Chưa thể đặt lịch trực tuyến', $preview['depositReason']);
        $terms = $this->accept($recipe);
        $this->assertNull($terms['terms']);
        $this->assertFalse($terms['readiness']['ok']);
        $this->book($recipe, null, 'direct-recipe-missing')->assertConflict()->assertJsonPath('message', $preview['depositReason']);
        foreach (['bookings', 'booking_deposits', 'wallets', 'wallet_transfers', 'business_payables'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_setup_disabled_and_unverified_connections_do_not_make_a_recipe_ready(): void
    {
        $recipe = $this->recipe();
        $connection = $this->create()->assertCreated()->json();
        $this->preview($recipe)->assertOk()->assertJsonPath('depositOk', false);
        $this->actor('owner');
        $this->patchJson($this->conn('/'.$connection['id']), ['enabled' => true, 'version' => $connection['version']])->assertConflict();
        $this->transfer($connection, $connection['secret'], $connection['verificationCode'], 1000)->assertOk();
        $this->preview($recipe)->assertOk()->assertJsonPath('depositOk', false);
        // Verification alone does not enable receiving new deposits; the owner must explicitly turn it on.
        $this->book($recipe, null, 'direct-recipe-unverified')->assertConflict();
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_verified_connection_creates_a_thirty_percent_direct_deposit_and_only_a_signed_receipt_pays_it(): void
    {
        [$connection, $secret] = $this->connect();
        $recipe = $this->recipe();
        $this->policy(['bookingConfirmationMode' => 'AUTO_CONFIRMATION']);
        $this->preview($recipe)->assertOk()->assertJsonPath('readiness.direct', true)->assertJsonPath('depositOk', true);
        $terms = $this->accept($recipe)['terms'];
        $id = (int) $this->book($recipe, $terms['quote'], 'direct-recipe-valid')->assertCreated()->assertJsonPath('status', 'PENDING')->json('id');
        $deposit = $this->deposit($id);
        $this->assertSame('DIRECT_SEPAY', $deposit->funding_source);
        $this->assertSame('AWAITING_PAYMENT', $deposit->status);
        $this->assertNull($deposit->wallet_id);
        $this->assertSame(intdiv((int) $deposit->total_vnd * 3 + 9, 10), (int) $deposit->amount_vnd);
        $this->assertSame((int) $deposit->total_vnd, (int) $deposit->amount_vnd + (int) $deposit->remaining_vnd);
        $this->assertNull($deposit->paid_at);
        $this->pay($connection, $secret, $deposit)->assertOk();
        $this->assertSame('PAID', $this->deposit($id)->status);
        $this->assertSame('CONFIRMED', DB::table('bookings')->where('id', $id)->value('status'));
        // Replays retain the historical deposit even after receiving new deposits is disabled.
        DB::table('payment_connections')->where('id', $connection['id'])->update(['enabled' => 0]);
        $this->book($recipe, $terms['quote'], 'direct-recipe-valid')->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('booking_deposits', 1);
        foreach (['wallets', 'wallet_transfers', 'business_payables'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_a_price_change_or_disabled_connection_invalidates_an_accepted_recipe_quote(): void
    {
        [$connection] = $this->connect();
        $recipe = $this->recipe();
        $terms = $this->accept($recipe)['terms'];
        $offering = BranchServiceOffering::findOrFail($recipe['selections'][0]['serviceId']);
        $offering->update(['price' => bcadd($offering->price, '12345', 2)]);
        $this->book($recipe, $terms['quote'], 'direct-recipe-stale-price')->assertConflict();
        $fresh = $this->accept($recipe)['terms'];
        $this->assertNotSame($terms['quote'], $fresh['quote']);
        DB::table('payment_connections')->where('id', $connection['id'])->update(['enabled' => 0]);
        $this->book($recipe, $fresh['quote'], 'direct-recipe-disabled')->assertConflict();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_deposits', 0);
    }

    public function test_zero_price_recipes_do_not_require_a_connection_or_deposit(): void
    {
        $recipe = $this->recipe();
        BranchServiceOffering::whereKey($recipe['selections'][0]['serviceId'])->update(['price' => 0]);
        $this->preview($recipe)->assertOk()->assertJsonPath('finalAmount', '0.00')->assertJsonPath('depositOk', true)
            ->assertJsonPath('readiness.required', false);
        $this->book($recipe, null, 'direct-recipe-zero')->assertCreated();
        $this->assertDatabaseCount('booking_deposits', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_direct_disabled_keeps_the_existing_no_deposit_mode(): void
    {
        config(['wallet.direct.enabled' => false]);
        $recipe = $this->recipe();
        $this->preview($recipe)->assertOk()->assertJsonPath('readiness.direct', false)->assertJsonPath('depositOk', true);
        $this->book($recipe, null, 'direct-recipe-feature-off')->assertCreated();
        $this->assertDatabaseCount('booking_deposits', 0);
    }

    public function test_recurring_preview_reports_the_missing_connection_for_each_occurrence(): void
    {
        $recipe = $this->recipe();
        $series = ['branchId' => $recipe['branchId'], 'selections' => $recipe['selections'], 'frequency' => 'WEEKLY',
            'startDate' => substr($recipe['startAt'], 0, 10), 'preferredTime' => '10:00', 'occurrenceCount' => 2, 'staffMode' => 'ANY_AVAILABLE'];
        $this->actor('customer');
        $this->postJson('/api/v1/recurring/preview', $series)->assertOk()->assertJsonPath('availableCount', 0)
            ->assertJsonPath('occurrences.0.readiness.direct', true)->assertJsonPath('occurrences.0.depositOk', false)
            ->assertJsonPath('occurrences.1.depositOk', false);
        $this->withHeader('Idempotency-Key', 'direct-recipe-series')->postJson('/api/v1/recurring', $series + [
            'contact' => $recipe['contact'], 'contactConsent' => true])->assertConflict();
        $this->assertDatabaseCount('bookings', 0);
    }
}
