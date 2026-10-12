<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernanceRoleExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_admin_peer_cannot_allow_removal_of_the_last_usable_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $role = $admin->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->firstOrFail();
        $peer = User::factory()->create(['account_kind' => 'OPERATIONAL']);
        $expired = app(RoleAssignmentService::class)->grant($peer, RoleName::PlatformAdmin);
        $expired->forceFill(['expires_at' => now('UTC')->subMinute()])->save();
        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $role->id, 'beautybook_access_hash' => hash('sha256', 'expiry-test'), 'beautybook_access_expires' => time() + 900])->withHeader('Authorization', 'Bearer expiry-test');
        // Source users.service.ts forbids self-suspension before its last-admin guard.
        $this->putJson(route('governance.users.status', $admin), ['is_active' => false, 'reason' => 'Kiểm thử quản trị viên cuối'])
            ->assertForbidden()->assertJsonPath('message', 'Không thể tự khóa tài khoản quản trị đang dùng.');
        $this->assertTrue((bool) $admin->fresh()->is_active);
        $this->deleteJson(route('governance.users.roles.revoke', [$admin, $role]))->assertUnprocessable();
        $this->assertDatabaseHas('role_assignments', ['id' => $role->id]);
        $this->postJson('/api/v1/users/'.$admin->id.'/roles', ['roleCode' => 'PLATFORM_ADMIN'])->assertConflict();
    }
}
