<?php
namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Models\{User,Business,Branch,BranchServiceOffering,CustomerProfile,Booking,Voucher,Combo};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeautybookVoucherApiTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->seed();}
    private function actor(string $email): User {
        $user=User::where('email',$email)->firstOrFail();$assignment=$user->roleAssignments()->firstOrFail();$token='voucher-contract-token';
        $this->actingAs($user)->withHeader('Authorization','Bearer '.$token)->withSession(['workspace_assignment_id'=>$assignment->id,'beautybook_access_hash'=>hash('sha256',$token),'beautybook_access_expires'=>time()+3600]);return $user;
    }
    private function voucher(array $overrides=[]): Voucher {
        $branch=Branch::where('slug','tan-tao')->firstOrFail();
        return Voucher::create($overrides+['business_id'=>$branch->business_id,'created_by_platform'=>false,'code'=>'SRC-'.Str::upper(Str::random(8)),'name'=>'Source voucher','discount_type'=>'PERCENTAGE','discount_value'=>10,'total_quantity'=>20,'min_order_value'=>0,'start_date'=>now()->subDay(),'end_date'=>now()->addDays(10),'status'=>'ACTIVE','scope'=>'TENANT','audience'=>'ALL','auto_issue'=>false,'max_usage_per_customer'=>1]);
    }
    private function customer(string $email,string $name='Unique Customer'): CustomerProfile {
        $user=User::factory()->create(['email'=>$email,'name'=>$name,'account_kind'=>AccountKind::Customer]);
        return CustomerProfile::create(['user_id'=>$user->id]);
    }
    private function booking(CustomerProfile $customer,?Branch $branch=null,string $status='COMPLETED'): Booking {
        $branch??=Branch::where('slug','tan-tao')->firstOrFail();
        return Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->id,'created_by_user_id'=>$customer->user_id,'booking_code'=>'VTEST-'.Str::random(12),'appointment_start_at'=>now()->subDays(2),'appointment_end_at'=>now()->subDays(2)->addHour(),'status'=>$status,'source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>100000]);
    }
    public function test_scope_editor_roundtrip_and_omitted_patch_fields_preserve_economics(): void {
        $this->actor('owner@beautybook.local');$branch=Branch::where('slug','tan-tao')->firstOrFail();$offering=BranchServiceOffering::where('branch_id',$branch->id)->firstOrFail();
        $combo=Combo::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'name'=>'Scope combo','combo_price'=>100000,'status'=>'ACTIVE']);
        $payload=['businessId'=>(string)$branch->business_id,'code'=>' src-code ','name'=>'Scope source','discountType'=>'PERCENTAGE','discountValue'=>12.5,'totalQuantity'=>20,'startDate'=>now()->subDay()->toDateTimeString(),'endDate'=>now()->addMonth()->toDateTimeString(),'branchIds'=>[(string)$branch->id],'serviceIds'=>[(string)$offering->id],'comboIds'=>[(string)$combo->id]];
        $id=$this->postJson('/api/v1/vouchers',$payload)->assertCreated()->assertJsonPath('code','SRC-CODE')->assertJsonPath('branches.0.id',(string)$branch->id)->assertJsonPath('services.0.name',$offering->service->name)->assertJsonPath('combos.0.name','Scope combo')->json('id');
        $this->patchJson('/api/v1/vouchers/'.$id,['name'=>'Edited','discountValue'=>99,'code'=>'MUTATED'])->assertOk()->assertJsonPath('discountValue',12.5)->assertJsonPath('code','SRC-CODE')->assertJsonPath('branches.0.id',(string)$branch->id)->assertJsonPath('version',2);
        $row=collect($this->getJson('/api/v1/vouchers')->assertOk()->json())->firstWhere('id',$id);$this->assertSame((string)$combo->id,$row['combos'][0]['id']);$this->assertSame(0,$row['claimedCount']);
        $this->patchJson('/api/v1/vouchers/'.$id,['branchIds'=>[]])->assertOk()->assertJsonCount(0,'branches')->assertJsonCount(1,'services')->assertJsonCount(1,'combos');
        $this->deleteJson('/api/v1/vouchers/'.$id)->assertOk()->assertJsonPath('status','REVOKED');$this->assertSoftDeleted('vouchers',['id'=>$id]);
    }
    public function test_manager_and_platform_ownership_guards_do_not_allow_cross_tenant_mutations(): void {
        $voucher=$this->voucher();$this->actor('owner2@beautybook.local');$this->patchJson('/api/v1/vouchers/'.$voucher->id,['name'=>'Forbidden'])->assertForbidden();
        $this->actor('admin@beautybook.local');$rows=$this->getJson('/api/v1/vouchers')->assertOk()->json();$this->assertNotNull(collect($rows)->firstWhere('id',(string)$voucher->id));
        $this->deleteJson('/api/v1/vouchers/'.$voucher->id)->assertForbidden();$this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>'unknown'])->assertForbidden();
        $platform=$this->voucher(['business_id'=>null,'created_by_platform'=>true,'scope'=>'PLATFORM']);$this->patchJson('/api/v1/vouchers/'.$platform->id,['name'=>'Platform edited'])->assertOk();
        $this->actor('owner@beautybook.local');$this->getJson('/api/v1/vouchers/mine')->assertForbidden();$this->deleteJson('/api/v1/vouchers/'.$platform->id)->assertForbidden();
        $this->actor('customer@beautybook.local');$this->getJson('/api/v1/vouchers')->assertForbidden();
        $this->actor('receptionist@beautybook.local');$this->getJson('/api/v1/vouchers')->assertForbidden();
        $this->assertSame('Source voucher',$voucher->fresh()->name);
    }
    public function test_grants_resolve_customer_references_enforce_relationship_and_preserve_selected_history(): void {
        $owner=$this->actor('owner@beautybook.local');$customer=$this->customer('reference@example.test','Unique Reference Name');$this->booking($customer);
        $uuid=(string)Str::uuid();$customer->forceFill(['beautybook_reference'=>$uuid])->save();DB::table('beautybook_user_profiles')->insert(['user_id'=>$customer->user_id,'phone'=>'0912345678']);
        $created=now()->subDays(30)->toDateTimeString();DB::table('customer_business_segments')->insert(['business_id'=>Branch::where('slug','tan-tao')->value('business_id'),'customer_profile_id'=>$customer->id,'segment'=>'SELECTED','created_at'=>$created,'updated_at'=>$created]);
        foreach(['REFERENCE@example.test','0912345678','unique reference name',$uuid,(string)$customer->id,'customer-'.$customer->id] as $reference){
            $voucher=$this->voucher(['audience'=>'SELECTED']);$this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>$reference])->assertOk()->assertJsonPath('customerId',(string)$customer->id);
            $this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>$reference])->assertUnprocessable();
        }
        $segment=DB::table('customer_business_segments')->where('customer_profile_id',$customer->id)->first();$this->assertSame($created,$segment->created_at);$this->assertSame($owner->id,$segment->assigned_by_user_id);
        $unrelated=$this->customer('outside@example.test');$this->booking($unrelated,Branch::where('business_id','<>',$voucher->business_id)->firstOrFail());
        $known=$this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>'outside@example.test'])->assertUnprocessable()->json('message');
        $unknown=$this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>'absent@example.test'])->assertUnprocessable()->json('message');$this->assertSame($known,$unknown);
        foreach(['duplicate-a@example.test','duplicate-b@example.test'] as $email)$this->booking($this->customer($email,'Duplicate Customer'));
        $this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>'Duplicate Customer'])->assertUnprocessable()->assertJsonPath('message','Có nhiều khách hàng trùng tên; hãy dùng email hoặc số điện thoại');
        $this->assertSame(6,DB::table('customer_vouchers')->where('customer_profile_id',$customer->id)->count());
    }
    public function test_mine_uses_parent_expiry_start_and_status_precedence(): void {
        $user=$this->actor('customer@beautybook.local');$customer=$user->customerProfile;
        $cases=[['start_date'=>now()->addDay(),'end_date'=>now()->addDays(3)],['end_date'=>now()->subHour()],['status'=>'REVOKED'],['status'=>'EXPIRED'],['status'=>'REVOKED']];$vouchers=[];
        foreach($cases as $index=>$override){$voucher=$this->voucher($override);$vouchers[]=$voucher;DB::table('customer_vouchers')->insert(['voucher_id'=>$voucher->id,'customer_profile_id'=>$customer->id,'status'=>$index===4?'USED':'ACTIVE','expires_at'=>now()->addMonth(),'created_at'=>now(),'updated_at'=>now()]);}
        $rows=collect($this->getJson('/api/v1/vouchers/mine')->assertOk()->json());
        foreach(['ACTIVE','EXPIRED','REVOKED','EXPIRED','USED'] as $index=>$status){$row=$rows->first(fn($item)=>$item['voucher']['id']===(string)$vouchers[$index]->id);$this->assertSame($status,$row['status']);$this->assertFalse($row['isUsable']);$this->assertSame($vouchers[$index]->end_date->copy()->utc()->toIso8601String(),\Carbon\Carbon::parse($row['expiresAt'])->utc()->toIso8601String());$this->assertArrayHasKey('acquiredAt',$row);$this->assertArrayHasKey('usedBookingId',$row);}
    }
    public function test_auto_issue_uses_completed_bookings_birthday_and_segments_without_resetting_entitlements(): void {
        $user=$this->actor('customer@beautybook.local');$customer=$user->customerProfile;$this->booking($customer);
        $businessId=Branch::where('slug','tan-tao')->value('business_id');DB::table('customer_business_segments')->insert(['business_id'=>$businessId,'customer_profile_id'=>$customer->id,'segment'=>'VIP','created_at'=>now(),'updated_at'=>now()]);
        DB::table('beautybook_user_profiles')->updateOrInsert(['user_id'=>$user->id],['date_of_birth'=>now()->subYears(25)->toDateString()]);
        $vouchers=[];foreach(['ALL','NEW_CUSTOMER','RETURNING_CUSTOMER','BIRTHDAY','VIP','SELECTED'] as $audience)$vouchers[$audience]=$this->voucher(['auto_issue'=>true,'audience'=>$audience]);
        $platform=$this->voucher(['auto_issue'=>true,'business_id'=>null,'created_by_platform'=>true,'scope'=>'PLATFORM']);
        $this->getJson('/api/v1/vouchers/mine')->assertOk();
        foreach(['ALL','RETURNING_CUSTOMER','BIRTHDAY','VIP'] as $audience)$this->assertDatabaseHas('customer_vouchers',['voucher_id'=>$vouchers[$audience]->id,'customer_profile_id'=>$customer->id]);
        foreach(['NEW_CUSTOMER','SELECTED'] as $audience)$this->assertDatabaseMissing('customer_vouchers',['voucher_id'=>$vouchers[$audience]->id,'customer_profile_id'=>$customer->id]);
        $this->assertDatabaseHas('customer_vouchers',['voucher_id'=>$platform->id,'customer_profile_id'=>$customer->id]);
        DB::table('customer_vouchers')->where('voucher_id',$vouchers['ALL']->id)->update(['status'=>'REVOKED']);$before=DB::table('customer_vouchers')->count();$this->getJson('/api/v1/vouchers/mine')->assertOk();$this->assertSame($before,DB::table('customer_vouchers')->count());$this->assertDatabaseHas('customer_vouchers',['voucher_id'=>$vouchers['ALL']->id,'status'=>'REVOKED']);
    }
    public function test_quota_reduction_and_exhausted_grant_reject_without_changing_history(): void {
        $this->actor('owner@beautybook.local');$customer=User::where('email','customer@beautybook.local')->firstOrFail()->customerProfile;$voucher=$this->voucher(['total_quantity'=>3,'used_quantity'=>2,'max_usage_per_customer'=>3]);
        for($i=0;$i<2;$i++){$booking=$this->booking($customer);DB::table('voucher_redemptions')->insert(['voucher_id'=>$voucher->id,'booking_id'=>$booking->id,'branch_id'=>$booking->branch_id,'customer_profile_id'=>$customer->id,'amount'=>1000,'rule_snapshot'=>'{}','status'=>'RESERVED','created_at'=>now(),'updated_at'=>now()]);}
        $this->patchJson('/api/v1/vouchers/'.$voucher->id,['totalQuantity'=>1])->assertConflict();$this->patchJson('/api/v1/vouchers/'.$voucher->id,['maxUsagePerCustomer'=>1])->assertConflict();$this->assertSame(1,$voucher->fresh()->version);
        $voucher->update(['used_quantity'=>3]);$this->postJson('/api/v1/vouchers/'.$voucher->id.'/grant',['customerId'=>(string)$customer->id])->assertConflict();$this->assertDatabaseMissing('customer_vouchers',['voucher_id'=>$voucher->id]);
    }
}
