<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\TestCase::assertSafeDatabaseConfiguration(config('database.connections.mysql'),config('database.default'),app()->environment('testing'),app()->configurationIsCached());
if(!config('wallet.public_wallet'))throw new RuntimeException('Expected public QA wallet');
Illuminate\Support\Facades\DB::transaction(function(){
    $customer=App\Models\User::where('email','customer@beautybook.local')->firstOrFail();
    $branch=App\Models\Branch::where('slug','tan-tao')->firstOrFail();
    $booking=App\Models\Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->customerProfile->id,'created_by_user_id'=>$customer->id,'booking_code'=>'QA-FINANCE-'.uniqid(),'appointment_start_at'=>now()->addDays(3),'appointment_end_at'=>now()->addDays(3)->addHour(),'status'=>'PENDING','source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>100000]);
    $terms=App\Services\Wallet\WalletCore::terms($branch->id,100000,4);
    App\Services\Wallet\WalletCore::hold(['bookingId'=>$booking->id,'customerProfileId'=>$customer->customerProfile->id,'actorId'=>$customer->id,'branchId'=>$branch->id,'businessId'=>$branch->business_id,'total'=>100000,'cancellationHours'=>4,'confirmationMode'=>'MANUAL','pendingHoldMinutes'=>15,'now'=>now('UTC'),'startAt'=>Carbon\CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'),'UTC'),'acceptedQuote'=>$terms['quote']]);
    App\Services\Wallet\WalletCore::transition($booking->id,'COMPLETED',['now'=>now('UTC')]);
    $booking->update(['status'=>'COMPLETED']);
});
echo "QA payable: 30000 held/released through wallet ledger from existing test funds. No bank calls.\n";
