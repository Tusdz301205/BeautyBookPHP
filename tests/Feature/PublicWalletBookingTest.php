<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Services\Wallet\LiveWalletPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

class PublicWalletBookingTest extends TestCase
{
    use RefreshDatabase, WalletFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->configureLab();
        config(['beautybook.legacy_ui' => false, 'wallet.public_wallet' => true, 'wallet.booking_deposit_enabled' => true, 'wallet.central' => config('wallet.live_lab'), 'wallet.live_lab.user_ids' => '', 'wallet.live_lab.business_ids' => '', 'wallet.direct.enabled' => false]);
    }

    private function createRecipe(string $key): array
    {
        $this->actor('customer');
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $service = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $date = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8);
        while ($date->dayOfWeek === 0) {
            $date = $date->addDay();
        }
        $recipe = ['branchId' => (string) $branch->id, 'selections' => [['serviceId' => (string) $service->id, 'quantity' => 1]]];
        $price = $this->postJson('/api/v1/bookings/preview-price', $recipe)->assertOk()->json('finalAmount');
        $terms = $this->getJson('/api/v1/wallet/deposit-terms?branchId='.$branch->id.'&total='.(int) $price)->assertOk()->assertJsonPath('walletMode', true)->json('terms');
        $slots = $this->postJson('/api/v1/bookings/available-slots', $recipe + ['date' => $date->toDateString()])->assertOk()->json('slots');
        $this->assertNotEmpty($slots);
        $payload = $recipe + ['startAt' => $slots[0]['start'], 'contact' => ['name' => 'Khách dùng ví chung', 'phone' => '0901234567'], 'contactConsent' => true, 'walletDepositQuote' => $terms['quote']];
        $created = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', $payload)->assertCreated()->json();

        return [$created, $payload, $terms];
    }

    public function test_public_customer_topup_hold_replay_and_owner_rejection_refund(): void
    {
        $invoice = $this->invoice(200000);
        $proof = $this->payload($invoice);
        $this->deliver(self::LAB_URL, $proof)->assertOk();
        $this->deliver(self::LAB_URL, $proof)->assertOk();
        $this->assertSame('200000', $this->wallet()['availableVnd']);
        [$booking,$payload,$terms] = $this->createRecipe('public-wallet-booking');
        $this->assertSame((string) $terms['depositVnd'], $this->wallet()['heldVnd']);
        $this->withHeader('Idempotency-Key', 'public-wallet-booking')->postJson('/api/v1/bookings', $payload)->assertCreated()->assertJsonPath('id', $booking['id']);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(1, DB::table('wallet_transfers')->where('kind', 'HOLD')->count());
        $this->actor('owner');
        $this->patchJson('/api/v1/bookings/'.$booking['id'].'/status', ['status' => 'REJECTED', 'expectedVersion' => $booking['version'], 'reason' => 'Không thể tiếp nhận'])->assertOk();
        $this->assertSame('200000', $this->wallet()['availableVnd']);
        $this->assertSame('0', $this->wallet()['heldVnd']);
        $this->assertDatabaseHas('booking_deposits', ['booking_id' => $booking['id'], 'status' => 'REFUNDED']);
    }

    public function test_complete_converts_held_deposit_to_business_payable(): void
    {
        $this->fund(200000);
        [$booking,,$terms] = $this->createRecipe('public-wallet-complete');
        $this->actor('owner');
        $url = '/api/v1/bookings/'.$booking['id'].'/status';
        $confirmed = $this->patchJson($url, ['status' => 'CONFIRMED', 'expectedVersion' => $booking['version']])->assertOk()->json();
        CarbonImmutable::setTestNow(CarbonImmutable::parse($booking['appointmentStartAt'] ?? $booking['bookingServices'][0]['itemStartAt']));
        $arrived = $this->patchJson($url, ['status' => 'CHECKED_IN', 'expectedVersion' => $confirmed['version']])->assertOk()->json();
        $this->patchJson($url, ['status' => 'COMPLETED', 'expectedVersion' => $arrived['version']])->assertOk();
        $this->assertSame('0', $this->wallet()['heldVnd']);
        $this->assertDatabaseHas('business_payables', ['amount_vnd' => $terms['depositVnd']]);
        $this->assertDatabaseHas('booking_deposits', ['booking_id' => $booking['id'], 'status' => 'APPLIED']);
    }

    public function test_spending_existing_balance_does_not_require_topup_bank_setup(): void
    {
        $this->fund(200000);
        config(['wallet.central.enabled' => false, 'wallet.central.webhook_secret' => '']);
        $this->actor('customer');
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('bookingDepositAvailable', true)->assertJsonPath('topUpAvailable', false);
        $this->withHeader('Idempotency-Key', 'no-bank-config-topup')->postJson('/api/v1/wallet/topups', ['amountVnd' => '50000', 'provider' => 'SEPAY_LIVE'])->assertStatus(503);
        [$booking] = $this->createRecipe('public-wallet-bank-unset');
        $this->assertDatabaseHas('booking_deposits', ['booking_id' => $booking['id'], 'status' => 'HELD']);
    }

    public function test_topups_remain_available_when_booking_deposits_are_disabled(): void
    {
        config(['wallet.booking_deposit_enabled' => false]);
        $this->actor('customer');
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('bookingDepositAvailable', false)->assertJsonPath('topUpAvailable', true);
        $invoice = $this->invoice(50000);
        $this->deliver(self::LAB_URL, $this->payload($invoice))->assertOk();
        $this->assertSame('50000', $this->wallet()['availableVnd']);
        $this->assertFalse(LiveWalletPolicy::walletMode());
    }
}
