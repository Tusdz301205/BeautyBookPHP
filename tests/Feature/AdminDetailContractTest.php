<?php

namespace Tests\Feature;

use App\Models\{Booking, Branch, Business, Review, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDetailContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function login(string $email): User
    {
        $user = User::where('email',$email)->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id'=>$user->roleAssignments()->firstOrFail()->id,'beautybook_access_hash'=>hash('sha256','admin-detail-token'),'beautybook_access_expires'=>time()+900])->withHeader('Authorization','Bearer admin-detail-token');
        return $user;
    }

    public function test_platform_business_detail_reports_real_owner_counts_catalog_and_members_only_in_selected_business(): void
    {
        $branch = Branch::where('slug','tan-tao')->firstOrFail(); $business = $branch->business;
        $customer = User::where('email','customer@beautybook.local')->firstOrFail();
        $foreign = Business::where('id','<>',$business->id)->firstOrFail()->branches()->firstOrFail();
        foreach ([$branch,$foreign] as $index=>$place) {
            $booking = Booking::create(['business_id'=>$place->business_id,'branch_id'=>$place->id,'customer_profile_id'=>$customer->customerProfile->id,'created_by_user_id'=>$customer->id,'booking_code'=>'ADMIN-DETAIL-'.$index,'appointment_start_at'=>now()->utc()->subDay(),'appointment_end_at'=>now()->utc()->subDay()->addHour(),'status'=>'COMPLETED','source'=>'ONLINE_WEB','total_amount'=>100,'currency'=>'VND']);
            Review::create(['booking_id'=>$booking->id,'customer_profile_id'=>$customer->customerProfile->id,'overall_rating'=>$index ? 1 : 5,'comment'=>'Actual business review','service_ratings'=>[],'status'=>'APPROVED']);
        }
        $this->login('owner@beautybook.local');
        $this->getJson('/api/v1/business/'.$business->id.'/detail')->assertForbidden();
        $this->login('admin@beautybook.local');
        $detail = $this->getJson('/api/v1/business/'.$business->id.'/detail')->assertOk()->assertJsonPath('owner.user.fullName',$business->ownerProfile->user->name)->assertJsonPath('owner.user.email',$business->ownerProfile->user->email)->assertJsonPath('summary.branchCount',$business->branches()->count())->assertJsonPath('summary.activeBranchCount',$business->branches()->where('status','ACTIVE')->count())->assertJsonPath('summary.serviceCount',$business->services()->count())->assertJsonPath('summary.bookingCount',1)->assertJsonPath('summary.averageRating',5)->assertJsonPath('summary.reviewCount',1);
        $this->assertNotEmpty($detail->json('members')); $this->assertNotEmpty($detail->json('serviceCatalog'));
        $this->assertArrayNotHasKey('password',$detail->json('owner.user'));
        $row = collect($detail->json('branches'))->firstWhere('id',(string)$branch->id);
        $this->assertSame($branch->offerings()->count(),$row['_count']['services']);
        $this->assertSame($branch->staffProfiles()->count(),$row['_count']['staff']);
        $this->assertSame(1,$row['_count']['bookings']);
        foreach ($detail->json('serviceCatalog') as $service) $this->assertArrayHasKey('branchServices',$service['_count']);
    }

    public function test_branch_detail_keeps_source_readiness_progress_and_reason_contract(): void
    {
        $branch = Branch::where('slug','tan-tao')->firstOrFail(); $this->login('admin@beautybook.local');
        $this->getJson('/api/v1/branches/accessible/'.$branch->id)->assertOk()->assertJsonStructure(['readiness'=>['ready','reasons','completed','total','progress','reviewStatus','operationalStatus'],'_count'=>['services','staff','bookings']])->assertJsonPath('readiness.total',8)->assertJsonPath('readiness.reviewStatus',$branch->review_status->value);
    }
}
