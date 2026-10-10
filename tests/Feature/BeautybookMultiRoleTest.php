<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeautybookMultiRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actor(User $user, RoleAssignment $role): void
    {
        $this->actingAs($user)->withHeader('Authorization', 'Bearer multirole-test')->withSession(['workspace_assignment_id' => $role->id, 'beautybook_access_hash' => hash('sha256', 'multirole-test'), 'beautybook_access_expires' => time() + 900]);
    }

    private function receptionistStaff(): array
    {
        $user = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staff = $user->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $receptionist = app(RoleAssignmentService::class)->grant($user, RoleName::Receptionist, $staff->business, $staff->branch);

        return [$user, $staff, $receptionist];
    }

    public function test_identity_unions_live_same_context_permissions_without_foreign_business_or_platform_roles(): void
    {
        [$user, $staff, $receptionist] = $this->receptionistStaff();
        $foreign = User::where('email', 'owner2@beautybook.local')->firstOrFail()->roleAssignments()->firstOrFail()->business;
        app(RoleAssignmentService::class)->grant($user, RoleName::BusinessOwner, $foreign);
        app(RoleAssignmentService::class)->grant($user, RoleName::PlatformAdmin);
        $this->actor($user, $receptionist);
        $response = $this->postJson('/api/v1/auth/refresh')->assertOk()->assertJsonPath('user.roles', ['STAFF', 'RECEPTIONIST']);
        $this->assertContains('booking:complete:branch', $response->json('user.permissions'));
        $this->assertSame([(string) $staff->business_id], collect($response->json('user.scopes'))->pluck('businessId')->unique()->all());
        $this->withHeader('Authorization', 'Bearer '.$response->json('accessToken'))->withHeader('X-Business-Id', (string) $foreign->id)->getJson('/api/v1/bookings')->assertForbidden();
    }

    public function test_expired_primary_assignment_can_refresh_with_another_live_grant_in_the_same_context(): void
    {
        [$user, $staff, $receptionist] = $this->receptionistStaff();
        $receptionist->update(['expires_at' => CarbonImmutable::now('UTC')->subMinute()]);
        $this->actor($user, $receptionist);
        $response = $this->postJson('/api/v1/auth/refresh')->assertOk()->assertJsonPath('user.roles', ['STAFF'])->assertJsonCount(1, 'user.scopes');
        $this->withHeader('Authorization', 'Bearer '.$response->json('accessToken'))->getJson('/api/v1/bookings')->assertOk();
    }

    public function test_receptionist_staff_executes_only_assigned_items_without_changing_session_selection(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07T03:15:00Z'));
        [$user, $staff, $receptionist] = $this->receptionistStaff();
        $profile = StaffProfile::where('user_id', $user->id)->where('branch_id', $staff->branch_id)->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $staff->branch_id)->where('is_active', true)->where('bookable', true)->firstOrFail();
        $profile->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $staff->business_id, 'branch_id' => $staff->branch_id]]);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->actor($customer, $customer->roleAssignments()->firstOrFail());
        $book = $this->withHeader('Idempotency-Key', 'multirole-execution')->postJson('/api/v1/bookings', ['branchId' => (string) $staff->branch_id, 'serviceIds' => [(string) $offering->id], 'staffId' => (string) $profile->id, 'appointmentDate' => CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(10, 0)->toIso8601String(), 'customerName' => 'Khách kiểm thử', 'customerPhone' => '0901234567', 'contactConsent' => true])->assertCreated()->json();
        Booking::findOrFail($book['id'])->update(['status' => 'CHECKED_IN', 'appointment_start_at' => CarbonImmutable::now('UTC')->subMinutes(15), 'appointment_end_at' => CarbonImmutable::now('UTC')->addMinutes(15)]);
        $item = BookingService::findOrFail($book['bookingServices'][0]['id']);
        $item->update(['item_start_at' => CarbonImmutable::now('UTC')->subMinutes(15), 'item_end_at' => CarbonImmutable::now('UTC')->addMinutes(15), 'occupied_start_at' => CarbonImmutable::now('UTC')->subMinutes(15), 'occupied_end_at' => CarbonImmutable::now('UTC')->addMinutes(15), 'duration_minutes' => 30]);
        $this->actor($user, $receptionist);
        $url = '/api/v1/bookings/'.$book['id'].'/items/'.$item->id;
        $item->update(['staff_profile_id' => null]);
        $this->patchJson($url, ['action' => 'START', 'expectedRevision' => 1, 'reason' => 'Bắt đầu thực hiện'])->assertForbidden();
        $item->update(['staff_profile_id' => $profile->id]);
        $this->patchJson($url, ['action' => 'START', 'expectedRevision' => 1, 'reason' => 'Bắt đầu thực hiện'])->assertOk()->assertJsonPath('bookingServices.0.status', 'IN_PROGRESS')->assertSessionHas('workspace_assignment_id', $receptionist->id);
        $this->patchJson($url, ['action' => 'COMPLETE', 'expectedRevision' => 2, 'reason' => 'Hoàn tất thực hiện'])->assertOk()->assertJsonPath('bookingServices.0.status', 'COMPLETED')->assertSessionHas('workspace_assignment_id', $receptionist->id);
        $this->travel(16)->minutes();
        $this->patchJson('/api/v1/bookings/'.$book['id'].'/status', ['status' => 'COMPLETED', 'note' => 'Hoàn thành lịch hẹn'])->assertOk()->assertJsonPath('status', 'COMPLETED')->assertSessionHas('workspace_assignment_id', $receptionist->id);
    }

    public function test_receptionist_in_another_branch_does_not_expand_staff_authority_on_this_branch(): void
    {
        $user = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staff = $user->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $otherBranch = Branch::where('business_id', $staff->business_id)->where('id', '<>', $staff->branch_id)->firstOrFail();
        $receptionist = app(RoleAssignmentService::class)->grant($user, RoleName::Receptionist, $staff->business, $otherBranch);
        $profile = StaffProfile::where('user_id', $user->id)->where('branch_id', $staff->branch_id)->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $staff->branch_id)->where('is_active', true)->where('bookable', true)->firstOrFail();
        $profile->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $staff->business_id, 'branch_id' => $staff->branch_id]]);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->actor($customer, $customer->roleAssignments()->firstOrFail());
        $date = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->setTime(10, 0);
        if ($date->isSunday()) {
            $date = $date->addDay();
        }
        $created = $this->withHeader('Idempotency-Key', 'multirole-branch-scope')->postJson('/api/v1/bookings', ['branchId' => (string) $staff->branch_id, 'serviceIds' => [(string) $offering->id], 'staffId' => (string) $profile->id, 'appointmentDate' => $date->toIso8601String(), 'customerName' => 'Khách kiểm thử', 'customerPhone' => '0901234567', 'contactConsent' => true])->assertCreated()->json();
        $this->actor($user, $receptionist);
        $this->getJson('/api/v1/bookings/scheduler?'.http_build_query(['branchId' => $staff->branch_id, 'startDate' => now()->toDateString(), 'endDate' => now()->addDays(10)->toDateString()]))->assertOk();
        $this->withHeader('X-Branch-Id', (string) $staff->branch_id)->getJson('/api/v1/users/me/profile')->assertOk();
        $foreignBranch = Branch::where('business_id', '<>', $staff->business_id)->firstOrFail();
        $this->withHeader('X-Branch-Id', (string) $foreignBranch->id)->getJson('/api/v1/users/me/profile')->assertForbidden();
        $this->withHeader('X-Branch-Id', (string) $staff->branch_id);
        $booking = Booking::findOrFail($created['id']);
        $this->getJson('/api/v1/bookings/'.$booking->id)->assertOk()->assertJsonPath('customerPhone', null)->assertJsonPath('customer.user.phone', null);
        $this->patchJson('/api/v1/bookings/'.$booking->id.'/status', ['status' => 'CONFIRMED'])->assertForbidden();
        $item = $booking->services()->firstOrFail();
        $this->patchJson('/api/v1/bookings/'.$booking->id.'/items/'.$item->id, ['action' => 'REASSIGN', 'staffId' => (string) $item->staff_profile_id, 'expectedRevision' => $item->revision, 'reason' => 'Không được dùng quyền chi nhánh khác'])->assertForbidden();
        $this->postJson('/api/v1/payments/collect', ['bookingId' => (string) $booking->id, 'method' => 'CASH', 'idempotencyKey' => 'multirole-denied-payment'])->assertNotFound();
    }
}
