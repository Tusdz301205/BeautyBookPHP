<?php

namespace Database\Seeders;

use App\Enums\{AccountKind,RoleName};
use App\Models\{User,Business,BusinessOwnerProfile,Branch,BusinessService,BranchServiceOffering,ServiceCategory,CanonicalService,StaffProfile};
use App\Services\Identity\RoleAssignmentService;
use App\Services\Staff\StaffManagementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Additive reference fixtures. Never resets, truncates, updates, or removes existing demo/history. */
class BeautybookSourceReferenceSeeder extends Seeder
{
    public function run(RoleAssignmentService $roles,StaffManagementService $staffService): void
    {
        $reference=json_decode(file_get_contents(database_path('data/beautybook-source-reference.json')),true,512,JSON_THROW_ON_ERROR);
        DB::transaction(function ()use($reference,$roles,$staffService){
            foreach($reference['categories'] as $category)ServiceCategory::firstOrCreate(['slug'=>$category['slug']],['name'=>$category['name']]);
            foreach($reference['canonicals'] as $canonical)CanonicalService::firstOrCreate(['code'=>$canonical['code']],$canonical+['status'=>'ACTIVE']);
            $businesses=[];$branches=[];
            foreach($reference['businesses'] as $entry){
                $mapping=$this->mapping('BUSINESS',$entry['sourceUuid']);
                if($mapping){$business=Business::findOrFail($mapping->local_id);}
                else{
                    $definition=$entry['owner'];
                    $owner=User::firstOrCreate(['email'=>$definition['email']],['name'=>$definition['fullName'],'password'=>'Password123!','account_kind'=>AccountKind::Operational]);
                    if($owner->wasRecentlyCreated)$owner->forceFill(['email_verified_at'=>now()])->save();
                    if($owner->account_kind!==AccountKind::Operational)throw new \DomainException('Source reference owner email belongs to a customer account; no identity was changed.');
                    $profile=BusinessOwnerProfile::firstOrCreate(['user_id'=>$owner->id],['company_name'=>$definition['company'],'tax_code'=>$definition['tax']]);
                    $business=Business::firstOrCreate(['slug'=>$entry['slug']],['owner_profile_id'=>$profile->id,'name'=>$entry['name'],'description'=>$entry['description'],'contact_email'=>$definition['email'],'status'=>'ACTIVE']);
                    if($business->owner_profile_id!==$profile->id)throw new \DomainException('Source reference business slug collision; no existing ownership was changed.');
                    $roles->grant($owner,RoleName::BusinessOwner,$business,grantedBy:$owner);
                    $this->map('BUSINESS',$entry['sourceUuid'],$business->id,$entry,$reference['provenance']);
                    foreach($reference['categories'] as $sourceCategory){
                        $category=ServiceCategory::where('slug',$sourceCategory['slug'])->firstOrFail();
                        DB::table('beautybook_business_categories')->insertOrIgnore(['business_id'=>$business->id,'service_category_id'=>$category->id,'source_slug'=>$sourceCategory['slug'],'created_at'=>now(),'updated_at'=>now()]);
                    }
                    DB::table('beautybook_business_settings')->insertOrIgnore(['business_id'=>$business->id,'metadata'=>json_encode(['referenceDemo'=>true,'legalRepresentative'=>$definition['fullName'],'onboardingStep'=>4]),'cancellation_policy'=>json_encode(['freeCancelHours'=>4,'rescheduleAllowedHours'=>2,'notes'=>'Chính sách hủy/đổi lịch; hệ thống không thu phí hủy hoặc no-show.']),'created_at'=>now(),'updated_at'=>now()]);
                }
                $businesses[$entry['sourceUuid']]=$business;
            }
            foreach($reference['branches'] as $entry){
                $business=$businesses[$entry['businessUuid']];$mapping=$this->mapping('BRANCH',$entry['sourceUuid']);
                if($mapping)$branch=Branch::findOrFail($mapping->local_id);
                else{
                    $branch=Branch::create(['business_id'=>$business->id,'name'=>$entry['name'],'public_name'=>$entry['name'],'slug'=>'source-'.substr($entry['sourceUuid'],0,12),'address_line'=>$entry['addressLine'],'email'=>$business->contact_email,'timezone'=>'Asia/Ho_Chi_Minh','status'=>'ACTIVE','review_status'=>'APPROVED','operational_status'=>'ACTIVE']);
                    foreach(range(0,6) as $day)$branch->workingHours()->create(['day_of_week'=>$day,'open_time'=>$entry['workingHours']['openTime'],'close_time'=>$entry['workingHours']['closeTime'],'is_closed'=>false]);
                    $districtId=\App\Services\Beautybook\SourceDistrictCatalog::districtId($entry['district']['provinceCode'],$entry['district']['name']);
                    DB::table('beautybook_branch_settings')->insert(['branch_id'=>$branch->id,'onboarding'=>json_encode(['currentStep'=>13,'completedSteps'=>range(1,13),'draftData'=>['wizardVersion'=>2]]),'metadata'=>json_encode(['referenceDemo'=>true,'sameLegalEntity'=>true,'serviceMode'=>'AT_LOCATION','districtId'=>$districtId,'district'=>$entry['district'],'bookingConfirmationMode'=>'MANUAL_CONFIRMATION','staffAssignmentMode'=>'AUTO_ASSIGN_IF_ANY_STAFF','pendingHoldMinutes'=>30]),'booking_policy'=>json_encode(['leadTimeMinutes'=>120,'bookingHorizonDays'=>30,'cancellationHours'=>4,'rescheduleHours'=>2,'allowWalkIn'=>true,'allowCounterBooking'=>true,'defaultBufferMinutes'=>0,'overbookingEnabled'=>false,'maxOverbookedSlots'=>0,'confirmedAt'=>now()->toIso8601String()]),'created_at'=>now(),'updated_at'=>now()]);
                    $this->map('BRANCH',$entry['sourceUuid'],$branch->id,$entry,$reference['provenance']);
                }
                $branches[$entry['sourceUuid']]=$branch;
            }
            foreach($reference['offerings'] as $entry){
                if($this->mapping('SERVICE',$entry['sourceUuid']))continue;
                $business=$businesses[$entry['businessUuid']];$branch=$branches[$entry['branchUuid']];
                $category=ServiceCategory::where('slug',$entry['categorySlug'])->firstOrFail();
                $canonical=$entry['canonicalCode'] ? CanonicalService::where('code',$entry['canonicalCode'])->firstOrFail():null;
                $service=BusinessService::firstOrCreate(['business_id'=>$business->id,'slug'=>Str::slug($entry['name'])],['service_category_id'=>$category->id,'canonical_service_id'=>$canonical?->id,'mapping_status'=>$canonical?'MAPPED':'UNMAPPED','name'=>$entry['name'],'description'=>$entry['name'].' trong danh mục chuẩn của '.$business->name.'.','base_price'=>$entry['price'],'base_duration_minutes'=>$entry['durationMinutes'],'status'=>'ACTIVE','is_active'=>true]);
                $offering=BranchServiceOffering::firstOrCreate(['business_service_id'=>$service->id,'branch_id'=>$branch->id],['business_id'=>$business->id,'price'=>$entry['price'],'duration_minutes'=>$entry['durationMinutes'],'bookable'=>true,'is_active'=>true]);
                $this->map('SERVICE',$entry['sourceUuid'],$offering->id,$entry,$reference['provenance']);
            }
            foreach($branches as $uuid=>$branch){
                // Name is the fixed demo identity in original seed.ts. Random source DB identities are unavailable.
                $employeeCode='SOURCE-'.substr($uuid,0,8);
                $staff=StaffProfile::where('business_id',$branch->business_id)->where('employee_code',$employeeCode)->first();
                if(!$staff){
                    $staff=$staffService->create($branch->business,['branch_id'=>$branch->id,'full_name'=>'Demo Nhân viên','position'=>'STAFF','employee_code'=>$employeeCode,'status'=>'ACTIVE','is_bookable'=>true,'public_visible'=>true]);
                    $ids=BranchServiceOffering::where('branch_id',$branch->id)->where('is_active',true)->where('bookable',true)->pluck('id')->all();
                    $staff->offerings()->syncWithoutDetaching(array_fill_keys($ids,['business_id'=>$branch->business_id,'branch_id'=>$branch->id]));
                }
                DB::table('beautybook_staff_settings')->insertOrIgnore(['staff_profile_id'=>$staff->id,'metadata'=>json_encode(['referenceDemo'=>true,'identityBasis'=>'FIXED_SOURCE_DEMO_STAFF_TEMPLATE','sourceSeedSha256'=>$reference['provenance']['seedSha256']]),'created_at'=>now(),'updated_at'=>now()]);
            }
        },3);
        $this->command?->info('Added source reference fixtures: '.count($reference['businesses']).' businesses, '.count($reference['branches']).' branches, '.count($reference['offerings']).' offerings. Existing demo and history retained; prices are source template base prices.');
    }
    private function mapping(string $type,string $uuid): ?object {return DB::table('beautybook_source_entities')->where('entity_type',$type)->where('source_uuid',$uuid)->first();}
    private function map(string $type,string $uuid,int $id,array $entry,array $provenance): void
    {
        DB::table('beautybook_source_entities')->insert(['entity_type'=>$type,'source_uuid'=>$uuid,'local_id'=>$id,'metadata'=>json_encode(['media'=>$entry['media'],'reference'=>$entry,'provenance'=>$provenance],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
    }
}
