<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Finance\FinancialPolicyService;
use App\Services\Finance\PackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvancedFinanceTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed();}

    public function test_exact_email_lookup_adds_first_time_customer_only_to_authorized_workspace_picker():void
    {
        $user=User::create(['name'=>'First Time Customer','email'=>'first-package@example.test','password'=>'password','account_kind'=>'CUSTOMER']);
        $profile=CustomerProfile::create(['user_id'=>$user->id]);
        $this->actor('customer');$this->send(route('finance.customers.lookup'),['email'=>$user->email])->assertForbidden();
        $this->actor('owner');
        $businessId=User::where('email','owner@beautybook.local')->firstOrFail()->roleAssignments()->where('role_name','BUSINESS_OWNER')->value('business_id');
        $this->send(route('finance.packages.store',$businessId),['name'=>'First time package','total_price'=>'1.00','session_count'=>1,'validity_days'=>30])->assertCreated();
        $this->get(route('finance.advanced'))->assertOk()->assertDontSee('First Time Customer');
        $this->send(route('finance.customers.lookup'),['email'=>'first-package'])->assertUnprocessable();
        $this->send(route('finance.customers.lookup'),['email'=>'FIRST-PACKAGE@example.test'])->assertOk()->assertJsonPath('customer_profile_id',$profile->id)->assertJsonPath('name','First Time Customer');
        $this->get(route('finance.advanced'))->assertOk()->assertSee('First Time Customer');
        $this->actor('owner2');
        $this->get(route('finance.advanced'))->assertOk()->assertDontSee('First Time Customer');
        $this->assertDatabaseHas('audit_logs',['action'=>'CUSTOMER_LOOKUP','entity_id'=>(string)$profile->id]);
    }

    public function test_policy_version_and_booking_deposit_snapshot_are_immutable():void
    {
        $booking=$this->booking('100.00');$this->actor('owner');
        $data=$this->policy(['deposit_type'=>'PERCENTAGE','deposit_value'=>'25.00']);
        $this->send(route('finance.policies.store',$booking->business_id),$data)->assertCreated();
        $booking->update(['created_at'=>now()]);
        $snapshot=app(FinancialPolicyService::class)->capturePolicy($booking->fresh());
        $this->assertSame(2500,(int)$snapshot->required_amount_cents);
        $this->send(route('finance.policies.store',$booking->business_id),[...$data,'deposit_value'=>'50.00'])->assertCreated();
        $this->assertSame(2500,(int)app(FinancialPolicyService::class)->capturePolicy($booking)->required_amount_cents);
        $this->assertDatabaseHas('payment_policies',['version'=>1,'status'=>'ARCHIVED']);
        $this->assertDatabaseHas('payment_policies',['version'=>2,'status'=>'ACTIVE']);
        $this->actor('owner2');$this->send(route('finance.policies.store',$booking->business_id),$data)->assertForbidden();
    }

    public function test_real_booking_captures_service_policy_after_items_and_honors_branch_scope():void
    {
        $branch=Branch::where('slug','tan-tao')->firstOrFail();
        $offering=BranchServiceOffering::where('branch_id',$branch->id)->firstOrFail();
        $staff=\App\Models\StaffProfile::where('branch_id',$branch->id)->firstOrFail();
        $this->actor('owner');
        $valid=$this->send(route('finance.policies.store',$branch->business_id),$this->policy(['name'=>'Service deposit','branch_id'=>$branch->id,'business_service_id'=>$offering->business_service_id,'deposit_type'=>'PERCENTAGE','deposit_value'=>'25.00']))->assertCreated()->json('id');
        $other=Branch::where('business_id',$branch->business_id)->where('id','!=',$branch->id)->firstOrFail();
        $this->send(route('finance.policies.store',$branch->business_id),$this->policy(['name'=>'Other branch only','branch_id'=>$other->id,'business_service_id'=>$offering->business_service_id,'deposit_type'=>'FULL_PREPAYMENT']))->assertCreated();
        $this->actor('customer');
        $start=\Carbon\CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(11,0);
        while ($start->dayOfWeek===0) $start=$start->addDay();
        $bookingId=$this->withHeader('Idempotency-Key','service-policy-real')->send(route('booking.store',$branch),['branch_service_offering_id'=>$offering->id,'staff_profile_id'=>$staff->id,'start_at'=>$start->format('Y-m-d\TH:i')])->assertCreated()->json('booking_id');
        $snapshot=DB::table('payment_policy_snapshots')->where('booking_id',$bookingId)->first();
        $this->assertSame($valid,json_decode($snapshot->snapshot,true)['payment_policy_id']);
        $this->assertSame(4500000,(int)$snapshot->required_amount_cents);
    }

    public function test_package_installments_split_remainder_and_manual_verify_activate_then_reverse():void
    {
        $booking=$this->booking('1.00');$this->actor('owner');
        $package=$this->send(route('finance.packages.store',$booking->business_id),['name'=>'Liệu trình','total_price'=>'1.00','session_count'=>2,'validity_days'=>30])->assertCreated()->json('id');
        $purchaseData=['customer_profile_id'=>$booking->customer_profile_id,'branch_id'=>$booking->branch_id,'installment_count'=>3];
        $this->send(route('finance.packages.purchase',$package),$purchaseData)->assertConflict();
        $this->send(route('finance.policies.store',$booking->business_id),$this->policy(['allow_installments'=>true]))->assertCreated();
        $purchase=$this->send(route('finance.packages.purchase',$package),$purchaseData)->assertCreated()->json('id');
        $installments=DB::table('package_installments')->where('package_purchase_id',$purchase)->orderBy('sequence')->get();
        $this->assertSame([33,33,34],$installments->pluck('amount_cents')->map(fn($x)=>(int)$x)->all());
        $transaction=$this->send(route('finance.installments.pay',$installments[0]->id),['method'=>'BANK_TRANSFER','idempotency_key'=>'pkg-bank'])->assertOk()->assertJsonPath('status','PENDING')->json('id');
        $this->send(route('finance.transaction',[$transaction,'verify']),['settlement_reference'=>'WRONG-ROUTE','evidence'=>['receipt'=>'Proof']])->assertNotFound();
        $this->assertDatabaseHas('package_purchases',['id'=>$purchase,'status'=>'PENDING_PAYMENT','paid_cents'=>0]);
        $this->send(route('finance.packages.transaction',[$transaction,'verify']),['settlement_reference'=>'PKG-BANK-1','evidence'=>['receipt'=>'Bank evidence']])->assertOk();
        $this->assertDatabaseHas('package_purchases',['id'=>$purchase,'status'=>'ACTIVE','paid_cents'=>33]);
        $this->send(route('finance.packages.transaction',[$transaction,'reverse']),['reason'=>'Ghi nhận sai','idempotency_key'=>'pkg-reverse'])->assertOk();
        $this->assertDatabaseHas('package_purchases',['id'=>$purchase,'status'=>'PENDING_PAYMENT','paid_cents'=>0]);
        $this->assertDatabaseHas('package_installments',['id'=>$installments[0]->id,'status'=>'DUE']);
        $this->get(route('finance.advanced'))->assertOk()->assertSee('Liệu trình');
    }

    public function test_package_reservation_has_customer_branch_scope_and_cancel_release_complete_redeem():void
    {
        $booking=$this->booking('1.00','CONFIRMED',true);$this->actor('owner');
        $package=$this->send(route('finance.packages.store',$booking->business_id),['name'=>'Một lượt','total_price'=>'1.00','session_count'=>1,'validity_days'=>30])->assertCreated()->json('id');
        $purchase=$this->send(route('finance.packages.purchase',$package),['customer_profile_id'=>$booking->customer_profile_id,'branch_id'=>$booking->branch_id,'installment_count'=>1])->assertCreated()->json('id');
        $installment=DB::table('package_installments')->where('package_purchase_id',$purchase)->value('id');
        $this->send(route('finance.installments.pay',$installment),['method'=>'CASH','idempotency_key'=>'pkg-cash'])->assertOk();
        $this->actor('owner2');$this->send(route('finance.packages.reserve',$purchase),['booking_id'=>$booking->id,'booking_service_id'=>$booking->services()->value('id')])->assertForbidden();
        $this->actor('customer');$data=['booking_id'=>$booking->id,'booking_service_id'=>$booking->services()->value('id')];
        $this->send(route('finance.packages.reserve',$purchase),$data)->assertOk()->assertJsonPath('status','RESERVED');
        $this->send(route('finance.packages.reserve',$purchase),$data)->assertConflict();
        DB::transaction(fn()=>app(PackageService::class)->redeemForBooking($booking->id,'CANCELLED'));
        $this->assertDatabaseHas('package_session_entitlements',['package_purchase_id'=>$purchase,'status'=>'AVAILABLE','booking_id'=>null]);
        foreach (['EXPIRED','REJECTED'] as $releasedStatus) {
            $this->send(route('finance.packages.reserve',$purchase),$data)->assertOk();
            DB::transaction(fn()=>app(PackageService::class)->redeemForBooking($booking->id,$releasedStatus));
            $this->assertDatabaseHas('package_session_entitlements',['package_purchase_id'=>$purchase,'status'=>'AVAILABLE','booking_id'=>null]);
        }
        $this->send(route('finance.packages.reserve',$purchase),$data)->assertOk();
        DB::transaction(fn()=>app(PackageService::class)->redeemForBooking($booking->id,'COMPLETED'));
        $this->assertDatabaseHas('package_purchases',['id'=>$purchase,'status'=>'COMPLETED']);
        $this->assertDatabaseHas('package_session_entitlements',['package_purchase_id'=>$purchase,'status'=>'REDEEMED']);
    }

    public function test_fees_statements_and_invoice_request_issue_replacement_lifecycle():void
    {
        $booking=$this->booking('100.00','COMPLETED',true);$this->actor('customer');
        $requestData=['buyer_name'=>'Buyer Co','buyer_tax_code'=>'TAX-1','idempotency_key'=>'invoice-info'];
        $this->send(route('finance.invoice-requests.store',$booking),$requestData)->assertConflict();
        $this->actor('owner');$this->send(route('finance.collect',$booking),['method'=>'CASH','amount'=>'100.00','idempotency_key'=>'invoice-paid'])->assertCreated();
        $this->assertDatabaseHas('platform_fee_entries',['booking_id'=>$booking->id,'base_cents'=>10000,'fee_cents'=>500]);
        $this->actor('customer');$requestId=$this->send(route('finance.invoice-requests.store',$booking),$requestData)->assertCreated()->json('id');
        $this->send(route('finance.invoice-requests.store',$booking),$requestData)->assertCreated()->assertJsonPath('id',$requestId);
        $this->actor('owner');$invoice=$this->send(route('finance.invoices.store',$booking),['invoice_request_id'=>$requestId,'tax_rate'=>'10.00','tax_inclusive'=>false])->assertCreated()->json('id');
        $this->assertDatabaseHas('invoices',['id'=>$invoice,'tax_cents'=>1000,'total_cents'=>11000]);
        $this->assertDatabaseHas('invoice_information_requests',['id'=>$requestId,'status'=>'FULFILLED','open_key'=>null]);
        $replacement=$this->send(route('finance.invoices.action',[$invoice,'reissue']),['reason'=>'Sửa người mua','buyer_name'=>'Buyer Correct'])->assertOk()->json('id');
        $this->assertDatabaseHas('invoices',['id'=>$invoice,'status'=>'ADJUSTED']);
        $this->assertDatabaseHas('invoices',['id'=>$replacement,'replaces_invoice_id'=>$invoice,'version'=>2]);
        $this->send(route('finance.invoices.action',[$invoice,'reissue']),['reason'=>'Lần hai'])->assertConflict();
        $this->get(route('finance.invoice',$replacement))->assertOk()->assertSee('Buyer Correct');
        $this->actor('admin');$period=['period_start'=>now()->startOfMonth()->format('Y-m-d'),'period_end'=>now()->format('Y-m-d')];
        $statement=$this->send(route('finance.statements.store',$booking->business_id),$period)->assertOk()->json('id');
        $this->send(route('finance.statements.store',$booking->business_id),$period)->assertOk()->assertJsonPath('id',$statement);
        $this->send(route('finance.statements.transition',$statement),['status'=>'PAID','reason'=>'Skip'])->assertConflict();
        foreach(['REVIEW','ISSUED','OVERDUE','PAID']as$status)$this->send(route('finance.statements.transition',$statement),['status'=>$status,'reason'=>'Đã kiểm tra'])->assertOk();
        $this->assertDatabaseHas('platform_statements',['id'=>$statement,'net_cents'=>500,'status'=>'PAID']);
        $this->assertDatabaseCount('invoice_events',3);
    }

    private function policy(array $extra=[]):array{return [...['name'=>'Default','deposit_type'=>'NONE','deposit_value'=>'0','allow_split_payment'=>true,'allow_installments'=>false,'effective_from'=>now()->subHour()->format('Y-m-d H:i:s')],...$extra];}
    private function actor(string $alias):void{$user=User::where('email',$alias.'@beautybook.local')->firstOrFail();$this->actingAs($user)->withSession(['workspace_assignment_id'=>$user->roleAssignments()->first()?->id]);}
    private function send(string $url,array $data){return $this->withSession(['_token'=>'advanced-finance-csrf'])->withHeaders(['X-CSRF-TOKEN'=>'advanced-finance-csrf'])->postJson($url,$data);}
    private function booking(string $amount,string $status='COMPLETED',bool $withItem=false):Booking
    {
        $customer=User::where('email','customer@beautybook.local')->firstOrFail();$branch=Branch::where('slug','tan-tao')->firstOrFail();$start=now()->subDay();$end=$start->copy()->addHour();
        $booking=Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>CustomerProfile::where('user_id',$customer->id)->value('id'),'created_by_user_id'=>$customer->id,
            'booking_code'=>'ADV-'.uniqid(),'appointment_start_at'=>$start,'appointment_end_at'=>$end,'status'=>$status,'source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>$amount]);
        if($withItem){$offering=BranchServiceOffering::where('branch_id',$branch->id)->firstOrFail();$booking->services()->create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'branch_service_offering_id'=>$offering->id,'business_service_id'=>$offering->business_service_id,
            'price_at_booking'=>$amount,'currency'=>'VND','duration_minutes'=>60,'service_name_snapshot'=>'Test service','item_start_at'=>$start,'item_end_at'=>$end,'occupied_start_at'=>$start,'occupied_end_at'=>$end]);}
        return $booking;
    }
}
