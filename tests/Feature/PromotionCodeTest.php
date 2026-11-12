<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Promotions\PromotionManagementService;
use App\Services\Promotions\PromotionPricingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PromotionCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function fixture(): array
    {
        $offering = BranchServiceOffering::where('bookable', true)->where('is_active', true)->firstOrFail();

        return [$offering->branch, $offering, User::where('email', 'customer@beautybook.local')->firstOrFail()->customerProfile];
    }

    private function program(Branch $branch, array $overrides = []): Promotion
    {
        return Promotion::create(array_replace(['business_id' => $branch->business_id, 'name' => 'Ưu đãi có mã', 'code' => 'SAVE20', 'target_mode' => 'ALL', 'auto_apply' => false, 'status' => 'ACTIVE', 'discount_type' => 'PERCENTAGE', 'discount_value' => '20.00', 'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'max_usage_per_customer' => 2, 'branch_ids' => [$branch->id]], $overrides))->refresh();
    }

    public function test_manual_code_needs_no_voucher_grant_and_never_applies_without_entry(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $program = $this->program($branch);
        $engine = app(PromotionPricingService::class);
        $this->assertSame('0.00', $engine->quote($branch, $offering->id, '100000.01', $customer->id)['discount_amount']);
        $quote = $engine->quote($branch, $offering->id, '100000.01', $customer->id, ' save20 ');
        $this->assertSame('20000.00', $quote['discount_amount']);
        $this->assertSame('80000.01', $quote['final_amount']);
        $this->assertTrue($quote['promotion_code_valid']);
        $this->assertSame($program->id, $quote['discount_rules'][0]['id']);
        $this->assertSame('PROMOTION', $quote['discount_rules'][0]['type']);
        $this->assertSame('SAVE20', $quote['discount_rules'][0]['snapshot']['requested_code']);
        $this->assertDatabaseCount('customer_vouchers', 0);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_a_better_automatic_offer_can_win_without_stacking_or_invalidating_the_code(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $this->program($branch);
        $better = $this->program($branch, ['code' => null, 'auto_apply' => true, 'discount_value' => '50.00']);
        $quote = app(PromotionPricingService::class)->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20');
        $this->assertTrue($quote['promotion_code_valid']);
        $this->assertSame('50000.00', $quote['final_amount']);
        $this->assertCount(1, $quote['discount_rules']);
        $this->assertSame($better->id, $quote['discount_rules'][0]['id']);
        $this->assertNull($quote['discount_rules'][0]['snapshot']['requested_code']);
    }

    public function test_customer_preview_reports_manual_code_separately_from_automatic_discounts_and_vouchers(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $offering->update(['price' => '100000.00']);
        $this->program($branch);
        $token = 'promotion-code-customer-token';
        $this->actingAs($customer->user)->withHeader('Authorization', 'Bearer '.$token)->withSession(['beautybook_access_hash' => hash('sha256', $token), 'beautybook_access_expires' => time() + 3600]);
        $this->postJson('/api/v1/bookings/preview-price', ['branchId' => (string) $branch->id, 'serviceIds' => [$offering->id], 'voucherCode' => ' save20 '])
            ->assertOk()->assertJsonPath('promotionCodeApplied', true)->assertJsonPath('promotionCodeValid', true)
            ->assertJsonPath('code', 'SAVE20')->assertJsonPath('promotionCodeDiscount', '20000.00')->assertJsonPath('promotionDiscount', '0.00')
            ->assertJsonPath('voucherApplied', false)->assertJsonPath('finalAmount', '80000.00');
    }

    public function test_archive_backfill_restores_code_and_source_flags_without_resetting_other_data_or_reapplying(): void
    {
        [$branch, $offering] = $this->fixture();
        $program = $this->program($branch, ['code' => null, 'target_mode' => 'LEGACY', 'status' => 'INACTIVE', 'service_ids' => [$offering->id]]);
        $version = $program->version;
        $sourceId = 'source-promotion-id';
        $businessId = 'source-business-id';
        DB::table('glowme_import_ids')->insert([['source_table' => 'public.promotions', 'source_id' => $sourceId, 'local_id' => $program->id], ['source_table' => 'public.businesses', 'source_id' => $businessId, 'local_id' => $branch->business_id]]);
        $payload = json_encode(['id' => $sourceId, 'business_id' => $businessId, 'code' => 'from-archive', 'target_mode' => 'SELECTED', 'auto_apply' => false, 'active' => true, 'deleted_at' => null], JSON_THROW_ON_ERROR);
        DB::table('glowme_import_rows')->insert(['source_table' => 'public.promotions', 'row_number' => 1, 'payload' => $payload, 'sha256' => hash('sha256', $payload)]);
        $migration = require database_path('migrations/2026_11_12_030000_support_promotion_codes.php');
        $migration->backfill();
        $this->assertSame('FROM-ARCHIVE', $program->refresh()->code);
        $this->assertSame('ACTIVE', $program->status);
        $this->assertSame('SELECTED', $program->target_mode);
        $this->assertFalse($program->auto_apply);
        $this->assertSame($version + 1, $program->version);
        $migration->backfill();
        $this->assertSame($version + 1, $program->refresh()->version);
        $this->assertDatabaseCount('glowme_import_rows', 1);
        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidPrograms(): array
    {
        return ['inactive' => ['inactive'], 'expired boundary' => ['expired'], 'not yet valid' => ['future'], 'another business' => ['tenant'], 'another branch' => ['branch'], 'wrong service' => ['service'], 'deleted' => ['deleted']];
    }

    #[DataProvider('invalidPrograms')]
    public function test_invalid_or_out_of_scope_codes_are_rejected(string $case): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $overrides = match ($case) {
            'inactive' => ['status' => 'INACTIVE'], 'expired' => ['end_date' => now()->startOfSecond()], 'future' => ['start_date' => now()->addHour()],
            'tenant' => ['business_id' => Business::where('id', '<>', $branch->business_id)->firstOrFail()->id],
            'branch' => ['branch_ids' => [Branch::where('business_id', $branch->business_id)->where('id', '<>', $branch->id)->firstOrFail()->id]],
            'service' => ['target_mode' => 'SELECTED', 'service_ids' => [$offering->id + 99999]], 'deleted' => ['deleted_at' => now()],
        };
        $this->program($branch, $overrides);
        $this->expectException(DomainException::class);
        app(PromotionPricingService::class)->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20');
    }

    public function test_selected_service_and_combo_targets_are_alternatives_and_only_eligible_lines_are_discounted(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $other = BranchServiceOffering::where('branch_id', $branch->id)->where('id', '<>', $offering->id)->firstOrFail();
        $this->program($branch, ['target_mode' => 'SELECTED', 'service_ids' => [$offering->id], 'combo_ids' => [9999]]);
        $engine = app(PromotionPricingService::class);
        $items = [['branch_service_offering_id' => $offering->id, 'allocated_amount' => '100000.00'], ['branch_service_offering_id' => $other->id, 'allocated_amount' => '200000.00']];
        $serviceQuote = $engine->quote($branch, $offering->id, '300000.00', $customer->id, 'SAVE20', false, null, $items);
        $this->assertSame('20000.00', $serviceQuote['discount_amount']);
        $comboQuote = $engine->quoteCombo($branch, 9999, [['branch_service_offering_id' => $other->id, 'allocated_amount' => '200000.00']], $customer->id, 'SAVE20');
        $this->assertSame('40000.00', $comboQuote['discount_amount']);
    }

    public function test_code_normalization_and_uniqueness_are_scoped_and_include_deleted_programs(): void
    {
        [$branch] = $this->fixture();
        $business = $branch->business;
        $data = ['name' => 'Mã mới', 'code' => ' fresh-20 ', 'discount_type' => 'PERCENTAGE', 'discount_value' => '20.00', 'start_date' => now()->subDay()->toISOString(), 'end_date' => now()->addDay()->toISOString(), 'auto_apply' => false];
        $service = app(PromotionManagementService::class);
        $first = $service->save($business, 'promotion', $data);
        $this->assertSame('FRESH-20', $first->code);
        $other = Business::where('id', '<>', $business->id)->firstOrFail();
        $this->assertSame('FRESH-20', $service->save($other, 'promotion', $data)->code);
        $first->delete();
        try {
            $service->save($business, 'promotion', $data);
            $this->fail('Reused deleted code accepted');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_owner_api_creates_edits_and_clears_codes_and_rejects_invalid_format(): void
    {
        [$branch] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $token = 'promotion-code-owner-token';
        $this->actingAs($owner)->withHeader('Authorization', 'Bearer '.$token)->withSession(['workspace_assignment_id' => $assignment->id, 'beautybook_access_hash' => hash('sha256', $token), 'beautybook_access_expires' => time() + 3600]);
        $data = ['name' => 'Mã API', 'code' => ' api-20 ', 'discountType' => 'PERCENTAGE', 'discountValue' => 20, 'startDate' => now()->subDay()->toISOString(), 'endDate' => now()->addDay()->toISOString(), 'autoApply' => false, 'targetMode' => 'ALL'];
        $id = $this->postJson('/api/v1/promotions', $data)->assertOk()->assertJsonPath('code', 'API-20')->json('id');
        $this->patchJson('/api/v1/promotions/'.$id, ['code' => 'changed_20'])->assertOk()->assertJsonPath('code', 'CHANGED_20');
        $this->patchJson('/api/v1/promotions/'.$id, ['code' => null])->assertOk()->assertJsonPath('code', null);
        $this->patchJson('/api/v1/promotions/'.$id, ['code' => '!invalid'])->assertUnprocessable();
    }

    public function test_reservation_preserves_manual_code_and_releases_quota_when_cancelled(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $program = $this->program($branch, ['total_quantity' => 1]);
        $engine = app(PromotionPricingService::class);
        $quote = $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20');
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->id, 'created_by_user_id' => $customer->user_id, 'booking_code' => 'MANUAL-CODE-TEST', 'appointment_start_at' => now()->addDay(), 'appointment_end_at' => now()->addDay()->addHour(), 'status' => 'PENDING', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $quote['final_amount']]);
        DB::transaction(fn () => $engine->reserve($booking, $quote));
        $this->assertDatabaseHas('promotion_redemptions', ['promotion_id' => $program->id, 'booking_id' => $booking->id, 'status' => 'RESERVED']);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $stored = json_decode(DB::table('promotion_redemptions')->where('booking_id', $booking->id)->value('rule_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('SAVE20', $stored['snapshot']['requested_code']);
        try {
            $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20');
            $this->fail('Exhausted code accepted');
        } catch (DomainException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        DB::transaction(fn () => $engine->transition($booking, 'CANCELLED'));
        $this->assertSame('20000.00', $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20')['discount_amount']);
        $this->assertDatabaseHas('promotion_redemptions', ['booking_id' => $booking->id, 'status' => 'RELEASED']);
    }

    public function test_ineligible_colliding_promotion_does_not_disable_an_entitled_native_voucher(): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $this->program($branch, ['status' => 'INACTIVE']);
        $voucher = Voucher::create(['business_id' => $branch->business_id, 'name' => 'Voucher cũ', 'code' => 'SAVE20', 'discount_type' => 'PERCENTAGE', 'discount_value' => '30.00', 'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'total_quantity' => 5, 'max_usage_per_customer' => 1]);
        app(PromotionManagementService::class)->grant($voucher, $customer->id);
        $quote = app(PromotionPricingService::class)->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20');
        $this->assertSame('70000.00', $quote['final_amount']);
        $this->assertSame('VOUCHER', $quote['discount_rules'][0]['type']);
        $this->assertFalse($quote['promotion_code_valid']);
    }

    public function test_partial_patch_cannot_empty_selected_targets_without_changing_mode(): void
    {
        [$branch, $offering] = $this->fixture();
        $program = $this->program($branch, ['target_mode' => 'SELECTED', 'service_ids' => [$offering->id]]);
        $this->expectException(DomainException::class);
        app(PromotionManagementService::class)->save($branch->business, 'promotion', ['service_ids' => [], 'combo_ids' => []], $program->id);
    }

    public static function missingManualCodes(): array
    {
        return ['missing' => [null], 'wrong' => ['WRONG-CODE']];
    }

    #[DataProvider('missingManualCodes')]
    public function test_manual_reservation_rejects_missing_or_mismatched_code(?string $code): void
    {
        [$branch, $offering, $customer] = $this->fixture();
        $this->program($branch);
        $engine = app(PromotionPricingService::class);
        $quote = $engine->quote($branch, $offering->id, '100000.00', $customer->id, 'SAVE20');
        $quote['discount_rules'][0]['snapshot']['requested_code'] = $code;
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->id, 'created_by_user_id' => $customer->user_id, 'booking_code' => 'MISSING-CODE-TEST', 'appointment_start_at' => now()->addDay(), 'appointment_end_at' => now()->addDay()->addHour(), 'status' => 'PENDING', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $quote['final_amount']]);
        $this->expectException(DomainException::class);
        DB::transaction(fn () => $engine->reserve($booking, $quote));
    }
}
