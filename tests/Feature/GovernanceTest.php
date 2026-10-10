<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Services\Platform\GovernanceService;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withSession(['_token' => 'governance-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'governance-csrf']);
    }

    private function admin(): User
    {
        $admin = User::whereHas('roleAssignments', fn ($q) => $q->where('role_name', 'PLATFORM_ADMIN'))->firstOrFail();
        $assignment = $admin->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->firstOrFail();
        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $assignment->id]);

        return $admin;
    }

    private function booking(Branch $branch, string $status, string $code): Booking
    {
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();

        return Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id, 'booking_code' => $code, 'appointment_start_at' => now('UTC')->addDay(), 'appointment_end_at' => now('UTC')->addDay()->addHour(), 'status' => $status, 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => '999999.00']);
    }

    public function test_admin_user_directory_search_and_customer_access_rejection(): void
    {
        $this->admin();
        $this->get(route('governance.users', ['search' => 'customer@beautybook.local']))->assertOk()->assertSee('customer@beautybook.local')->assertDontSee('owner@beautybook.local');
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->getJson(route('governance.user', $customer))->assertOk()->assertJsonPath('user.account_kind', 'CUSTOMER')->assertJsonMissingPath('user.password');
        $this->actingAs($customer)->get(route('governance.users'))->assertForbidden();
    }

    public function test_suspend_revokes_sessions_remember_token_and_blocks_login_and_live_session(): void
    {
        $this->admin();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $customer->forceFill(['remember_token' => 'old-remember-token'])->save();
        DB::table('sessions')->insert(['id' => 'disabled-user-session', 'user_id' => $customer->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        $this->putJson(route('governance.users.status', $customer), ['is_active' => false, 'reason' => 'Kiểm tra khóa tài khoản'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $customer->id]);
        $this->assertNull($customer->refresh()->remember_token);
        $this->actingAs($customer)->getJson(route('loyalty.mine'))->assertForbidden();
        $this->postJson(route('login'), ['email' => $customer->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_role_grants_enforce_account_separation_and_exact_scope(): void
    {
        $this->admin();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $business = Business::firstOrFail();
        $this->postJson(route('governance.users.roles.grant', $customer), ['role_name' => 'BUSINESS_OWNER', 'business_id' => $business->id])->assertUnprocessable();
        $operational = User::factory()->create(['account_kind' => 'OPERATIONAL']);
        $this->postJson(route('governance.users.roles.grant', $operational), ['role_name' => 'CUSTOMER'])->assertUnprocessable();
        $this->postJson(route('governance.users.roles.grant', $operational), ['role_name' => 'PLATFORM_ADMIN', 'business_id' => $business->id])->assertUnprocessable();
        $branch = Branch::where('business_id', $business->id)->firstOrFail();
        $this->postJson(route('governance.users.roles.grant', $operational), ['role_name' => 'RECEPTIONIST', 'business_id' => $business->id, 'branch_id' => $branch->id])->assertCreated();
        $this->assertDatabaseHas('role_assignments', ['user_id' => $operational->id, 'role_name' => 'RECEPTIONIST', 'branch_id' => $branch->id]);
    }

    public function test_owner_can_only_grant_staff_within_selected_business(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $operational = User::factory()->create(['account_kind' => 'OPERATIONAL']);
        $this->postJson(route('governance.users.roles.grant', $operational), ['role_name' => 'PLATFORM_ADMIN'])->assertForbidden();
        $other = Branch::where('business_id', '!=', $assignment->business_id)->firstOrFail();
        $this->postJson(route('governance.users.roles.grant', $operational), ['role_name' => 'STAFF', 'business_id' => $other->business_id, 'branch_id' => $other->id])->assertForbidden();
    }

    public function test_customer_cancellation_and_no_show_do_not_reduce_salon_trust(): void
    {
        $this->admin();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $cancelled = $this->booking($branch, 'CANCELLED', 'TRUST-CUSTOMER-CANCEL');
        $history = BookingStatusHistory::create(['booking_id' => $cancelled->id, 'actor_user_id' => $cancelled->created_by_user_id, 'from_status' => 'PENDING', 'to_status' => 'CANCELLED']);
        DB::table('booking_status_histories')->where('id', $history->id)->update(['actor_kind' => 'CUSTOMER']);
        $this->booking($branch, 'NO_SHOW', 'TRUST-NO-SHOW');
        $metrics = app(GovernanceService::class)->trustMetrics(Business::findOrFail($branch->business_id));
        $this->assertSame(100, $metrics['trust_score']);
        $this->assertEquals(0, $metrics['cancellation_rate']);
        $this->assertSame(.5, $metrics['no_show_rate']);
        $this->postJson(route('governance.trust.rebuild'))->assertOk();
        $this->assertDatabaseHas('trust_snapshots', ['business_id' => $branch->business_id, 'trust_score' => 100]);
    }

    public function test_trust_warning_is_durable_and_notifies_owner(): void
    {
        $this->admin();
        $business = Business::firstOrFail();
        $this->postJson(route('governance.trust.actions', $business), ['action' => 'WARNING_SENT', 'reason' => 'Yêu cầu cải thiện chất lượng'])->assertCreated()->assertJsonPath('outcome', 'APPLIED');
        $this->assertDatabaseHas('trust_actions', ['business_id' => $business->id, 'action' => 'WARNING_SENT', 'reason' => 'Yêu cầu cải thiện chất lượng']);
        $this->assertDatabaseHas('notifications', ['type' => 'SALON_VIOLATION_ALERT']);
        $this->get(route('governance.trust'))->assertOk()->assertSee('Trust & Safety', false);
    }

    public function test_trust_suspension_with_future_booking_keeps_branch_active_and_creates_impact(): void
    {
        $this->admin();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $this->booking($branch, 'PENDING', 'TRUST-IMPACT');
        $this->postJson(route('governance.trust.actions', $branch->business), ['branch_id' => $branch->id, 'action' => 'SUSPENDED', 'reason' => 'Kiểm tra an toàn'])->assertConflict()->assertJsonPath('outcome', 'PENDING_IMPACT');
        $this->assertSame('ACTIVE', $branch->refresh()->operational_status->value);
        $this->assertDatabaseHas('operational_impact_cases', ['branch_id' => $branch->id, 'trigger_type' => 'SUSPEND']);
        $this->assertDatabaseHas('trust_actions', ['branch_id' => $branch->id, 'outcome' => 'PENDING_IMPACT']);
    }

    public function test_configured_violation_threshold_suspends_and_restore_recovers_original_status(): void
    {
        $admin = $this->admin();
        $business = Branch::where('slug', 'tan-tao')->firstOrFail()->business;
        app(PlatformSettingsService::class)->update(['violationSuspendThreshold' => 2], $admin->id);
        $this->postJson(route('governance.trust.actions', $business), ['action' => 'WARNING_SENT', 'reason' => 'Cảnh báo thứ nhất'])->assertCreated();
        $this->postJson(route('governance.trust.rebuild'))->assertOk();
        $this->assertDatabaseMissing('trust_actions', ['business_id' => $business->id, 'internal_note' => 'THRESHOLD_REVIEW']);
        $this->postJson(route('governance.trust.actions', $business), ['action' => 'EXPLANATION_REQUESTED', 'reason' => 'Yêu cầu giải trình'])->assertCreated();
        $this->postJson(route('governance.trust.rebuild'))->assertOk();
        $this->postJson(route('governance.trust.rebuild'))->assertOk();
        $this->assertSame(0, DB::table('trust_actions')->where('business_id', $business->id)->where('internal_note', 'THRESHOLD_REVIEW')->count());
        $this->assertSame('SUSPENDED', $business->refresh()->status->value);
        $this->assertNotNull($business->booking_restricted_at);
        $this->postJson(route('governance.trust.actions', $business), ['action' => 'RESTORED', 'reason' => 'Đã khắc phục'])->assertCreated()->assertJsonPath('outcome', 'APPLIED');
        $this->assertSame('ACTIVE', $business->refresh()->status->value);
        $this->assertNull($business->booking_restricted_at);
    }

    public function test_automatic_threshold_suspension_waits_for_future_booking_impact(): void
    {
        $admin = $this->admin();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        app(PlatformSettingsService::class)->update(['violationSuspendThreshold' => 2], $admin->id);
        $this->booking($branch, 'CONFIRMED', 'TRUST-AUTO-IMPACT');
        $this->postJson(route('governance.trust.actions', $branch->business), ['action' => 'WARNING_SENT', 'reason' => 'Cảnh báo đầu'])->assertCreated();
        $this->postJson(route('governance.trust.actions', $branch->business), ['action' => 'EXPLANATION_REQUESTED', 'reason' => 'Vi phạm tiếp theo'])
            ->assertConflict()->assertJsonPath('outcome', 'PENDING_IMPACT')->assertJsonCount(1, 'impact_case_ids');
        $this->assertSame('ACTIVE', $branch->business->refresh()->status->value);
        $this->assertNull($branch->business->booking_restricted_at);
        $this->assertSame('ACTIVE', $branch->refresh()->operational_status->value);
    }

    public function test_business_suspension_preflight_records_impact_for_each_branch_without_changing_status(): void
    {
        $this->admin();
        $first = Branch::where('slug', 'tan-tao')->firstOrFail();
        $second = Branch::where('business_id', $first->business_id)->whereKeyNot($first->id)->firstOrFail();
        $this->booking($first, 'PENDING', 'TRUST-BUSINESS-FIRST');
        $this->booking($second, 'CONFIRMED', 'TRUST-BUSINESS-SECOND');
        $this->postJson(route('governance.trust.actions', $first->business), ['action' => 'SUSPENDED', 'reason' => 'Kiểm tra toàn doanh nghiệp'])
            ->assertConflict()->assertJsonPath('outcome', 'PENDING_IMPACT')->assertJsonCount(2, 'impact_case_ids');
        $this->assertSame('ACTIVE', $first->business->refresh()->status->value);
        $this->assertNull($first->business->booking_restricted_at);
        $this->assertSame('ACTIVE', $first->refresh()->operational_status->value);
        $this->assertSame('ACTIVE', $second->refresh()->operational_status->value);
        $this->assertDatabaseHas('operational_impact_cases', ['branch_id' => $first->id, 'trigger_type' => 'SUSPEND']);
        $this->assertDatabaseHas('operational_impact_cases', ['branch_id' => $second->id, 'trigger_type' => 'SUSPEND']);
    }

    public function test_business_restriction_without_bookings_applies_and_blocks_public_booking(): void
    {
        $this->admin();
        $business = Branch::where('slug', 'tan-tao')->firstOrFail()->business;
        $this->postJson(route('governance.trust.actions', $business), ['action' => 'BOOKING_RESTRICTED', 'reason' => 'Đề nghị xem xét'])
            ->assertCreated()->assertJsonPath('outcome', 'APPLIED')->assertJsonCount(0, 'impact_case_ids');
        $this->assertSame('ACTIVE', $business->refresh()->status->value);
        $this->assertNotNull($business->booking_restricted_at);
        $this->assertFalse(Business::publicReady()->whereKey($business->id)->exists());
        $this->assertDatabaseHas('trust_actions', ['business_id' => $business->id, 'branch_id' => null, 'outcome' => 'APPLIED']);
    }

    public function test_dashboard_reports_actual_payment_ledger_not_booking_face_value_and_scopes_owner(): void
    {
        $admin = $this->admin();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $booking = $this->booking($branch, 'COMPLETED', 'REPORT-BOOKING');
        $payment = DB::table('payment_transactions')->insertGetId(['booking_id' => $booking->id, 'amount_cents' => 10000000, 'method' => 'CASH', 'status' => 'VERIFIED', 'idempotency_key' => 'GOV-PAYMENT', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_ledger_entries')->insert(['booking_id' => $booking->id, 'payment_transaction_id' => $payment, 'type' => 'PAYMENT_RECEIVED', 'direction' => 'CREDIT', 'amount_cents' => 10000000, 'idempotency_key' => 'GOV-LEDGER', 'actor_id' => $admin->id, 'created_at' => now()]);
        $this->getJson(route('governance.dashboard'))->assertOk()->assertJsonPath('gross_revenue', '100000.00')->assertJsonPath('net_revenue', '100000.00')->assertJsonPath('bookings', 1);
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->getJson(route('governance.dashboard'))->assertOk();
        $other = Branch::where('business_id', '!=', $assignment->business_id)->firstOrFail();
        $this->getJson(route('governance.dashboard', ['branch_id' => $other->id]))->assertForbidden();
    }
}
