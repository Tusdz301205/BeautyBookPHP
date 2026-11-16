<?php
// Test data only: three released deposits (90,000 VND) and one held deposit (30,000 VND).
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\TestCase::assertSafeDatabaseConfiguration(config('database.connections.mysql'), config('database.default'), app()->environment('testing'), app()->configurationIsCached());
$lease = new PDO('mysql:host=mysql_test;dbname=beautybook_test', config('database.connections.mysql.username'), config('database.connections.mysql.password'));
if ((int) $lease->query("SELECT GET_LOCK('beautybook_test_suite',0)")->fetchColumn() !== 1) throw new RuntimeException('Another test suite is active.');
try {
    Illuminate\Support\Facades\DB::transaction(function () {
        $customer = App\Models\User::where('email', 'customer@beautybook.local')->firstOrFail();
        $branch = App\Models\Branch::where('slug', 'tan-tao')->firstOrFail();
        $wallet = App\Services\Wallet\WalletCore::lock($customer->customerProfile->id, 'LIVE_LAB');
        App\Services\Wallet\WalletCore::post(['operationKey'=>'qa-owner-finance-funds', 'kind'=>'TOPUP', 'amount'=>400000, 'walletId'=>$wallet->id, 'availableDelta'=>400000, 'actorId'=>$customer->id, 'reason'=>'QA_FIXTURE_NO_BANK_TRANSFER']);
        for ($i = 1; $i <= 4; $i++) {
            if (App\Models\Booking::where('booking_code', 'QA-OWNER-FINANCE-'.$i)->exists()) continue;
            $booking = App\Models\Booking::create(['business_id'=>$branch->business_id, 'branch_id'=>$branch->id, 'customer_profile_id'=>$customer->customerProfile->id, 'created_by_user_id'=>$customer->id, 'booking_code'=>'QA-OWNER-FINANCE-'.$i, 'appointment_start_at'=>now()->addDays(3+$i), 'appointment_end_at'=>now()->addDays(3+$i)->addHour(), 'status'=>'PENDING', 'source'=>'ONLINE_WEB', 'currency'=>'VND', 'total_amount'=>100000]);
            $terms = App\Services\Wallet\WalletCore::terms($branch->id, 100000, 4);
            App\Services\Wallet\WalletCore::hold(['bookingId'=>$booking->id, 'customerProfileId'=>$customer->customerProfile->id, 'actorId'=>$customer->id, 'branchId'=>$branch->id, 'businessId'=>$branch->business_id, 'total'=>100000, 'cancellationHours'=>4, 'confirmationMode'=>'MANUAL', 'pendingHoldMinutes'=>15, 'now'=>now('UTC'), 'startAt'=>Carbon\CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'),'UTC'), 'acceptedQuote'=>$terms['quote']]);
            if ($i <= 3) {
                App\Services\Wallet\WalletCore::transition($booking->id, 'COMPLETED', ['now'=>now('UTC')]);
                $booking->update(['status'=>'COMPLETED']);
            }
        }
    });
    echo "Owner finance QA: available=90000, held=30000 on a fresh fixture. No bank requests.\n";
} finally {
    $lease->query("SELECT RELEASE_LOCK('beautybook_test_suite')");
}
