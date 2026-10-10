<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Business;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Policies\BranchPolicy;
use App\Policies\BusinessPolicy;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\SessionContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_seed_contains_exactly_the_five_official_roles(): void
    {
        $roles = DB::table('roles')->orderBy('name')->pluck('name')->all();

        $this->assertSame([
            'BUSINESS_OWNER',
            'CUSTOMER',
            'PLATFORM_ADMIN',
            'RECEPTIONIST',
            'STAFF',
        ], $roles);
        $this->assertNotContains('GUEST', $roles);
    }

    public function test_customer_account_cannot_receive_an_operational_role(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();

        $this->expectException(DomainException::class);

        app(RoleAssignmentService::class)->grant($customer, RoleName::BusinessOwner, $business);
    }

    public function test_operational_account_cannot_receive_customer_role(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();

        $this->expectException(DomainException::class);

        app(RoleAssignmentService::class)->grant($owner, RoleName::Customer);
    }

    public function test_branch_scoped_role_rejects_a_branch_from_another_business(): void
    {
        $staff = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $otherBusiness = Business::create([
            'name' => 'BeautyBook Other',
            'slug' => 'beautybook-other',
            'status' => 'ACTIVE',
        ]);
        $otherBranch = Branch::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Branch',
            'slug' => 'other-branch',
            'status' => 'ACTIVE',
        ]);

        $this->expectException(DomainException::class);

        app(RoleAssignmentService::class)->grant($staff, RoleName::Staff, $business, $otherBranch);
    }

    public function test_duplicate_grant_is_idempotent(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $service = app(RoleAssignmentService::class);

        $first = $service->grant($customer, RoleName::Customer);
        $second = $service->grant($customer, RoleName::Customer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RoleAssignment::where('user_id', $customer->id)
            ->where('role_name', RoleName::Customer->value)
            ->where('scope_key', 'self')
            ->count());
    }

    public function test_mysql_scope_check_rejects_invalid_normalized_scope(): void
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('role_assignments')->insert([
            'user_id' => $customer->id,
            'role_name' => RoleName::Customer->value,
            'scope_key' => 'platform',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_business_and_branch_policies_do_not_cross_tenants(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $staff = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $demoBranch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $otherBusiness = Business::create([
            'name' => 'BeautyBook Isolated',
            'slug' => 'beautybook-isolated',
            'status' => 'ACTIVE',
        ]);
        $otherBranch = Branch::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Isolated Branch',
            'slug' => 'isolated-branch',
            'status' => 'ACTIVE',
        ]);
        $businessPolicy = new BusinessPolicy();
        $branchPolicy = new BranchPolicy();

        $this->assertFalse($businessPolicy->manage($owner, $otherBusiness));
        $this->assertFalse($branchPolicy->view($receptionist, $otherBranch));
        $this->assertFalse($branchPolicy->operate($receptionist, $otherBranch));
        $this->assertTrue($branchPolicy->view($staff, $demoBranch));
        $this->assertFalse($branchPolicy->operate($staff, $demoBranch));
        $this->assertTrue($businessPolicy->view($admin, $otherBusiness));
        $this->assertFalse($businessPolicy->manage($admin, $otherBusiness));
        $this->assertTrue($branchPolicy->view($admin, $otherBranch));
        $this->assertFalse($branchPolicy->operate($admin, $otherBranch));
    }

    public function test_operational_session_context_re_resolves_the_active_assignment(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->firstOrFail();
        $request = Request::create('/workspace', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $owner);
        $context = app(SessionContext::class);

        $context->select($request, $assignment);
        $this->assertSame($assignment->id, $context->current($request)?->id);

        $assignment->delete();

        $this->assertNull($context->current($request));
    }

    public function test_revoking_an_assignment_invalidates_all_database_sessions_for_that_user(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->firstOrFail();

        DB::table('sessions')->insert([
            'id' => 'owner-session',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        app(RoleAssignmentService::class)->revoke($assignment);

        $this->assertDatabaseMissing('role_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
    }
}
