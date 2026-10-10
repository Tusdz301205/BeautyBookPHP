<?php

namespace Tests\Feature;

use App\Models\{Branch, RoleAssignment, StaffProfile, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementMultiRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function grant(User $user, string $role, Branch $branch): RoleAssignment
    {
        return RoleAssignment::create(['user_id' => $user->id, 'role_name' => $role, 'business_id' => $branch->business_id, 'branch_id' => $branch->id, 'scope_key' => 'branch:'.$branch->business_id.':'.$branch->id]);
    }

    private function login(User $user, RoleAssignment $anchor): void
    {
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $anchor->id, 'beautybook_access_hash' => hash('sha256', 'management-multi-access'), 'beautybook_access_expires' => time() + 900])->withHeaders(['Authorization' => 'Bearer management-multi-access']);
    }

    private function secondBranch(Branch $first): Branch
    {
        $branch = $first->replicate(); $branch->slug = 'multi-role-second'; $branch->public_name = 'Second role branch'; $branch->save(); return $branch;
    }

    public function test_reception_grant_in_other_branch_does_not_unlock_staff_private_detail_or_commission(): void
    {
        $a = Branch::where('slug', 'tan-tao')->firstOrFail(); $b = $this->secondBranch($a);
        $actor = User::create(['name' => 'Mixed operator', 'email' => 'mixed-operator@example.test', 'password' => 'password', 'account_kind' => 'OPERATIONAL']);
        $anchor = $this->grant($actor, 'RECEPTIONIST', $b); $this->grant($actor, 'STAFF', $a);
        $other = StaffProfile::where('branch_id', $a->id)->firstOrFail();
        $this->login($actor, $anchor);
        $this->getJson('/api/v1/staff/'.$other->id)->assertForbidden();
        $this->getJson('/api/v1/staff/'.$other->id.'/commission')->assertForbidden();
        $this->getJson('/api/v1/staff?branchId='.$a->id)->assertForbidden();
        $this->getJson('/api/v1/staff')->assertOk()->assertJsonCount(0);
        $own = $other->replicate(); $own->user_id = $actor->id; $own->employee_code = null; $own->full_name = 'Own staff'; $own->save();
        $this->getJson('/api/v1/staff/'.$own->id)->assertOk()->assertJsonPath('id', (string) $own->id);
        $this->grant($actor, 'RECEPTIONIST', $a);
        $this->getJson('/api/v1/staff/'.$other->id)->assertOk();
        $this->getJson('/api/v1/staff/'.$other->id.'/commission')->assertOk();
        $this->getJson('/api/v1/staff?branchId='.$a->id)->assertOk()->assertJsonFragment(['id'=>(string)$other->id]);
    }

    public function test_catalog_retains_explicit_authorized_branch_and_uses_owner_grant_without_primary_role_override(): void
    {
        $a = Branch::where('slug', 'tan-tao')->firstOrFail(); $b = $this->secondBranch($a);
        $actor = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $anchor = $actor->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $this->grant($actor, 'RECEPTIONIST', $b); $this->login($actor, $anchor);
        $rows = $this->getJson('/api/v1/services/workspace/manage?branchId='.$b->id)->assertOk()->json();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) { $this->assertCount(1, $row['branchAvailability']); $this->assertSame((string) $b->id, $row['branchAvailability'][0]['branchId']); }
        $foreign = Branch::where('business_id', '<>', $a->business_id)->firstOrFail();
        $this->getJson('/api/v1/services/workspace/manage?branchId='.$foreign->id)->assertForbidden();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $reception = $this->grant($owner, 'RECEPTIONIST', $b); $this->login($owner, $reception);
        $rows = $this->getJson('/api/v1/services/workspace/manage')->assertOk()->json();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) $this->assertContains((string) $a->id, array_column($row['branchAvailability'], 'branchId'));
        $this->getJson('/api/v1/reports/owner-dashboard?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())->assertOk();
        $this->getJson('/api/v1/vouchers')->assertOk();
        $this->getJson('/api/v1/promotions')->assertOk();
        $this->getJson('/api/v1/reviews/manage')->assertOk();
        $this->getJson('/api/v1/business/onboarding/mine')->assertOk();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->getJson('/api/v1/operational-impacts')->assertOk();
        $this->postJson('/api/v1/ownership-transfers/business/'.$a->business_id.'/payout-account',['bankName'=>'Ngân hàng','accountHolder'=>'Chủ doanh nghiệp','accountNumber'=>'0123456789'])->assertCreated()->assertJsonPath('maskedAccountNumber','****6789');
        $transfer=$this->postJson('/api/v1/ownership-transfers',['businessId'=>$a->business_id,'newOwnerEmail'=>'owner2@beautybook.local','effectiveAt'=>now()->addDays(2)->toIso8601String(),'reason'=>'Chuyển chủ theo quyền đang hiệu lực'])->assertCreated()->json();
        $this->postJson('/api/v1/ownership-transfers/'.$transfer['id'].'/cancel',['reason'=>'Hủy yêu cầu kiểm thử'])->assertOk()->assertJsonPath('status','CANCELLED');
        $recipient=User::where('email','owner2@beautybook.local')->firstOrFail();
        $this->login($recipient,$recipient->roleAssignments()->firstOrFail());
        $this->getJson('/api/v1/notifications?type=OWNERSHIP_TRANSFER')->assertOk()->assertJsonPath('data.0.actionUrl','/salon/incoming-ownership');
    }
}
