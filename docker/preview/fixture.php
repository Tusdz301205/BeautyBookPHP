<?php

// Preview-only wallet fixtures (private LIVE_LAB scope): the demo customer already topped up through a signed Live-lab receipt,
// holds a deposit for a pending booking, and a completed booking released its deposit to the business as a payable.
// One invoice stays pending so the tester can send a signed receipt for it; the bank account stays PENDING for admin verification.
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\User;
use App\Services\Finance\SepayWebhookService;
use App\Services\Wallet\LiveWalletPolicy;
use App\Services\Wallet\WalletCore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(config('database.connections.mysql.database') === 'beautybook_preview', 500, 'preview database only');

$customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
$offering = BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))->orderBy('id')->firstOrFail();
$lab = LiveWalletPolicy::configuration();
$secret = $lab['secret'];

$invoice = function (int $amount) use ($customer, $lab) {
    DB::table('wallets')->insertOrIgnore(['customer_profile_id' => $customer->customerProfile->id, 'scope' => 'LIVE_LAB', 'created_at' => now(), 'updated_at' => now()]);
    $walletId = DB::table('wallets')->where('customer_profile_id', $customer->customerProfile->id)->where('scope', 'LIVE_LAB')->value('id');
    $code = 'WL'.strtoupper(bin2hex(random_bytes(12)));
    DB::table('wallet_topups')->insert(['wallet_id' => $walletId, 'key_hash' => hash('sha256', $code), 'fingerprint' => hash('sha256', 'preview'.$amount), 'code' => $code, 'amount_vnd' => $amount, 'provider' => 'SEPAY_LIVE',
        'expires_at' => now('UTC')->addMinutes(15)->format('Y-m-d H:i:s'), 'receiver_snapshot' => json_encode(LiveWalletPolicy::receiverSnapshot($lab)), 'created_at' => now(), 'updated_at' => now()]);

    return $code;
};
$receive = function (string $code, int $amount, int $id) use ($app, $secret, $lab) {
    $raw = json_encode(['id' => $id, 'gateway' => $lab['gateway'], 'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'), 'accountNumber' => $lab['account_number'], 'subAccount' => null,
        'code' => null, 'content' => $code.' nap vi', 'transferType' => 'in', 'transferAmount' => $amount, 'referenceCode' => 'PREVIEW-'.$id, 'description' => 'preview fixture']);
    $ts = (string) time();
    $request = Request::create('/api/v1/payments/sepay/wallet-live-lab/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SEPAY_TIMESTAMP' => $ts, 'HTTP_X_SEPAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, $secret)], $raw);
    $app->make(SepayWebhookService::class)->receiveLiveLab($request);
};
$booking = function (string $code, int $total, string $status, int $days) use ($customer, $offering) {
    $start = now('UTC')->addDays($days)->setTime(3, 0);
    $b = Booking::create(['business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
        'booking_code' => $code, 'status' => $status, 'appointment_start_at' => $start, 'appointment_end_at' => $start->copy()->addHour(), 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $total,
        'pending_expires_at' => $status === 'PENDING' ? now()->addDays(1) : null]);
    $b->services()->create(['business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
        'price_at_booking' => $total, 'currency' => 'VND', 'duration_minutes' => 60, 'service_name_snapshot' => $offering->businessService->name ?? 'Dịch vụ',
        'item_start_at' => $start, 'item_end_at' => $start->copy()->addHour(), 'occupied_start_at' => $start, 'occupied_end_at' => $start->copy()->addHour()]);

    return $b;
};
$hold = fn (Booking $b) => DB::transaction(fn () => WalletCore::hold(['bookingId' => $b->id, 'customerProfileId' => (int) $b->customer_profile_id, 'actorId' => (int) $customer->id, 'branchId' => (int) $b->branch_id, 'businessId' => (int) $b->business_id,
    'total' => $b->total_amount, 'cancellationHours' => 4, 'confirmationMode' => 'MANUAL', 'pendingHoldMinutes' => 15, 'now' => now('UTC'), 'startAt' => CarbonImmutable::parse($b->getRawOriginal('appointment_start_at'), 'UTC'),
    'acceptedQuote' => WalletCore::terms((int) $b->branch_id, $b->total_amount, 4)['quote']]));

$receive($invoice(100000), 100000, 880001);
$done = $booking('PREVIEW-PAID', 100000, 'CONFIRMED', 1);
$hold($done);
DB::transaction(fn () => WalletCore::transition($done->id, 'COMPLETED', ['now' => now('UTC')]));
$done->update(['status' => 'COMPLETED']);
$pending = $booking('PREVIEW-WAIT', 150000, 'PENDING', 2);
$hold($pending);
$waiting = $invoice(50000);
DB::table('business_bank_accounts')->insert(['business_id' => $offering->business_id, 'bank_code' => 'ACB', 'bank_name' => 'ACB', 'account_number' => '0000012345', 'account_name' => 'CONG TY DEMO', 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
echo json_encode(['wallet' => DB::table('wallets')->where('scope', 'LIVE_LAB')->first(['available_vnd', 'held_vnd']), 'releasedBooking' => $done->booking_code, 'heldBooking' => $pending->booking_code,
    'pendingTopUpCode' => $waiting, 'pendingTopUpAmountVnd' => 50000, 'bankAccount' => 'PENDING (admin must verify)'], JSON_PRETTY_PRINT), "\n";
