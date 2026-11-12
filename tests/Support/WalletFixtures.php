<?php

namespace Tests\Support;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\User;
use App\Services\Wallet\WalletCore;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Shared setup for the wallet / SePay / payout suites: LIVE_LAB config, actors, signed receipts, deposits. */
trait WalletFixtures
{
    private const LAB_SECRET = 'lab-only-secret-0123456789abcdef0123456789abcdef';
    private const SEPAY_SECRET = 'test-only-secret-0123456789abcdef0123456789abcdef';
    private const ORDINARY_URL = '/api/v1/payments/sepay/webhook';
    private const LAB_URL = '/api/v1/payments/sepay/wallet-live-lab/webhook';

    protected function configureLab(array $override = []): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->value('id');
        $business = Branch::where('slug', 'tan-tao')->value('business_id');
        config(['wallet.mode' => 'INTERNAL_WALLET', 'wallet.live_lab' => $override + [
            'isolated' => true, 'enabled' => true, 'user_ids' => (string) $customer, 'business_ids' => (string) $business, 'webhook_secret' => self::LAB_SECRET,
            'bank_code' => 'ACB', 'account_number' => '0000000001', 'account_name' => 'CONG TY THU NGHIEM', 'min_topup_vnd' => '1000', 'max_topup_vnd' => '200000', 'max_total_vnd' => '1000000',
        ]]);
        config(['services.sepay' => ['enabled' => true, 'environment' => 'test', 'secret' => self::SEPAY_SECRET, 'gateway' => 'ACB', 'account_number' => '0000000001']]);
    }

    protected function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession([
            'workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id,
            'beautybook_access_hash' => hash('sha256', 'wallet-fixture'),
            'beautybook_access_expires' => time() + 900,
        ])->withHeader('Authorization', 'Bearer wallet-fixture');

        return $user;
    }

    protected function key(): string
    {
        return 'idem-'.bin2hex(random_bytes(10));
    }

    /** @return array<string,mixed> the invoice json */
    protected function invoice(int $amount, ?string $key = null): array
    {
        $this->actor('customer');

        return $this->withHeader('Idempotency-Key', $key ?? $this->key())->postJson('/api/v1/wallet/topups', ['amountVnd' => (string) $amount, 'provider' => 'SEPAY_LIVE'])->assertOk()->json('topUp');
    }

    protected function payload(array $invoice, array $patch = []): array
    {
        return array_merge([
            'id' => random_int(100000, 99999999), 'gateway' => 'ACB', 'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'), 'accountNumber' => '0000000001', 'subAccount' => null,
            'code' => null, 'content' => $invoice['code'].' nap vi', 'transferType' => 'in', 'transferAmount' => (int) $invoice['amountVnd'],
            'referenceCode' => 'TEST-ONLY-FT123456', 'description' => 'Giao dich thu nghiem', 'accumulated' => 0,
        ], $patch);
    }

    protected function deliver(string $url, array $payload, ?string $secret = null, ?int $timestamp = null, string $contentType = 'application/json'): TestResponse
    {
        return $this->signedRaw($url, json_encode($payload, JSON_UNESCAPED_UNICODE), $secret, $timestamp, $contentType);
    }

    protected function signedRaw(string $url, string $raw, ?string $secret = null, ?int $timestamp = null, string $contentType = 'application/json'): TestResponse
    {
        $timestamp ??= time();
        $secret ??= $url === self::LAB_URL ? self::LAB_SECRET : self::SEPAY_SECRET;

        return $this->rawCall($url, $raw, ['CONTENT_TYPE' => $contentType, 'HTTP_X_SEPAY_TIMESTAMP' => (string) $timestamp, 'HTTP_X_SEPAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret)]);
    }

    protected function rawCall(string $url, string $raw, array $server): TestResponse
    {
        return $this->call('POST', $url, [], [], [], $server + ['HTTP_ACCEPT' => 'application/json'], $raw);
    }

    /** Credits the customer's LIVE_LAB wallet through the real top-up and webhook path. */
    protected function fund(int $amount): void
    {
        // One invoice is capped (200.000 in the fixtures), so larger balances arrive as several real top-ups.
        while ($amount > 0) {
            $chunk = min($amount, 200000);
            if ($amount - $chunk > 0 && $amount - $chunk < 1000) $chunk -= 1000;
            $this->deliver(self::LAB_URL, $this->payload($this->invoice($chunk)))->assertOk();
            $amount -= $chunk;
        }
    }

    protected function wallet(): array
    {
        $this->actor('customer');

        return $this->getJson('/api/v1/wallet')->assertOk()->json('wallet');
    }

    protected function booking(int $total, string $status = 'CONFIRMED'): Booking
    {
        $offering = BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))->orderBy('id')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = now('UTC')->addDays(2);
        $end = $start->copy()->addHour();
        $booking = Booking::create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'WAL-'.bin2hex(random_bytes(4)), 'status' => $status, 'appointment_start_at' => $start, 'appointment_end_at' => $end,
            'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $total, 'pending_expires_at' => $status === 'PENDING' ? now()->addMinutes(15) : null,
        ]);
        $booking->services()->create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
            'price_at_booking' => $total, 'currency' => 'VND', 'duration_minutes' => 60, 'service_name_snapshot' => 'Fixture service',
            'item_start_at' => $start, 'item_end_at' => $end, 'occupied_start_at' => $start, 'occupied_end_at' => $end,
        ]);

        return $booking;
    }

    /** Holds the 30% deposit directly (the booking API path is covered by its own test). */
    protected function heldDeposit(Booking $booking): object
    {
        $quote = WalletCore::terms((int) $booking->branch_id, (int) $booking->total_amount, 4)['quote'];

        return DB::transaction(fn () => WalletCore::hold(['bookingId' => $booking->id, 'customerProfileId' => (int) $booking->customer_profile_id, 'actorId' => (int) $booking->created_by_user_id,
            'branchId' => (int) $booking->branch_id, 'businessId' => (int) $booking->business_id, 'total' => $booking->total_amount, 'cancellationHours' => 4, 'confirmationMode' => 'MANUAL',
            'pendingHoldMinutes' => 15, 'now' => now('UTC'), 'startAt' => \Carbon\CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC'), 'acceptedQuote' => $quote]));
    }

    /** A completed booking whose deposit became a payable of the business (what payouts draw on). */
    protected function releasedPayable(int $total): array
    {
        $this->fund(max(1000, (int) ceil($total * 0.3)));
        $booking = $this->booking($total);
        $deposit = $this->heldDeposit($booking);
        DB::transaction(fn () => WalletCore::transition($booking->id, 'COMPLETED', ['now' => now('UTC')]));
        $booking->update(['status' => 'COMPLETED']);

        return [$booking, DB::table('booking_deposits')->find($deposit->id), DB::table('business_payables')->where('deposit_id', $deposit->id)->first()];
    }
}
