<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withoutVite();
    }

    public function test_navigation_follows_selected_role_instead_of_all_grants(): void
    {
        $admin = User::where('email','admin@beautybook.local')->firstOrFail();
        $adminGrant = $admin->roleAssignments()->where('role_name','PLATFORM_ADMIN')->firstOrFail();
        $branch = Branch::where('slug','tan-tao')->firstOrFail();
        $reception = app(RoleAssignmentService::class)->grant($admin,RoleName::Receptionist,$branch->business,$branch);
        $this->actingAs($admin)->withSession(['workspace_assignment_id'=>$reception->id])->get('/app')->assertOk()->assertSee('Lễ tân')->assertDontSee('Quản trị nền tảng')->assertDontSee(route('governance.users'));
        $this->withSession(['workspace_assignment_id'=>$adminGrant->id])->get('/app')->assertOk()->assertSee('Quản trị nền tảng')->assertSee(route('governance.users'));
    }

    public function test_owner_dashboard_metrics_and_upcoming_are_tenant_scoped(): void
    {
        $owner = User::where('email','owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name','BUSINESS_OWNER')->firstOrFail();
        $local = Branch::where('business_id',$assignment->business_id)->firstOrFail();
        $foreign = Branch::where('business_id','!=',$assignment->business_id)->firstOrFail();
        foreach ([$local,$foreign] as $branch) Booking::create(['business_id'=>$branch->business_id,'branch_id'=>$branch->id,'created_by_user_id'=>$owner->id,'booking_code'=>'UI-BOOK-'.$branch->id,'appointment_start_at'=>now()->addHour(),'appointment_end_at'=>now()->addHours(2),'status'=>'CONFIRMED','source'=>'PHONE','currency'=>'VND','total_amount'=>'180000.00']);
        $this->actingAs($owner)->withSession(['workspace_assignment_id'=>$assignment->id])->get('/app')->assertOk()->assertViewHas('metrics',fn ($metrics)=>$metrics['Sắp tới']===1)->assertViewHas('upcoming',fn ($upcoming)=>$upcoming->count()===1 && $upcoming->first()->branch_id===$local->id)->assertDontSee('UI-BOOK-'.$foreign->id);
    }
}
