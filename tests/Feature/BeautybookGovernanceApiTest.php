<?php

namespace Tests\Feature;

use App\Models\{Booking,Branch,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeautybookGovernanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function login(string $email): User
    {
        $user=User::where('email',$email)->firstOrFail();
        $assignment=$user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id'=>$assignment->id,'_token'=>'governance-api-test','beautybook_access_hash'=>hash('sha256','governance-access-token'),'beautybook_access_expires'=>time()+900])->withHeaders(['Authorization'=>'Bearer governance-access-token']);
        return $user;
    }

    private function headers(): array { return ['X-CSRF-TOKEN'=>'governance-api-test']; }

    public function test_public_policy_and_admin_setting_override_reset_contract(): void
    {
        $this->getJson('/api/v1/platform-settings/public')->assertOk()->assertJsonPath('freeCancellationHours',4);
        $this->login('customer@beautybook.local');
        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->login('admin@beautybook.local');
        $this->patchJson('/api/v1/admin/settings',['settings'=>['reviewMinLength'=>30,'freeCancellationHours'=>4]],$this->headers())->assertOk()->assertJsonPath('configured.reviewMinLength',30)->assertJsonPath('effective.reviewMinLength',30);
        $this->patchJson('/api/v1/admin/settings',['settings'=>['unknownFeature'=>true]],$this->headers())->assertUnprocessable();
        $this->postJson('/api/v1/admin/settings/reset',[],$this->headers())->assertOk()->assertJsonPath('configured',[])->assertJsonPath('effective.reviewMinLength',0);
    }

    public function test_report_authorization_and_cash_include_only_selected_tenant(): void
    {
        $owner=$this->login('owner@beautybook.local');
        $assignment=$owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail();
        $local=Branch::where('business_id',$assignment->business_id)->firstOrFail();
        $foreign=Branch::where('business_id','!=',$assignment->business_id)->firstOrFail();
        foreach([$local,$foreign] as$branch) {
            $b=Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>User::where('email','customer@beautybook.local')->firstOrFail()->customerProfile->id,'created_by_user_id'=>$owner->id,'booking_code'=>'REPORT-'.$branch->id,'appointment_start_at'=>now()->utc(),'appointment_end_at'=>now()->utc()->addHour(),'status'=>'COMPLETED','source'=>'PHONE','currency'=>'VND','total_amount'=>'100.00']);
            $transaction=DB::table('payment_transactions')->insertGetId(['booking_id'=>$b->id,'amount_cents'=>10000,'currency'=>'VND','method'=>'CASH','status'=>'VERIFIED','idempotency_key'=>'REPORT-'.$branch->id,'created_by'=>$owner->id,'verified_by'=>$owner->id,'verified_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('payment_ledger_entries')->insert(['booking_id'=>$b->id,'payment_transaction_id'=>$transaction,'type'=>'PAYMENT_RECEIVED','direction'=>'CREDIT','amount_cents'=>10000,'idempotency_key'=>'REPORT-LEDGER-'.$branch->id,'actor_id'=>$owner->id,'created_at'=>now()]);
        }
        $this->getJson('/api/v1/reports/overview')->assertOk()->assertJsonPath('totalBookings',1)->assertJsonPath('netRevenue',100);
        $this->getJson('/api/v1/reports/overview?branchId='.$foreign->id)->assertForbidden();
        $this->getJson('/api/v1/reports/user-growth')->assertForbidden();
        $this->login('customer@beautybook.local');
        $this->getJson('/api/v1/reports/overview')->assertForbidden();
    }

    public function test_owner_dashboard_and_financial_summary_supply_original_chart_contract(): void
    {
        $this->login('owner@beautybook.local'); $from=now()->startOfMonth()->toDateString(); $to=now()->endOfMonth()->toDateString();
        $response=$this->getJson('/api/v1/reports/owner-dashboard?from='.$from.'&to='.$to)->assertOk()->assertJsonStructure(['range'=>['from','to','previousFrom','previousTo'],'kpis'=>['netRevenue'=>['current','previous','changePercent']],'charts'=>['revenueSeries','bookingStatus','branchComparison','topServices','topCombos','review']]);
        $this->assertCount(now()->daysInMonth,$response->json('charts.revenueSeries'));
        $this->getJson('/api/v1/reports/financial-summary?from='.$from.'&to='.$to)->assertOk()->assertJsonStructure(['grossCollected','refundAmount','netCollected','recognizedServiceRevenue','outstandingAmount','drillDown']);
        $this->getJson('/api/v1/reports/financial-summary?from=2026-12-31&to=2026-01-01')->assertUnprocessable();
    }

    public function test_financial_discounts_follow_applied_local_day_instead_of_appointment_day(): void
    {
        $owner=$this->login('owner@beautybook.local');
        $businessId=$owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail()->business_id;
        $local=Branch::where('business_id',$businessId)->firstOrFail();
        $foreign=Branch::where('business_id','!=',$businessId)->firstOrFail();
        $customer=User::where('email','customer@beautybook.local')->firstOrFail()->customerProfile;
        $ids=[];
        // Match the actual now() writers: timestamps in these SQL tables use
        // the application timezone; 00:30 on Oct8 corresponds to Oct7 17:30UTC.
        foreach([[$local,'APPLIED','2026-10-08 00:30:00','125.00'],[$foreign,'APPLIED','2026-10-08 00:30:00','999.00'],[$local,'RELEASED','2026-10-08 00:30:00','500.00'],[$local,'APPLIED','2026-10-07 00:30:00','1000.00']] as$i=>[$branch,$status,$applied,$amount]) {
            $booking=Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->id,'created_by_user_id'=>$owner->id,'booking_code'=>'DISCOUNT-PERIOD-'.$i,'appointment_start_at'=>'2026-12-01 03:00:00','appointment_end_at'=>'2026-12-01 04:00:00','status'=>'CONFIRMED','source'=>'PHONE','currency'=>'VND','total_amount'=>'2000.00']);
            $promotion=DB::table('promotions')->insertGetId(['business_id'=>$branch->business_id,'name'=>'Period discount '.$i,'discount_type'=>'FIXED','discount_value'=>$amount,'start_date'=>'2026-01-01','end_date'=>'2027-01-01','created_at'=>now(),'updated_at'=>now()]);
            $ids[]=DB::table('promotion_redemptions')->insertGetId(['promotion_id'=>$promotion,'booking_id'=>$booking->id,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->id,'amount'=>$amount,'rule_snapshot'=>'{}','status'=>$status,'applied_at'=>$applied,'created_at'=>$applied,'updated_at'=>$applied]);
        }
        $this->getJson('/api/v1/reports/financial-summary?from=2026-10-08&to=2026-10-08')->assertOk()->assertJsonPath('bookedCount',0)->assertJsonPath('discountAmount',125)->assertJsonPath('drillDown.adjustmentIds',['promotion_redemptions:'.$ids[0]]);
    }

    public function test_privacy_contract_persists_preferences_and_exports_once(): void
    {
        $customer=$this->login('customer@beautybook.local');
        $preferences=['emailMarketing'=>true,'smsMarketing'=>false,'pushMarketing'=>true,'personalizedPromotions'=>false];
        $this->putJson('/api/v1/privacy/marketing-preferences',$preferences,$this->headers())->assertOk()->assertJsonPath('emailMarketing',true);
        $this->assertDatabaseHas('marketing_preferences',['customer_profile_id'=>$customer->customerProfile->id,'email_marketing'=>1]);
        $this->postJson('/api/v1/privacy/data-requests',['type'=>'ERASURE','reason'=>'Yêu cầu thực'], $this->headers())->assertCreated()->assertJsonPath('status','RECEIVED');
        $this->getJson('/api/v1/privacy/center')->assertOk()->assertJsonPath('account.email',$customer->email)->assertJsonCount(1,'sections.dataRequests');
        $result=$this->postJson('/api/v1/privacy/exports',['currentPassword'=>'BeautyBook123!'],$this->headers())->assertCreated();
        $id=$result->json('id');$token=$result->json('downloadToken');
        $this->getJson('/api/v1/privacy/exports/'.$id.'/download',['X-Privacy-Download-Token'=>$token])->assertOk()->assertJsonPath('account.email',$customer->email);
        $this->getJson('/api/v1/privacy/exports/'.$id.'/download',['X-Privacy-Download-Token'=>$token])->assertNotFound();
        $this->login('owner@beautybook.local');
        $this->getJson('/api/v1/privacy/center')->assertForbidden();
    }

    public function test_real_cash_collection_stays_on_its_branch_local_day_after_seventeen_oclock(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-07T11:30:00Z'));
        $owner=$this->login('owner@beautybook.local');
        $businessId=$owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail()->business_id;
        $branch=Branch::where('business_id',$businessId)->firstOrFail();
        $customer=User::where('email','customer@beautybook.local')->firstOrFail();
        $booking=Booking::create(['business_id'=>$businessId,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->customerProfile->id,'created_by_user_id'=>$owner->id,'booking_code'=>'ACTUAL-CASH-LOCAL-DAY','appointment_start_at'=>'2026-10-08T03:00:00Z','appointment_end_at'=>'2026-10-08T04:00:00Z','status'=>'CONFIRMED','source'=>'PHONE','currency'=>'VND','total_amount'=>'123.45']);
        $this->postJson('/api/v1/payments/collect',['bookingId'=>(string)$booking->id,'method'=>'CASH','amount'=>123.45,'idempotencyKey'=>'cash-local-day'],$this->headers())->assertSuccessful();
        $this->getJson('/api/v1/reports/financial-summary?from=2026-10-07&to=2026-10-07')->assertOk()->assertJsonPath('grossCollected',123.45);
        $this->getJson('/api/v1/reports/financial-summary?from=2026-10-08&to=2026-10-08')->assertOk()->assertJsonPath('grossCollected',0);
    }

    public function test_ownership_versions_are_encrypted_masked_and_scope_bound(): void
    {
        $owner=$this->login('owner@beautybook.local');$businessId=$owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail()->business_id;
        $response=$this->postJson('/api/v1/ownership-transfers/business/'.$businessId.'/payout-account',['bankName'=>'Ngân hàng thật','accountHolder'=>'Tên tài khoản','accountNumber'=>'012345678901'],$this->headers())->assertCreated()->assertJsonPath('maskedAccountNumber','****8901');
        $this->assertArrayNotHasKey('accountNumber',$response->json());
        $stored=DB::table('business_identity_versions')->find($response->json('id'));
        $this->assertStringNotContainsString('012345678901',$stored->payload_ciphertext);
        $this->login('owner2@beautybook.local');
        $this->getJson('/api/v1/ownership-transfers/business/'.$businessId.'/versions')->assertForbidden();
        $this->getJson('/api/v1/operational-impacts')->assertOk();
        $this->login('receptionist@beautybook.local');
        $this->getJson('/api/v1/operational-impacts')->assertForbidden();
    }
}
