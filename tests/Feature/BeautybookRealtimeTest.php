<?php

namespace Tests\Feature;

use App\Models\{Booking,Branch,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeautybookRealtimeTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function login(string $email): User
    {
        $user=User::where('email',$email)->firstOrFail();$a=$user->roleAssignments()->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id'=>$a->id,'beautybook_access_hash'=>hash('sha256','realtime-access-token'),'beautybook_access_expires'=>time()+900])->withHeaders(['Authorization'=>'Bearer realtime-access-token']);
        return $user;
    }

    private function booking(Branch $branch, string $code): Booking
    {
        $user=User::where('email','customer@beautybook.local')->firstOrFail();
        return Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'customer_profile_id'=>$user->customerProfile->id,'created_by_user_id'=>$user->id,'booking_code'=>$code,'appointment_start_at'=>now()->utc()->addDay(),'appointment_end_at'=>now()->utc()->addDay()->addHour(),'status'=>'CONFIRMED','source'=>'ONLINE_WEB','currency'=>'VND','total_amount'=>'100.00']);
    }

    public function test_transactional_journal_records_bulk_updates_and_discards_rollback(): void
    {
        $branch=Branch::where('slug','tan-tao')->firstOrFail();
        DB::beginTransaction(); $this->booking($branch,'REALTIME-ROLLBACK'); DB::rollBack();
        $this->assertDatabaseCount('beautybook_realtime_events',0);
        $booking=$this->booking($branch,'REALTIME-COMMIT');
        $this->assertDatabaseHas('beautybook_realtime_events',['booking_id'=>$booking->id,'event'=>'booking_created']);
        DB::table('bookings')->where('id',$booking->id)->update(['status'=>'COMPLETED']);
        $this->assertDatabaseHas('beautybook_realtime_events',['booking_id'=>$booking->id,'event'=>'booking_updated','status'=>'COMPLETED']);
    }

    public function test_events_are_tenant_scoped_and_staff_receives_invalidation_without_foreign_ids(): void
    {
        $owner=$this->login('owner@beautybook.local'); $businessId=$owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail()->business_id;
        $staffAssignment=User::where('email','staff@beautybook.local')->firstOrFail()->roleAssignments()->where('role_name','STAFF')->firstOrFail();
        $local=Branch::where('business_id',$businessId)->findOrFail($staffAssignment->branch_id);$foreign=Branch::where('business_id','!=',$businessId)->firstOrFail();
        $own=$this->booking($local,'REALTIME-OWN');$other=$this->booking($foreign,'REALTIME-FOREIGN');
        $response=$this->getJson('/api/v1/realtime/session?after=0')->assertOk()->assertJsonCount(1,'events')->assertJsonPath('events.0.payload.id',(string)$own->id);
        $this->assertStringNotContainsString('REALTIME-FOREIGN',$response->getContent());
        $this->login('staff@beautybook.local');
        $this->getJson('/api/v1/realtime/session?after=0')->assertOk()->assertJsonCount(1,'events')->assertJsonPath('events.0.event','scheduler_resync')->assertJsonPath('events.0.payload',null);
    }

    public function test_session_expiry_or_revoked_assignment_prevents_realtime_lease(): void
    {
        $user=$this->login('owner@beautybook.local');
        $this->getJson('/api/v1/realtime/session')->assertOk()->assertJsonCount(0,'events');
        $this->withSession(['beautybook_access_expires'=>time()-1])->getJson('/api/v1/realtime/session')->assertUnauthorized();
        $this->withSession(['beautybook_access_expires'=>time()+900]);
        $user->roleAssignments()->delete();
        $this->getJson('/api/v1/realtime/session')->assertForbidden();
    }

    public function test_secondary_staff_branch_events_never_inherit_receptionist_payload_privileges(): void
    {
        $user=$this->login('staff@beautybook.local');
        $staffRole=$user->roleAssignments()->where('role_name','STAFF')->firstOrFail();
        $staffBranch=Branch::findOrFail($staffRole->branch_id);
        $counterBranch=Branch::where('business_id',$staffRole->business_id)->where('id','!=',$staffBranch->id)->firstOrFail();
        $counterRole=app(\App\Services\Identity\RoleAssignmentService::class)->grant($user,\App\Enums\RoleName::Receptionist,$staffBranch->business,$counterBranch);
        $this->withSession(['workspace_assignment_id'=>$counterRole->id]);
        $staffBooking=$this->booking($staffBranch,'REALTIME-SECONDARY-STAFF');
        $counterBooking=$this->booking($counterBranch,'REALTIME-COUNTER');
        $foreign=Branch::where('business_id','!=',$staffRole->business_id)->firstOrFail();
        $this->booking($foreign,'REALTIME-SECONDARY-FOREIGN');
        $result=$this->getJson('/api/v1/realtime/session?after=0')->assertOk()->assertJsonCount(2,'events')->assertJsonPath('events.0.event','scheduler_resync')->assertJsonPath('events.0.payload',null)->assertJsonPath('events.1.payload.id',(string)$counterBooking->id);
        $authorization=$result->json('authorization');
        $this->assertNotEquals((string)$staffBooking->id,$result->json('events.1.payload.id'));
        $staffRole->delete();
        $next=$this->getJson('/api/v1/realtime/session?after=0')->assertOk()->assertJsonCount(1,'events');
        $this->assertNotSame($authorization,$next->json('authorization'));
    }
}
