<?php
namespace Tests\Feature;
use App\Models\{User,Branch,BranchServiceOffering,Booking,StaffProfile};
use App\Services\Booking\BookingRecipe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class BookingRecipeFlowTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp():void{parent::setUp();$this->seed();config(['beautybook.legacy_ui'=>false]);}
 private function actor(string $email):void{$user=User::where('email',$email)->firstOrFail();$grant=$user->roleAssignments()->firstOrFail();$token='recipe-booking-test';$this->actingAs($user)->withHeader('Authorization','Bearer '.$token)->withSession(['workspace_assignment_id'=>$grant->id,'beautybook_access_hash'=>hash('sha256',$token),'beautybook_access_expires'=>time()+3600]);}
 private function recipe(int $quantity=2):array{$branch=Branch::where('slug','tan-tao')->firstOrFail();$service=BranchServiceOffering::where('branch_id',$branch->id)->orderBy('id')->firstOrFail();return ['branchId'=>(string)$branch->id,'selections'=>[['serviceId'=>(string)$service->id,'quantity'=>$quantity]]];}
 public function test_quantity_preview_slots_creation_replay_and_contact_are_the_same_recipe():void{
  $this->actor('customer@beautybook.local');$recipe=$this->recipe();
  $quote=$this->postJson('/api/v1/bookings/preview-price',$recipe)->assertOk()->assertJsonCount(2,'items')->json();
  $date=CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8);while($date->dayOfWeek===0)$date=$date->addDay();
  $slots=$this->postJson('/api/v1/bookings/available-slots',$recipe+['date'=>$date->format('Y-m-d')])->assertOk()->json('slots');$this->assertNotEmpty($slots);
  $this->assertCount(2,$slots[0]['assignments']);
  $payload=$recipe+['startAt'=>$slots[0]['start'],'contact'=>['name'=>'Khách thử luồng','phone'=>'0901234567','email'=>'contact@example.test'],'contactConsent'=>true];
  $created=$this->withHeader('Idempotency-Key','recipe-quantity-create-1')->postJson('/api/v1/bookings',$payload)->assertCreated()->assertJsonCount(2,'bookingServices')->assertJsonPath('flow','AGGREGATE')->json();
  $this->assertSame($quote['finalAmount'],$created['totalAmount']);
  $this->postJson('/api/v1/bookings',$payload)->assertCreated()->assertJsonPath('id',$created['id'])->assertJsonPath('replayed',true);
  $this->assertDatabaseCount('bookings',1);$this->assertDatabaseHas('booking_contacts',['booking_id'=>$created['id'],'email'=>'contact@example.test']);
 }
 public function test_completed_booking_does_not_require_per_service_start_or_actual_times():void{
  $branch=Branch::where('slug','tan-tao')->firstOrFail();$user=User::where('email','customer@beautybook.local')->firstOrFail();
  $booking=Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$user->customerProfile->id,'created_by_user_id'=>$user->id,'booking_code'=>'FLOW-AGGREGATE','appointment_start_at'=>now()->subMinutes(5),'appointment_end_at'=>now()->addHour(),'status'=>'CHECKED_IN','source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>100000]);
  $this->actor('receptionist@beautybook.local');$this->patchJson('/api/v1/bookings/'.$booking->id.'/status',['status'=>'COMPLETED','expectedVersion'=>1])->assertForbidden();
  $this->actor('owner@beautybook.local');$this->patchJson('/api/v1/bookings/'.$booking->id.'/status',['status'=>'IN_PROGRESS','expectedVersion'=>1])->assertConflict();
  $this->patchJson('/api/v1/bookings/'.$booking->id.'/status',['status'=>'COMPLETED','expectedVersion'=>9])->assertConflict();
  $this->patchJson('/api/v1/bookings/'.$booking->id.'/status',['status'=>'COMPLETED','expectedVersion'=>1])->assertOk()->assertJsonPath('status','COMPLETED');
 }
 public function test_invalid_quantities_mixed_service_combo_and_missing_consent_are_rejected():void{
  $this->actor('customer@beautybook.local');$recipe=$this->recipe(0);$this->postJson('/api/v1/bookings/preview-price',$recipe)->assertUnprocessable();
  $recipe=$this->recipe(101);$this->postJson('/api/v1/bookings/preview-price',$recipe)->assertUnprocessable();
  $recipe=$this->recipe();$recipe['selections'][0]['comboId']='1';$this->postJson('/api/v1/bookings/preview-price',$recipe)->assertUnprocessable();
  $this->postJson('/api/v1/bookings',$this->recipe()+['startAt'=>now()->addDay()->toIso8601String(),'contact'=>['name'=>'Test','phone'=>'0901234567']])->assertUnprocessable();
 }
 public function test_price_allocation_is_exact_ordered_and_safe_for_large_values():void{
  $this->assertSame([34,33,33],BookingRecipe::allocate(100,[1,1,1]));$this->assertSame([1,1,0],BookingRecipe::allocate(2,[0,0,0]));
  $values=BookingRecipe::allocate(99999999999999,[99999999999999,99999999999998]);$this->assertSame(99999999999999,array_sum($values));
 }
 public function test_combo_quantity_price_limit_and_persisted_allocation():void{
  $this->actor('customer@beautybook.local');$recipe=$this->recipe(1);$branch=Branch::findOrFail($recipe['branchId']);$service=BranchServiceOffering::findOrFail($recipe['selections'][0]['serviceId']);
  $combo=\App\Models\Combo::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'name'=>'Combo kiểm thử','combo_price'=>'100001.00','pricing_mode'=>'FIXED','staff_assignment_mode'=>'SINGLE_PROVIDER','status'=>'ACTIVE','max_usage'=>2,'used_count'=>0,'version'=>1]);
  $other=BranchServiceOffering::where('branch_id',$branch->id)->where('id','!=',$service->id)->firstOrFail();
  $staff=StaffProfile::where('branch_id',$branch->id)->where('status','ACTIVE')->firstOrFail();$staff->offerings()->syncWithoutDetaching([$other->id=>['business_id'=>$branch->business_id,'branch_id'=>$branch->id]]);
  foreach([$service,$other] as $i=>$member)$combo->items()->create(['branch_service_offering_id'=>$member->id,'quantity'=>1,'sort_order'=>$i,'transition_minutes'=>0,'price_snapshot'=>$member->price,'duration_snapshot'=>$member->duration_minutes]);
  $recipe['selections']=[['comboId'=>(string)$combo->id,'quantity'=>2]];
  $quote=$this->postJson('/api/v1/bookings/preview-price',$recipe)->assertOk()->assertJsonCount(4,'items')->assertJsonPath('finalAmount','200002.00')->json();
  $date=CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8);while($date->dayOfWeek===0)$date=$date->addDay();
  $slots=$this->postJson('/api/v1/bookings/available-slots',$recipe+['date'=>$date->toDateString()])->assertOk()->json('slots');$this->assertNotEmpty($slots);
  $created=$this->withHeader('Idempotency-Key','combo-two-recipe')->postJson('/api/v1/bookings',$recipe+['startAt'=>$slots[0]['start'],'contact'=>['name'=>'Khách combo','phone'=>'0901234567'],'contactConsent'=>true])->assertCreated()->json();
  $this->assertSame('200002.00',bcadd('0',(string)Booking::find($created['id'])->services()->sum('price_at_booking'),2));$this->assertSame(2,$combo->fresh()->used_count);
  $this->postJson('/api/v1/bookings/preview-price',$recipe)->assertUnprocessable();
  $this->patchJson('/api/v1/bookings/'.$created['id'].'/status',['status'=>'CANCELLED','expectedVersion'=>$created['version']])->assertOk();
  $this->assertSame(0,$combo->fresh()->used_count);$this->postJson('/api/v1/bookings/preview-price',$recipe)->assertOk()->assertJsonPath('finalAmount','200002.00');
 }
 public function test_recurring_recipe_is_atomic_replayable_and_cancellable():void{
  $this->actor('customer@beautybook.local');$date=CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8);while($date->dayOfWeek===0)$date=$date->addDay();
  $recipe=$this->recipe(2)+['frequency'=>'WEEKLY','startDate'=>$date->toDateString(),'preferredTime'=>'10:00','occurrenceCount'=>2,'staffMode'=>'ANY_AVAILABLE'];
  $this->postJson('/api/v1/recurring/preview',$recipe)->assertOk()->assertJsonCount(2,'occurrences')->assertJsonPath('availableCount',2)->assertJsonCount(2,'occurrences.0.quote.items');
  $payload=$recipe+['contact'=>['name'=>'Khách định kỳ','phone'=>'+12025550123','email'=>'recurring@example.test'],'contactConsent'=>true];
  $created=$this->withHeader('Idempotency-Key','recurring-recipe-test')->postJson('/api/v1/recurring',$payload)->assertOk()->assertJsonCount(2,'bookings')->json();
  $this->postJson('/api/v1/recurring',$payload)->assertOk()->assertJsonPath('id',$created['id'])->assertJsonPath('replayed',true);$this->assertDatabaseCount('bookings',2);
  $this->deleteJson('/api/v1/recurring/'.$created['id'])->assertOk()->assertJsonPath('status','CANCELLED');$this->assertSame(0,Booking::where('status','!=','CANCELLED')->count());
 }
 public function test_monthly_calendar_clamps_from_original_day_and_old_item_commands_are_hidden():void{
  $branch=Branch::where('slug','tan-tao')->firstOrFail();$dates=app(\App\Services\Scheduling\RecurringService::class)->dates($branch,['selections'=>[['serviceId'=>1,'quantity'=>1]],'frequency'=>'MONTHLY','start_date'=>'2027-01-31','preferred_time'=>'09:00','occurrence_count'=>3]);
  $this->assertSame(['2027-01-31','2027-02-28','2027-03-31'],array_map(fn($d)=>$d->toDateString(),$dates));
  $this->actor('owner@beautybook.local');$this->patchJson('/api/v1/bookings/1/move',[])->assertNotFound();
 }
 public function test_repeated_service_can_have_independent_variants_and_staff():void{
  $this->actor('customer@beautybook.local');$recipe=$this->recipe(1);$branch=Branch::find($recipe['branchId']);$serviceId=$recipe['selections'][0]['serviceId'];
  $first=StaffProfile::where('branch_id',$branch->id)->where('status','ACTIVE')->firstOrFail();
  $second=StaffProfile::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'full_name'=>'Chuyên viên thứ hai','status'=>'ACTIVE','is_bookable'=>true,'public_visible'=>true]);
  $second->offerings()->attach($serviceId,['business_id'=>$branch->business_id,'branch_id'=>$branch->id]);
  $variants=[];foreach([100001,200003] as $i=>$price)$variants[]=\App\Models\ServiceVariant::create(['branch_service_offering_id'=>$serviceId,'code'=>'RECIPE-'.$i,'name'=>'Lựa chọn '.$i,'price_type'=>'FIXED','price'=>$price,'duration_minutes'=>30+$i*15,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'status'=>'ACTIVE','version'=>1]);
  $recipe['selections']=array_map(fn($v,$s)=>['serviceId'=>$serviceId,'quantity'=>1,'variantId'=>(string)$v->id,'staffId'=>(string)$s->id],$variants,[$first,$second]);
  $this->postJson('/api/v1/bookings/preview-price',$recipe)->assertOk()->assertJsonPath('finalAmount','300004.00')->assertJsonPath('durationMinutes',75);
  $date=CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8);while($date->dayOfWeek===0)$date=$date->addDay();
  $slots=$this->postJson('/api/v1/bookings/available-slots',$recipe+['date'=>$date->toDateString()])->assertOk()->json('slots');$this->assertNotEmpty($slots);$this->assertSame([(string)$first->id,(string)$second->id],array_column($slots[0]['assignments'],'staffId'));
  $payload=$recipe+['startAt'=>$slots[0]['start'],'contact'=>['name'=>'Hai lựa chọn riêng','phone'=>'0901234567'],'contactConsent'=>true];
  $created=$this->withHeader('Idempotency-Key','separate-service-choices')->postJson('/api/v1/bookings',$payload)->assertCreated()->json();
  $items=Booking::find($created['id'])->services()->orderBy('sort_order')->get();$this->assertSame(array_map(fn($v)=>$v->id,$variants),$items->pluck('service_variant_id')->all());$this->assertSame([$first->id,$second->id],$items->pluck('staff_profile_id')->all());
  $payload['selections'][0]['quantity']=2;$this->postJson('/api/v1/bookings',$payload)->assertConflict();$this->assertDatabaseCount('bookings',1);
 }
 public function test_pending_expiry_rejection_reason_and_version_are_enforced():void{
  $branch=Branch::where('slug','tan-tao')->firstOrFail();$customer=User::where('email','customer@beautybook.local')->firstOrFail();
  $booking=Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$customer->customerProfile->id,'created_by_user_id'=>$customer->id,'booking_code'=>'PENDING-RULES','appointment_start_at'=>now()->addDay(),'appointment_end_at'=>now()->addDay()->addHour(),'pending_expires_at'=>now()->subMinute(),'status'=>'PENDING','source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>100000]);
  $this->actor('owner@beautybook.local');$url='/api/v1/bookings/'.$booking->id.'/status';
  $this->patchJson($url,['status'=>'CONFIRMED'])->assertUnprocessable();$this->patchJson($url,['status'=>'CONFIRMED','expectedVersion'=>1])->assertConflict();
  $booking->update(['pending_expires_at'=>now()->addMinutes(10)]);$this->patchJson($url,['status'=>'REJECTED','expectedVersion'=>1])->assertConflict();
  $this->patchJson($url,['status'=>'REJECTED','expectedVersion'=>1,'reason'=>'Cơ sở không thể tiếp nhận'])->assertOk()->assertJsonPath('status','REJECTED');
  $this->patchJson($url,['status'=>'CONFIRMED','expectedVersion'=>1])->assertConflict();
 }
 public function test_fixed_offers_apply_per_purchased_unit_and_do_not_discount_unselected_services():void{
  $this->actor('customer@beautybook.local');$recipe=$this->recipe(2);$branch=Branch::find($recipe['branchId']);$service=BranchServiceOffering::find($recipe['selections'][0]['serviceId']);$other=BranchServiceOffering::where('branch_id',$branch->id)->where('id','!=',$service->id)->firstOrFail();
  $recipe['selections'][]=['serviceId'=>(string)$other->id,'quantity'=>1];
  \App\Models\Promotion::create(['business_id'=>$branch->business_id,'name'=>'Giảm từng lượt','code'=>'PERUNIT','target_mode'=>'SELECTED','service_ids'=>[$service->id],'branch_ids'=>[$branch->id],'auto_apply'=>false,'status'=>'ACTIVE','discount_type'=>'FIXED_AMOUNT','discount_value'=>10000,'start_date'=>now()->subDay(),'end_date'=>now()->addDay(),'max_usage_per_customer'=>10]);
  $quote=$this->postJson('/api/v1/bookings/preview-price',$recipe+['promotionCode'=>'PERUNIT'])->assertOk()->assertJsonPath('promotionCodeValid',true)->json();
  $expected=bcsub(bcadd(bcmul($service->price,'2',2),$other->price,2),'20000',2);$this->assertSame($expected,$quote['finalAmount']);
  $this->assertSame(bcsub($service->price,'10000',2),$quote['items'][0]['priceFinalSnapshot']);$this->assertSame($other->price,$quote['items'][2]['priceFinalSnapshot']);
 }
}
