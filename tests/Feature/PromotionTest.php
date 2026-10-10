<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Pricing\QuoteService;
use App\Services\Promotions\PromotionManagementService;
use App\Services\Promotions\PromotionPricingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withSession(['_token' => 'promotion-test-token'])->withHeaders(['X-CSRF-TOKEN' => 'promotion-test-token']);
    }

    private function fixture(): array
    {
        $offering = BranchServiceOffering::where('bookable', true)->where('is_active', true)->firstOrFail();

        return [Branch::findOrFail($offering->branch_id), $offering, User::where('email', 'customer@beautybook.local')->firstOrFail()->customerProfile];
    }

    private function promotion(Branch $branch, array $overrides = []): Promotion
    {
        return Promotion::create(array_replace(['business_id' => $branch->business_id, 'name' => 'Ưu đãi', 'discount_type' => 'PERCENTAGE', 'discount_value' => '10.00', 'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'total_quantity' => 10, 'max_usage_per_customer' => 2, 'branch_ids' => [$branch->id], 'auto_apply' => true, 'stacking_allowed' => false], $overrides))->refresh();
    }

    private function voucher(Branch $branch, int $customer, array $overrides = []): Voucher
    {
        $voucher = Voucher::create(array_replace(['business_id' => $branch->business_id, 'name' => 'Voucher', 'code' => 'SAVE50', 'discount_type' => 'PERCENTAGE', 'discount_value' => '50.00', 'min_order_value' => '0.00', 'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'total_quantity' => 2, 'max_usage_per_customer' => 2], $overrides))->refresh();
        app(PromotionManagementService::class)->grant($voucher, $customer);

        return $voucher;
    }

    public function test_quote_selects_best_rule_with_stable_tie_and_exact_money(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $first = $this->promotion($branch, ['discount_value' => '20.00']);
        $this->promotion($branch, ['discount_value' => '20.00']);
        $quote = app(PromotionPricingService::class)->quote($branch, $offering->id, '100000.01', $customer->id);
        $this->assertSame('20000.00', $quote['discount_amount']);
        $this->assertSame('80000.01', $quote['final_amount']);
        $this->assertSame($first->id, $quote['discount_rules'][0]['id']);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_voucher_wins_without_stacking_and_stacks_on_remaining_price_when_allowed(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $promotion = $this->promotion($branch, ['discount_value' => '20.00']);
        $this->voucher($branch, $customer->id);
        $engine = app(PromotionPricingService::class);
        $quote = $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'save50');
        $this->assertSame('50000.00', $quote['final_amount']);
        $this->assertCount(1, $quote['discount_rules']);
        $this->assertSame('VOUCHER', $quote['discount_rules'][0]['type']);
        $promotion->update(['stacking_allowed' => true]);
        $quote = $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE50');
        $this->assertSame('40000.00', $quote['final_amount']);
        $this->assertCount(2, $quote['discount_rules']);
    }

    public function test_scopes_audiences_expiry_and_entitlement_are_enforced(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $this->promotion($branch, ['audience' => 'VIP']);
        $this->promotion($branch, ['service_ids' => [$offering->id + 99999]]);
        $this->promotion($branch, ['end_date' => now()->subHour()]);
        $engine = app(PromotionPricingService::class);
        $this->assertSame('0.00', $engine->quote($branch, $offering->id, '100000.00', $customer->id)['discount_amount']);
        DB::table('customer_business_segments')->insert(['business_id' => $branch->business_id, 'customer_profile_id' => $customer->id, 'segment' => 'VIP']);
        $this->assertSame('10000.00', $engine->quote($branch, $offering->id, '100000.00', $customer->id)['discount_amount']);
        $voucher = $this->voucher($branch, $customer->id);
        DB::table('customer_vouchers')->where('voucher_id', $voucher->id)->update(['status' => 'REVOKED']);
        $this->expectException(DomainException::class);
        $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE50');
    }

    public function test_reservation_quota_release_and_apply_are_idempotent(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $voucher = $this->voucher($branch, $customer->id, ['total_quantity' => 1]);
        $engine = app(PromotionPricingService::class);
        $quote = $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE50');
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->id, 'created_by_user_id' => $customer->user_id, 'booking_code' => 'TEST-PROMO', 'appointment_start_at' => now()->addDay(), 'appointment_end_at' => now()->addDay()->addHour(), 'status' => 'PENDING', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $quote['final_amount']]);
        DB::transaction(fn () => $engine->reserve($booking, $quote));
        $this->assertSame(1, $voucher->refresh()->used_quantity);
        $this->assertDatabaseHas('voucher_redemptions', ['booking_id' => $booking->id, 'status' => 'RESERVED']);
        try {
            app(PromotionManagementService::class)->save(Business::find($branch->business_id), 'voucher', ['total_quantity' => 0], $voucher->id);
            $this->fail('Quota lowering accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('quota', $e->getMessage());
        }
        DB::transaction(fn () => $engine->transition($booking, 'CANCELLED'));
        DB::transaction(fn () => $engine->transition($booking, 'CANCELLED'));
        $this->assertSame(0, $voucher->refresh()->used_quantity);
        $booking2 = $booking->replicate();
        $booking2->booking_code = 'TEST-PROMO-2';
        $booking2->save();
        DB::transaction(fn () => $engine->reserve($booking2, $quote));
        DB::transaction(fn () => $engine->transition($booking2, 'COMPLETED'));
        DB::transaction(fn () => $engine->transition($booking2, 'COMPLETED'));
        $this->assertSame(1, $voucher->refresh()->used_quantity);
        $this->assertDatabaseHas('voucher_redemptions', ['booking_id' => $booking2->id, 'status' => 'APPLIED']);
    }

    public function test_owner_crud_ui_and_cross_business_scope_rejection(): void
    {
        [$branch] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $business = Business::findOrFail($assignment->business_id);
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->get(route('owner.promotions.index', $business))->assertOk()->assertSee('Khuyến mãi và voucher');
        $data = ['type' => 'promotion', 'name' => 'Test CRUD', 'discount_type' => 'PERCENTAGE', 'discount_value' => '10.00', 'start_date' => now()->subDay()->toISOString(), 'end_date' => now()->addDay()->toISOString()];
        $this->postJson(route('owner.promotions.store', $business), $data)->assertCreated();
        $rule = Promotion::where('name', 'Test CRUD')->firstOrFail();
        $this->putJson(route('owner.promotions.update', ['business' => $business, 'type' => 'promotion', 'id' => $rule->id]), ['status' => 'INACTIVE'])->assertOk();
        $this->assertSame(2, $rule->refresh()->version);
        $other = Branch::where('business_id', '!=', $business->id)->firstOrFail();
        $this->postJson(route('owner.promotions.store', $business), $data + ['branch_ids' => [$other->id]])->assertUnprocessable();
        $this->delete(route('owner.promotions.destroy', ['business' => $business, 'type' => 'promotion', 'id' => $rule->id]))->assertRedirect();
        $this->assertSoftDeleted($rule);
    }

    public function test_server_quote_ignores_client_price_and_enforces_voucher_minimum_and_cap(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $this->voucher($branch, $customer->id, ['max_discount' => '10000.00']);
        $quote = app(QuoteService::class)->quote($branch, $offering->id, null, false, $customer->id, 'SAVE50');
        $this->assertSame((string) $offering->price, $quote['subtotal_amount']);
        $this->assertSame('10000.00', $quote['discount_amount']);
        Voucher::where('code', 'SAVE50')->update(['min_order_value' => '999999999.00']);
        $this->expectException(DomainException::class);
        app(QuoteService::class)->quote($branch, $offering->id, null, false, $customer->id, 'SAVE50');
    }

    public function test_booking_transaction_reserves_once_and_keeps_immutable_discount_snapshot(): void
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $voucher = $this->voucher($branch, $customer->customerProfile->id);
        $start = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime(10, 0);
        while ($start->dayOfWeek === 0) {
            $start = $start->addDay();
        }
        $data = ['branch_service_offering_id' => $offering->id, 'staff_profile_id' => $staff->id, 'start_at' => $start->format('Y-m-d\TH:i'), 'voucher_code' => 'SAVE50', 'final_amount' => '1.00'];
        $this->actingAs($customer)->withHeaders(['Idempotency-Key' => 'promotion-booking-once']);
        $this->postJson(route('booking.store', $branch), $data)->assertCreated();
        $this->postJson(route('booking.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(1, $voucher->refresh()->used_quantity);
        $this->assertDatabaseCount('voucher_redemptions', 1);
        $booking = Booking::with('pricingSnapshot')->firstOrFail();
        $snapshot = $booking->pricingSnapshot->source_snapshot['discount_rules'];
        $this->assertSame('VOUCHER', $snapshot[0]['type']);
        $this->assertNotSame('1.00', $booking->total_amount);
        $voucher->update(['discount_value' => '5.00', 'version' => 2]);
        $this->assertSame($snapshot, $booking->pricingSnapshot->refresh()->source_snapshot['discount_rules']);
        $changed = $data;
        $changed['voucher_code'] = 'OTHER';
        $this->postJson(route('booking.store', $branch), $changed)->assertConflict();
    }

    public function test_platform_workspace_isolated_from_tenant_campaigns(): void
    {
        [$branch] = $this->fixture();
        $tenant = $this->promotion($branch, ['name' => 'Campaign Tenant Private']);
        $admin = User::whereHas('roleAssignments', fn ($q) => $q->where('role_name', 'PLATFORM_ADMIN'))->firstOrFail();
        $assignment = $admin->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->firstOrFail();
        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->get(route('admin.promotions.index'))->assertOk()->assertDontSee($tenant->name);
        $this->putJson(route('admin.promotions.update', ['type' => 'promotion', 'id' => $tenant->id]), ['status' => 'INACTIVE'])->assertForbidden();
        $this->withSession(['workspace_assignment_id' => 999999])->get(route('admin.promotions.index'))->assertForbidden();
    }

    public function test_automatic_issue_requires_related_business_and_matching_segment(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $voucher = $this->voucher($branch, $customer->id, ['auto_issue' => true, 'audience' => 'VIP']);
        DB::table('customer_vouchers')->delete();
        $service = app(PromotionManagementService::class);
        $service->issueAutomatic($customer);
        $this->assertDatabaseCount('customer_vouchers', 0);
        DB::table('customer_business_segments')->insert(['business_id' => $branch->business_id, 'customer_profile_id' => $customer->id, 'segment' => 'VIP']);
        $service->issueAutomatic($customer);
        $service->issueAutomatic($customer);
        $this->assertDatabaseCount('customer_vouchers', 1);
        $this->assertDatabaseHas('customer_vouchers', ['voucher_id' => $voucher->id, 'customer_profile_id' => $customer->id]);
    }

    public function test_combo_discount_only_uses_allocated_lines_in_service_scope(): void
    {
        [$branch,$offering,$customer] = $this->fixture();
        $other = BranchServiceOffering::where('branch_id', $branch->id)->whereKeyNot($offering->id)->firstOrFail();
        $this->promotion($branch, ['service_ids' => [$offering->id], 'combo_ids' => [9999], 'discount_value' => '50.00']);
        $quote = app(PromotionPricingService::class)->quoteCombo($branch, 9999, [['branch_service_offering_id' => $offering->id, 'allocated_amount' => '10000.00'], ['branch_service_offering_id' => $other->id, 'allocated_amount' => '90000.00']], $customer->id);
        $this->assertSame('5000.00', $quote['discount_amount']);
        $this->assertSame('95000.00', $quote['final_amount']);
        $this->assertSame(9999, $quote['discount_rules'][0]['snapshot']['combo_id']);
        $this->assertSame('0.00', app(PromotionPricingService::class)->quote($branch, $offering->id, '100000.00', $customer->id)['discount_amount']);
    }
}
