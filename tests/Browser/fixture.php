<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\TestCase::assertSafeDatabaseConfiguration(config('database.connections.mysql'), config('database.default'), app()->environment('testing'), app()->configurationIsCached());
$lease = new PDO('mysql:host=mysql_test;dbname=beautybook_test', config('database.connections.mysql.username'), config('database.connections.mysql.password'));
if ((int)$lease->query("SELECT GET_LOCK('beautybook_test_suite',0)")->fetchColumn()!==1) throw new RuntimeException('Backend tests still running.');
try {
    Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed'=>true,'--force'=>true]);
    $branch = App\Models\Branch::where('slug','tan-tao')->firstOrFail();
    $customer = App\Models\User::where('email','customer@beautybook.local')->firstOrFail();
    $service = App\Models\BranchServiceOffering::where('branch_id',$branch->id)->orderBy('id')->firstOrFail();
    $staff = App\Models\StaffProfile::where('branch_id',$branch->id)->where('status','ACTIVE')->firstOrFail();
    $staff->offerings()->syncWithoutDetaching([$service->id=>['business_id'=>$branch->business_id,'branch_id'=>$branch->id]]);
    $combo = App\Models\Combo::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'name'=>'Combo thư giãn QA','combo_price'=>250001,'pricing_mode'=>'FIXED','staff_assignment_mode'=>'SINGLE_PROVIDER','status'=>'ACTIVE','version'=>1]);
    $other = App\Models\BranchServiceOffering::where('branch_id',$branch->id)->where('id','!=',$service->id)->firstOrFail();
    $staff->offerings()->syncWithoutDetaching([$other->id=>['business_id'=>$branch->business_id,'branch_id'=>$branch->id]]);
    foreach([$service,$other] as $index=>$offering) $combo->items()->create(['branch_service_offering_id'=>$offering->id,'quantity'=>1,'sort_order'=>$index,'transition_minutes'=>0,'price_snapshot'=>$offering->price,'duration_snapshot'=>$offering->duration_minutes]);
    $booking = App\Models\Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->customerProfile->id,'created_by_user_id'=>$customer->id,'booking_code'=>'QA-CHECKEDIN-'.uniqid(),'appointment_start_at'=>now()->subMinutes(5),'appointment_end_at'=>now()->addHour(),'status'=>'CHECKED_IN','source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>100000]);
    $booking->services()->create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'branch_service_offering_id'=>$service->id,'business_service_id'=>$service->business_service_id,'staff_profile_id'=>$staff->id,'service_name_snapshot'=>$service->service->name,'price_at_booking'=>100000,'currency'=>'VND','duration_minutes'=>60,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'sort_order'=>0,'status'=>'SCHEDULED','revision'=>1,'item_start_at'=>$booking->appointment_start_at,'item_end_at'=>$booking->appointment_end_at,'occupied_start_at'=>$booking->appointment_start_at,'occupied_end_at'=>$booking->appointment_end_at]);
    App\Models\Promotion::updateOrCreate(['business_id'=>$branch->business_id,'code'=>'BEAUTY20'],['name'=>'Ưu đãi QA 20%','target_mode'=>'ALL','auto_apply'=>false,'status'=>'ACTIVE','discount_type'=>'PERCENTAGE','discount_value'=>'20.00','start_date'=>now()->subDay(),'end_date'=>now()->addDays(30),'max_usage_per_customer'=>20,'branch_ids'=>[$branch->id]]);
    $date = Carbon\CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8); while($date->dayOfWeek===0)$date=$date->addDay();
    $disposable = App\Models\User::factory()->create(['name'=>'QA Disposable Status','email'=>'qa-status@beautybook.local','account_kind'=>'CUSTOMER','is_active'=>true,'email_verified_at'=>now()]);
    App\Models\CustomerProfile::create(['user_id'=>$disposable->id]);
    $pendingOwner = App\Models\User::factory()->create(['name'=>'QA Pending Owner','email'=>'qa-pending-owner@example.test','account_kind'=>'OPERATIONAL','is_active'=>true,'email_verified_at'=>now()]);
    $pendingProfile = App\Models\BusinessOwnerProfile::create(['user_id'=>$pendingOwner->id]);
    $pendingBusiness = $branch->business->replicate();
    $pendingBusiness->forceFill(['owner_profile_id'=>$pendingProfile->id,'name'=>'QA Pending Browser Review','slug'=>'qa-pending-browser-review','status'=>'PENDING_REVIEW','submitted_at'=>now(),'reviewed_at'=>null,'review_note'=>null,'contact_email'=>'qa-pending-owner@example.test'])->save();
    app(App\Services\Platform\PlatformSettingsService::class)->update(['maxAdvanceBookingDays'=>90,'freeCancellationHours'=>4], App\Models\User::where('email','admin@beautybook.local')->firstOrFail()->id);
    $fixture = ['bookingId'=>(string)$booking->id,'branchId'=>(string)$branch->id,'businessId'=>(string)$branch->business_id,'serviceId'=>(string)$service->id,'comboId'=>(string)$combo->id,'date'=>$date->toDateString()];
    file_put_contents(base_path('storage/app/private/full-qa/fixture.json'), json_encode($fixture));
    file_put_contents(base_path('storage/app/private/booking-qa/fixture.json'), json_encode($fixture));
    echo "Fresh isolated QA fixture ready.\n";
} finally {
    $lease->query("SELECT RELEASE_LOCK('beautybook_test_suite')");
}
