<?php

namespace Tests\Feature;

use App\Models\{User,Business,Branch,BranchServiceOffering,BusinessService,StaffProfile,MediaFile,StaffInvitation};
use App\Services\Beautybook\ManagementPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage,Mail,DB};
use Tests\TestCase;

class BeautybookManagementApiTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->seed();}
    private function actor(string $email): User
    {
        $user=User::where('email',$email)->firstOrFail();$assignment=$user->roleAssignments()->firstOrFail();$token='management-api-token';
        $this->actingAs($user)->withHeader('Authorization','Bearer '.$token)->withSession(['workspace_assignment_id'=>$assignment->id,'beautybook_access_hash'=>hash('sha256',$token),'beautybook_access_expires'=>time()+3600]);
        return $user;
    }
    public function test_catalog_source_contract_and_branch_coverage_actions_preserve_catalog_history(): void
    {
        $this->actor('owner@beautybook.local');$branch=Branch::where('slug','tan-tao')->firstOrFail();
        $response=$this->postJson('/api/v1/services/catalog',['name'=>'Source contract facial','description'=>'Facial treatment','price'=>180000,'durationMinutes'=>60,'branchId'=>(string)$branch->id])->assertOk();
        $id=$response->json('id');$this->assertIsString($id);$response->assertJsonPath('durationMinutes',60)->assertJsonPath('branchCoverage.active',1);
        $workspace=$this->getJson('/api/v1/services/workspace/manage?branchId='.$branch->id)->assertOk()->json();$row=collect($workspace)->firstWhere('id',$id);$this->assertSame('ACTIVE',$row['branchAvailability'][0]['status']);
        $this->assertSame(0, $row['branchAvailability'][0]['staffCount']);
        $this->assertSame(0, $row['branchAvailability'][0]['futureBookingCount']);
        $this->postJson('/api/v1/services/catalog/'.$id.'/branches/'.$branch->id.'/pause')->assertOk()->assertJsonPath('bookable',false);
        $this->getJson('/api/v1/services/workspace/manage?branchId='.$branch->id)->assertOk();
        $this->postJson('/api/v1/services/catalog/'.$id.'/branches/'.$branch->id.'/reactivate')->assertOk()->assertJsonPath('bookable',true);
        $this->patchJson('/api/v1/services/catalog/'.$id,['price'=>190000,'durationMinutes'=>65])->assertOk()->assertJsonPath('durationMinutes',65);
        $this->deleteJson('/api/v1/services/catalog/'.$id)->assertNoContent();$this->assertSoftDeleted('business_services',['id'=>$id]);
    }
    public function test_source_canonical_list_route_is_available_to_catalog_owner(): void
    {
        $expected=\App\Models\CanonicalService::orderBy('name')->firstOrFail();
        $deprecated=\App\Models\CanonicalService::where('id','<>',$expected->id)->firstOrFail();$deprecated->update(['status'=>'DEPRECATED']);
        $rows=$this->getJson('/api/v1/services/canonical')->assertOk()->json();
        $row=collect($rows)->firstWhere('id',(string)$expected->id);
        $this->assertNotNull($row);
        $this->assertSame($expected->code,$row['code']);
        $this->assertSame($expected->name,$row['name']);
        $this->assertArrayHasKey('businessServices',$row['_count']);
        $this->assertNull(collect($rows)->firstWhere('id',(string)$deprecated->id));
        $this->actor('owner@beautybook.local');
        $this->getJson('/api/v1/services/canonical/manage')->assertForbidden();
        $this->actor('admin@beautybook.local');
        $managed=$this->getJson('/api/v1/services/canonical/manage')->assertOk()->json();
        $this->assertNotNull(collect($managed)->firstWhere('id',(string)$deprecated->id));
    }
    public function test_forged_scope_headers_and_cross_business_catalog_are_denied_without_mutation(): void
    {
        $this->actor('owner@beautybook.local');$other=Business::where('slug','<>','beautybook-demo')->firstOrFail();$service=BusinessService::where('business_id',$other->id)->firstOrFail();$price=$service->base_price;
        $this->patchJson('/api/v1/services/catalog/'.$service->id,['price'=>1])->assertForbidden();$this->assertSame($price,$service->fresh()->base_price);
        $this->withHeader('X-Business-Id',(string)$other->id)->getJson('/api/v1/services/workspace/manage')->assertForbidden();
    }
    public function test_tenant_category_creation_hierarchy_and_slug_are_scoped_to_owner(): void
    {
        $this->actor('owner@beautybook.local');$business=Business::where('slug','beautybook-demo')->firstOrFail();$other=Business::where('id','<>',$business->id)->firstOrFail();
        $parent=$this->postJson('/api/v1/services/categories',['businessId'=>$business->id,'name'=>'Source parent','slug'=>'source-parent'])->assertOk()->assertJsonPath('businessId',(string)$business->id)->assertJsonPath('slug','source-parent')->json('id');
        $child=$this->postJson('/api/v1/services/categories',['businessId'=>$business->id,'parentId'=>$parent,'name'=>'Source child','slug'=>'source-child'])->assertOk()->assertJsonPath('parentId',$parent)->json('id');
        $rows=$this->getJson('/api/v1/services/categories/manage')->assertOk()->json();$this->assertSame('source-child',collect($rows)->firstWhere('id',$child)['slug']);
        $before=\App\Models\ServiceCategory::count();
        $this->postJson('/api/v1/services/categories',['businessId'=>$business->id,'name'=>'Duplicate','slug'=>'source-child'])->assertUnprocessable();
        $this->postJson('/api/v1/services/categories',['businessId'=>$other->id,'name'=>'Forbidden','slug'=>'source-parent'])->assertForbidden();
        $this->assertSame($before,\App\Models\ServiceCategory::count());
        $this->actor('owner2@beautybook.local');$rows=$this->getJson('/api/v1/services/categories/manage')->assertOk()->json();$this->assertNull(collect($rows)->firstWhere('id',$child));
        $this->postJson('/api/v1/services/categories',['businessId'=>$other->id,'parentId'=>$parent,'name'=>'Cross tenant child','slug'=>'source-child'])->assertUnprocessable();
        $this->postJson('/api/v1/services/categories',['businessId'=>$other->id,'name'=>'Same slug other tenant','slug'=>'source-parent'])->assertOk()->assertJsonPath('slug','source-parent');
    }
    public function test_branch_draft_progress_working_hours_and_booking_policy_roundtrip(): void
    {
        $this->actor('owner@beautybook.local');$business=Business::where('slug','beautybook-demo')->firstOrFail();
        $id=$this->postJson('/api/v1/branches',['businessId'=>(string)$business->id,'sameLegalEntity'=>true,'name'=>'New source branch','serviceMode'=>'AT_LOCATION'])->assertOk()->json('branch.id');
        $hours=array_map(fn($day)=>['dayOfWeek'=>$day,'openTime'=>'09:00','closeTime'=>'18:00','isClosed'=>$day===0],range(0,6));
        $this->patchJson('/api/v1/branches/'.$id.'/onboarding',['currentStep'=>5,'completedSteps'=>[1,2,3,4],'draftData'=>['wizardVersion'=>'v2'],'branch'=>['name'=>'New source branch','publicName'=>'Source branch','addressLine'=>'15 Source Street','phone'=>'0901234567','timezone'=>'Asia/Ho_Chi_Minh'],'workingHours'=>$hours,'bookingPolicy'=>['leadTimeMinutes'=>120,'bookingHorizonDays'=>30,'overbookingEnabled'=>false,'maxOverbookedSlots'=>0]])->assertOk()->assertJsonPath('onboardingProgress.currentStep',5)->assertJsonCount(7,'workingHours')->assertJsonPath('bookingPolicy.leadTimeMinutes',120);
        $detail=$this->getJson('/api/v1/branches/accessible/'.$id)->assertOk()->assertJsonPath('publicName','Source branch')->json();$this->assertSame('1970-01-01T09:00:00.000Z',$detail['workingHours'][1]['openTime']);
        $this->assertDatabaseHas('beautybook_branch_settings',['branch_id'=>$id]);
    }
    public function test_private_document_upload_download_versioning_and_scope(): void
    {
        Storage::fake('local');$this->actor('owner@beautybook.local');$business=Business::where('slug','beautybook-demo')->firstOrFail();
        $newId=$this->postJson('/api/v1/branches',['businessId'=>$business->id,'name'=>'Document draft','sameLegalEntity'=>true,'serviceMode'=>'AT_LOCATION'])->assertOk()->json('branch.id');$branch=Branch::findOrFail($newId);
        $file=UploadedFile::fake()->createWithContent('license.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $response=$this->post('/api/v1/media/upload',['file'=>$file,'entityType'=>'BRANCH_DOCUMENT','entityId'=>$branch->id,'businessId'=>$branch->business_id,'branchId'=>$branch->id],['Accept'=>'application/json'])->assertOk()->assertJsonPath('visibility','PRIVATE');$mediaId=$response->json('id');
        $this->postJson('/api/v1/branches/'.$branch->id.'/documents',['mediaId'=>$mediaId,'documentType'=>'BUSINESS_LICENSE','documentName'=>'License'])->assertOk()->assertJsonPath('0.versions.0.media.id',$mediaId);
        $this->get('/api/v1/media/'.$mediaId.'/content',['Accept'=>'application/json'])->assertOk();
        $this->deleteJson('/api/v1/media/'.$mediaId)->assertConflict();
        $this->actor('owner2@beautybook.local');$this->getJson('/api/v1/media/'.$mediaId.'/content')->assertForbidden();
    }
    public function test_staff_invitation_token_is_delivered_rotated_and_consumed_once(): void
    {
        Mail::fake();$this->actor('owner@beautybook.local');$branch=Branch::where('slug','tan-tao')->firstOrFail();
        $invite=$this->postJson('/api/v1/staff/invitations',['email'=>'invite-contract@example.test','roleCode'=>'STAFF','branchId'=>$branch->id,'businessId'=>$branch->business_id])->assertOk()->json();
        $this->assertArrayNotHasKey('tokenHash',$invite);parse_str(parse_url($invite['invitationUrl'],PHP_URL_QUERY),$query);$oldToken=$query['token'];
        $this->getJson('/api/v1/staff/invitations/context?token='.$oldToken)->assertOk()->assertJsonPath('roleCode','STAFF');
        $resend=$this->postJson('/api/v1/staff/invitations/'.$invite['id'].'/resend')->assertOk()->json();parse_str(parse_url($resend['invitationUrl'],PHP_URL_QUERY),$query);$newToken=$query['token'];
        $this->getJson('/api/v1/staff/invitations/context?token='.$oldToken)->assertNotFound();
        $this->postJson('/api/v1/staff/invitations/accept',['token'=>$newToken,'fullName'=>'Invited Worker','password'=>'SafePassword123!'])->assertOk()->assertJsonPath('accepted',true);
        $this->postJson('/api/v1/staff/invitations/accept',['token'=>$newToken,'fullName'=>'Duplicate Worker','password'=>'SafePassword123!'])->assertGone();
        $user=User::where('email','invite-contract@example.test')->firstOrFail();$this->assertDatabaseHas('role_assignments',['user_id'=>$user->id,'role_name'=>'STAFF','business_id'=>$branch->business_id,'branch_id'=>$branch->id]);
    }
    public function test_both_account_types_can_upload_only_their_own_private_avatar(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
        Storage::fake('local');Storage::fake('public');foreach(['customer@beautybook.local','owner@beautybook.local'] as $email){$user=$this->actor($email);$response=$this->post('/api/v1/media/upload',['file'=>UploadedFile::fake()->createWithContent('avatar.png',$png),'entityType'=>'USER_AVATAR','entityId'=>(string)$user->id],['Accept'=>'application/json'])->assertOk()->assertJsonPath('visibility','PRIVATE')->assertJsonPath('disk','local')->assertJsonPath('url','/api/v1/users/me/avatar');$this->assertSame((int)$response->json('id'),$user->fresh()->avatar_media_file_id);Storage::disk('public')->assertMissing($response->json('path'));}
        $privateMediaId=$response->json('id');
        $this->getJson('/api/v1/media/'.$privateMediaId.'/content')->assertOk();
        $this->actor('owner2@beautybook.local');$this->getJson('/api/v1/media/'.$privateMediaId.'/content')->assertForbidden();
        $this->post('/api/v1/media/upload',['file'=>UploadedFile::fake()->createWithContent('avatar.png',$png),'entityType'=>'USER_AVATAR','entityId'=>'99999'],['Accept'=>'application/json'])->assertForbidden();
    }

    public function test_real_avif_uploads_and_mime_spoof_are_handled_on_avatar_and_entity_paths(): void
    {
        Storage::fake('local');Storage::fake('public');$user=$this->actor('owner@beautybook.local');$business=Business::where('slug','beautybook-demo')->firstOrFail();
        $avif=file_get_contents(base_path('tests/Fixtures/valid-avatar.avif'));
        $this->post('/api/v1/media/upload',['file'=>UploadedFile::fake()->createWithContent('avatar.avif',$avif),'entityType'=>'USER_AVATAR','entityId'=>$user->id],['Accept'=>'application/json'])->assertOk()->assertJsonPath('mimeType','image/avif')->assertJsonPath('visibility','PRIVATE');
        $this->post('/api/v1/media/upload',['file'=>UploadedFile::fake()->createWithContent('logo.avif',$avif),'entityType'=>'BUSINESS_LOGO','entityId'=>$business->id,'businessId'=>$business->id],['Accept'=>'application/json'])->assertOk()->assertJsonPath('mimeType','image/avif')->assertJsonPath('visibility','PUBLIC');
        $before=MediaFile::count();
        $this->post('/api/v1/media/upload',['file'=>UploadedFile::fake()->createWithContent('avatar.avif','fake AVIF bytes'),'entityType'=>'USER_AVATAR','entityId'=>$user->id],['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertSame($before,MediaFile::count());
    }

    public function test_variant_price_rule_and_exception_calendar_source_contracts(): void
    {
        $this->actor('owner@beautybook.local');$branch=Branch::where('slug','tan-tao')->firstOrFail();$offering=BranchServiceOffering::where('branch_id',$branch->id)->firstOrFail();
        $variant=$this->postJson('/api/v1/services/'.$offering->id.'/variants',['code'=>'SOURCE-RANGE','name'=>'Source range variant','priceType'=>'RANGE','price'=>100000,'maxPrice'=>150000,'durationMinutes'=>30,'maxDurationMinutes'=>45,'bufferBeforeMinutes'=>5,'bufferAfterMinutes'=>10])->assertOk()->assertJsonPath('priceType','RANGE')->json();
        $this->patchJson('/api/v1/services/variants/'.$variant['id'],['status'=>'INACTIVE'])->assertOk()->assertJsonPath('status','INACTIVE');
        $this->postJson('/api/v1/services/'.$offering->id.'/price-rules',['name'=>'Morning surcharge','adjustmentType'=>'FIXED_AMOUNT','adjustmentValue'=>12345.67,'conditions'=>['daysOfWeek'=>[1],'startTime'=>'09:00','endTime'=>'11:00']])->assertOk()->assertJsonPath('conditions.daysOfWeek.0',1);
        $this->patchJson('/api/v1/staff/branches/'.$branch->id.'/holidays',['date'=>'2026-12-25','name'=>'Holiday','isClosed'=>true])->assertOk()->assertJsonPath('isClosed',true);
        $this->postJson('/api/v1/staff/branches/'.$branch->id.'/special-days',['date'=>'2026-12-26','startTime'=>'10:00','endTime'=>'16:00'])->assertOk()->assertJsonPath('openTime','10:00:00');
        $this->postJson('/api/v1/staff/branches/'.$branch->id.'/special-days',['date'=>'2026-12-26','startTime'=>'16:00','endTime'=>'10:00'])->assertUnprocessable();
    }

    public function test_staff_offboarding_uses_only_assigned_future_bookings_and_requires_actual_resolution(): void
    {
        $branch=Branch::where('slug','tan-tao')->firstOrFail();$offering=BranchServiceOffering::where('branch_id',$branch->id)->firstOrFail();$staff=StaffProfile::where('branch_id',$branch->id)->where('status','ACTIVE')->firstOrFail();
        $start=\Carbon\CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(10,0);while($start->dayOfWeek===0)$start=$start->addDay();
        $this->actor('customer@beautybook.local');$booking=$this->withHeader('Idempotency-Key','offboarding-source-booking')->postJson('/api/v1/bookings',['branchId'=>(string)$branch->id,'serviceIds'=>[(string)$offering->id],'staffId'=>(string)$staff->id,'appointmentDate'=>$start->toIso8601String(),'source'=>'ONLINE_WEB','customerName'=>'Source Customer','customerPhone'=>'0901234567','contactConsent'=>true])->assertCreated()->json();
        $this->actor('owner@beautybook.local');
        $catalogService=BusinessService::with(['offerings','canonicalService'])->findOrFail($offering->business_service_id);
        $beforeOffboarding=app(ManagementPresenter::class)->catalog($catalogService,$branch->id)['branchAvailability'][0];
        $this->assertGreaterThanOrEqual(1,$beforeOffboarding['futureBookingCount']);
        $this->assertGreaterThanOrEqual(1,$beforeOffboarding['staffCount']);
        $other=app(\App\Services\Staff\StaffManagementService::class)->create($branch->business,['branch_id'=>$branch->id,'full_name'=>'Unrelated Employee','status'=>'ACTIVE','is_bookable'=>true,'public_visible'=>false]);
        $emptyCase=app(\App\Services\Operations\ImpactService::class)->openForStaff($other,User::where('email','owner@beautybook.local')->firstOrFail()->id,'Unrelated staff offboarding');
        $this->assertCount(0,$emptyCase->items);
        $result=$this->deleteJson('/api/v1/staff/'.$staff->id,['reason'=>'Employee leaves'])->assertOk()->assertJsonPath('deactivated',false)->assertJsonPath('requiresImpactResolution',true)->assertJsonCount(1,'impactCase.items')->json();
        $this->assertSame('ACTIVE',$staff->fresh()->status);$caseId=$result['impactCase']['id'];$itemId=$result['impactCase']['items'][0]['id'];
        $this->patchJson('/api/v1/operational-impacts/'.$caseId.'/items/'.$itemId,['resolution'=>'CANCEL_REFUND','reason'=>'Customer agrees cancellation'])->assertOk();
        $this->postJson('/api/v1/operational-impacts/'.$caseId.'/complete')->assertOk();
        $this->deleteJson('/api/v1/staff/'.$staff->id,['reason'=>'Resolved all future bookings'])->assertOk()->assertJsonPath('deactivated',true);
        $this->assertSoftDeleted('staff_profiles',['id'=>$staff->id]);$this->assertDatabaseHas('bookings',['id'=>$booking['id'],'status'=>'CANCELLED']);
        $afterOffboarding=app(ManagementPresenter::class)->catalog($catalogService,$branch->id)['branchAvailability'][0];
        $this->assertSame($beforeOffboarding['futureBookingCount']-1,$afterOffboarding['futureBookingCount']);
        $this->assertLessThan($beforeOffboarding['staffCount'],$afterOffboarding['staffCount']);
    }
}
