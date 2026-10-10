<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\OperationalImpactCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_closure_commits_new_impact_coverage_and_requires_completed_current_case(): void
    {
        $this->seed();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $owner->roleAssignments()->firstOrFail()->id]);
        Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'created_by_user_id' => $owner->id,
            'booking_code' => 'CLOSURE-COVERAGE', 'appointment_start_at' => now('UTC')->addDay(), 'appointment_end_at' => now('UTC')->addDay()->addHour(),
            'status' => 'CONFIRMED', 'source' => 'STAFF_CREATED', 'currency' => 'VND', 'total_amount' => '100.00']);
        $url = route('owner.branches.operational', [$branch->business_id, $branch]);
        $this->post($url, ['action' => 'CLOSE', 'reason' => 'Đóng cơ sở'])->assertSessionHasErrors('domain');
        $case = OperationalImpactCase::where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame('ACTIVE', $branch->fresh()->operational_status->value);
        $this->assertSame(1, $case->items()->count());
        $this->patchJson(route('impacts.resolve', [$case, $case->items()->first()]), ['resolution' => 'APPROVED_EXCEPTION', 'reason' => 'Khách đồng ý phương án đã được ghi nhận'])->assertOk();
        $this->postJson(route('impacts.complete', $case))->assertOk();
        $this->post($url, ['action' => 'CLOSE', 'reason' => 'Hồ sơ ảnh hưởng hoàn tất'])->assertSessionHasNoErrors();
        $this->assertSame('CLOSED', $branch->fresh()->operational_status->value);
        $this->assertSame(1, OperationalImpactCase::where('branch_id', $branch->id)->count());
    }

    public function test_empty_paused_branch_can_close_without_an_unresolvable_empty_case(): void
    {
        $this->seed();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $owner->roleAssignments()->firstOrFail()->id]);
        $url = route('owner.branches.operational', [$branch->business_id, $branch]);
        $this->post($url, ['action' => 'PAUSE', 'reason' => 'Chuẩn bị đóng cơ sở'])->assertSessionHasNoErrors();
        $this->assertSame('PAUSED', $branch->fresh()->operational_status->value);
        $this->post($url, ['action' => 'CLOSE', 'reason' => 'Không còn lịch cần xử lý'])->assertSessionHasNoErrors();
        $this->assertSame('CLOSED', $branch->fresh()->operational_status->value);
    }
}
