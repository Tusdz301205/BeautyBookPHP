<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Combo;
use App\Models\CustomerBookingPolicy;
use App\Models\CustomerProfile;
use App\Models\RecurringBookingSeries;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Scheduling\RecurringService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_combo_snapshots_validation_and_archive(): void
    {
        [$branch, $offering] = $this->fixture();
        $other = BranchServiceOffering::where('branch_id', $branch->id)->whereKeyNot($offering->id)->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $data = ['name' => 'Combo chăm sóc', 'combo_price' => 100000, 'status' => 'INACTIVE', 'items' => [
            ['branch_service_offering_id' => $offering->id, 'quantity' => 2, 'transition_minutes' => 5],
            ['branch_service_offering_id' => $other->id, 'quantity' => 1],
        ]];
        $this->postJson(route('owner.combos.store', $branch), $data)->assertCreated()->assertJsonPath('version', 1);
        $combo = Combo::with('items')->firstOrFail();
        $this->assertCount(2, $combo->items);
        $this->assertEquals((float) $offering->price * 2 + (float) $other->price, $combo->original_price);
        $this->assertSame($offering->duration_minutes * 2 + $other->duration_minutes + 5, $combo->duration_minutes);
        $data['items'][1]['branch_service_offering_id'] = $offering->id;
        $this->putJson(route('owner.combos.update', [$branch, $combo]), $data)->assertUnprocessable();
        $this->assertSame(1, $combo->fresh()->version);
        $this->deleteJson(route('owner.combos.archive', [$branch, $combo]))->assertOk();
        $this->assertSoftDeleted('combos', ['id' => $combo->id]);
    }

    public function test_combo_owner_requires_current_workspace_and_rejects_customer(): void
    {
        [$branch] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $this->actingAs($owner)->getJson(route('owner.combos.index', $branch))->assertForbidden();
        $this->actingAs($this->customer())->getJson(route('owner.combos.index', $branch))->assertForbidden();
    }

    public function test_active_combo_requires_staff_and_update_increments_version(): void
    {
        [$branch, $offering] = $this->fixture();
        $other = BranchServiceOffering::where('branch_id', $branch->id)->whereKeyNot($offering->id)->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $data = ['name' => 'Active combo', 'combo_price' => 100000, 'status' => 'ACTIVE', 'items' => [
            ['branch_service_offering_id' => $offering->id, 'quantity' => 1],
            ['branch_service_offering_id' => $other->id, 'quantity' => 1],
        ]];
        $this->postJson(route('owner.combos.store', $branch), $data)->assertCreated();
        $combo = Combo::firstOrFail();
        $data['combo_price'] = 200000;
        $this->putJson(route('owner.combos.update', [$branch, $combo]), $data)->assertOk()->assertJsonPath('version', 2);
        DB::table('staff_services')->where('branch_service_offering_id', $other->id)->delete();
        $this->putJson(route('owner.combos.update', [$branch, $combo]), $data)->assertUnprocessable();
        $this->assertSame(2, $combo->fresh()->version);
    }

    public function test_public_combo_hides_expired_exhausted_and_archived_services(): void
    {
        [$branch] = $this->fixture();
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->take(2)->get();
        $combo = Combo::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'name' => 'Public combo', 'combo_price' => 100000, 'status' => 'ACTIVE']);
        foreach ($offerings as $index => $offering) {
            $combo->items()->create(['branch_service_offering_id' => $offering->id, 'quantity' => 1, 'sort_order' => $index, 'price_snapshot' => $offering->price, 'duration_snapshot' => $offering->duration_minutes]);
        }
        $this->getJson(route('combos.public'))->assertOk()->assertJsonCount(1, 'data');
        $combo->update(['max_usage' => 1, 'used_count' => 1]);
        $this->getJson(route('combos.public'))->assertJsonCount(0, 'data');
        $combo->update(['max_usage' => null, 'valid_to' => now()->subDay()]);
        $this->getJson(route('combos.public'))->assertJsonCount(0, 'data');
        $combo->update(['valid_to' => null]);
        $offerings->first()->delete();
        $this->getJson(route('combos.public'))->assertJsonCount(0, 'data');
    }

    public function test_weekly_create_replays_stably_and_rejects_changed_key_payload(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $this->actingAs($this->customer());
        $data = $this->payload($offering, $staff);
        $this->withHeader('Idempotency-Key', 'weekly-series-one')->postJson(route('recurring.store', $branch), $data)
            ->assertCreated()->assertJsonPath('replayed', false)->assertJsonCount(3, 'series.occurrences');
        $this->postJson(route('recurring.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(3, Booking::count());
        $this->assertSame(3, DB::table('idempotency_records')->where('idempotency_key', 'like', 'recurring:%')->count());
        $data['note'] = 'Changed';
        $this->postJson(route('recurring.store', $branch), $data)->assertConflict();
        $this->assertSame(1, RecurringBookingSeries::count());
    }

    public function test_conflict_rolls_back_all_occurrences_and_skip_conflicts_records_reason(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $this->actingAs($this->customer());
        $data = $this->payload($offering, $staff);
        $data['occurrence_count'] = 2;
        $this->withHeader('Idempotency-Key', 'first-series-key')->postJson(route('recurring.store', $branch), $data)->assertCreated();
        // The first date collides; the second date is beyond the previously created series.
        $data['start_date'] = CarbonImmutable::parse($this->payload($offering, $staff)['start_date'])->addWeek()->toDateString();
        $this->withHeader('Idempotency-Key', 'conflict-series-key')->postJson(route('recurring.store', $branch), $data)->assertConflict();
        $this->assertSame(2, Booking::count());
        $this->assertSame(1, RecurringBookingSeries::count());
        $data['skip_conflicts'] = true;
        $this->postJson(route('recurring.store', $branch), $data)->assertCreated()
            ->assertJsonPath('series.occurrences.0.status', 'SKIPPED')->assertJsonPath('series.occurrences.1.status', 'BOOKED');
        $this->assertSame(3, Booking::count());
    }

    public function test_same_staff_and_any_available_preview_enforce_capability(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $this->actingAs($this->customer());
        $data = $this->payload($offering, $staff);
        $this->postJson(route('recurring.preview', $branch), $data)->assertOk()->assertJsonPath('available_count', 3);
        DB::table('staff_services')->where('branch_service_offering_id', $offering->id)->delete();
        $this->postJson(route('recurring.preview', $branch), $data)->assertOk()->assertJsonPath('conflict_count', 3);
        $data['staff_mode'] = 'ANY_AVAILABLE';
        $data['staff_profile_id'] = null;
        $this->postJson(route('recurring.preview', $branch), $data)->assertOk()->assertJsonPath('conflict_count', 3);
    }

    public function test_dates_use_biweekly_and_original_month_rollover_semantics(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $data = $this->payload($offering, $staff);
        $data['start_date'] = '2027-01-31';
        $data['frequency'] = 'MONTHLY';
        $dates = app(RecurringService::class)->dates($branch, $data);
        $this->assertSame(['2027-01-31', '2027-03-03', '2027-04-03'], array_map(fn ($date) => $date->toDateString(), $dates));
        $data['frequency'] = 'BIWEEKLY';
        $dates = app(RecurringService::class)->dates($branch, $data);
        $this->assertSame('2027-02-14', $dates[1]->toDateString());
    }

    public function test_restricted_customer_cannot_create_any_series_even_when_skipping_conflicts(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = $this->customer();
        $occurredAt = CarbonImmutable::now('UTC');
        foreach ([1, 2] as $sequence) {
            $evidence = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
                'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
                'booking_code' => 'RESTRICTION-EVIDENCE-'.$sequence, 'appointment_start_at' => $occurredAt->subHours($sequence + 1),
                'appointment_end_at' => $occurredAt->subHours($sequence), 'status' => 'NO_SHOW', 'source' => 'ONLINE_WEB',
                'currency' => 'VND', 'total_amount' => '100.00']);
            app(\App\Services\Booking\BookingPolicyService::class)->recordViolation($evidence, 'NO_SHOW', $customer->id, null, $occurredAt);
        }
        $data = $this->payload($offering, $staff) + ['skip_conflicts' => true];
        $this->actingAs($customer)->withHeader('Idempotency-Key', 'restricted-series')->postJson(route('recurring.store', $branch), $data)->assertForbidden();
        $this->assertSame(0, RecurringBookingSeries::count());
        $this->assertSame(2, Booking::count());
    }

    public function test_pause_occurrence_cancel_and_series_cancel_preserve_customer_scope(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $customer = $this->customer();
        $this->actingAs($customer)->withHeader('Idempotency-Key', 'cancel-series-key')->postJson(route('recurring.store', $branch), $this->payload($offering, $staff))->assertCreated();
        $series = RecurringBookingSeries::with('occurrences')->firstOrFail();
        $this->patchJson(route('recurring.status', $series), ['status' => 'PAUSED'])->assertOk()->assertJsonPath('status', 'PAUSED');
        $this->assertSame(3, Booking::where('status', 'PENDING')->count());
        $other = User::factory()->create(['account_kind' => AccountKind::Customer]);
        CustomerProfile::create(['user_id' => $other->id]);
        $this->actingAs($other)->postJson(route('recurring.cancel', $series))->assertNotFound();
        $this->getJson(route('recurring.index'))->assertJsonCount(0, 'data');
        $this->actingAs($customer)->postJson(route('recurring.occurrences.cancel', [$series, $series->occurrences->first()->id]))->assertOk();
        $this->assertSame(1, Booking::where('status', 'CANCELLED')->count());
        $this->postJson(route('recurring.cancel', $series))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame(3, Booking::where('status', 'CANCELLED')->count());
        $this->patchJson(route('recurring.status', $series), ['status' => 'ACTIVE'])->assertConflict();
    }

    public function test_scheduling_blade_pages_render(): void
    {
        [$branch] = $this->fixture();
        $this->get(route('combos.public'))->assertOk()->assertSee('Combo dịch vụ');
        $this->actingAs($this->customer())->get(route('recurring.create', $branch))->assertOk()->assertSee('Đặt lịch định kỳ');
        $this->get(route('recurring.index'))->assertOk()->assertSee('Chuỗi lịch của tôi');
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id])->get(route('owner.combos.index', $branch))->assertOk()->assertSee('Quản lý combo');
    }

    public function test_full_combo_booking_expands_quantities_allocates_exact_price_and_replays(): void
    {
        [$branch, , $staff] = $this->fixture();
        $combo = $this->bookingCombo($branch, 2);
        $adminId = User::where('email', 'admin@beautybook.local')->value('id');
        app(\App\Services\Platform\PlatformSettingsService::class)->update(['pendingHoldMinutes' => 17], $adminId);
        $policyId = DB::table('payment_policies')->insertGetId(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'business_service_id' => $combo->items->first()->offering->business_service_id, 'name' => 'Combo service policy',
            'version' => 1, 'status' => 'ACTIVE', 'deposit_type' => 'FULL_PREPAYMENT', 'deposit_value_cents' => 0,
            'allow_split_payment' => true, 'allow_installments' => false, 'effective_from' => now()->subHour(),
            'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->customer());
        $data = ['staff_profile_id' => $staff->id, 'start_at' => $this->payload($combo->items->first()->offering, $staff)['start_date'].'T10:00'];
        $this->withHeader('Idempotency-Key', 'full-combo-booking')->postJson(route('combos.booking.store', [$branch, $combo]), $data)->assertCreated()->assertJsonPath('replayed', false);
        $booking = Booking::with(['services', 'pricingSnapshot'])->firstOrFail();
        $this->assertCount(3, $booking->services);
        $policySnapshot = DB::table('payment_policy_snapshots')->where('booking_id', $booking->id)->first();
        $this->assertSame($policyId, json_decode($policySnapshot->snapshot, true)['payment_policy_id']);
        $this->assertSame(12345678, (int) $policySnapshot->required_amount_cents);
        $this->assertSame(17, (int) round($booking->created_at->diffInMinutes($booking->pending_expires_at)));
        $this->assertSame('123456.78', $booking->total_amount);
        $this->assertSame(12345678, $booking->services->sum(fn ($item) => \App\Services\Promotions\PromotionPricingService::cents($item->price_at_booking)));
        $this->assertSame($combo->id, $booking->pricingSnapshot->source_snapshot['combo_id']);
        $this->assertSame(1, $combo->fresh()->used_count);
        $this->postJson(route('combos.booking.store', [$branch, $combo]), $data)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, $combo->fresh()->used_count);
        $this->get(route('combos.booking.create', [$branch, $combo]))->assertOk()->assertSee($combo->name);
    }

    public function test_combo_discount_applies_only_service_scoped_allocation_and_cancel_releases_usage(): void
    {
        [$branch, , $staff] = $this->fixture();
        $combo = $this->bookingCombo($branch);
        $offering = $combo->items->first()->offering;
        $promotion = \App\Models\Promotion::create(['business_id' => $branch->business_id, 'name' => 'Half first service',
            'discount_type' => 'PERCENTAGE', 'discount_value' => '50.00', 'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
            'total_quantity' => 10, 'max_usage_per_customer' => 2, 'branch_ids' => [$branch->id], 'service_ids' => [$offering->id],
            'combo_ids' => [$combo->id], 'auto_apply' => true, 'stacking_allowed' => false]);
        $this->actingAs($this->customer())->withHeader('Idempotency-Key', 'discount-combo-booking')
            ->postJson(route('combos.booking.store', [$branch, $combo]), ['staff_profile_id' => $staff->id, 'start_at' => $this->payload($offering, $staff)['start_date'].'T10:00'])->assertCreated();
        $booking = Booking::with(['services', 'pricingSnapshot'])->firstOrFail();
        $eligible = \App\Services\Promotions\PromotionPricingService::cents($booking->services->first()->price_at_booking);
        $expected = intdiv($eligible * 5000 + 5000, 10000);
        $this->assertSame($expected, \App\Services\Promotions\PromotionPricingService::cents($booking->pricingSnapshot->discount_amount));
        $this->assertDatabaseHas('promotion_redemptions', ['booking_id' => $booking->id, 'promotion_id' => $promotion->id]);
        $this->postJson(route('bookings.transition', $booking), ['status' => 'CANCELLED'])->assertOk();
        $this->assertSame(0, $combo->fresh()->used_count);
        $this->assertDatabaseHas('combo_booking_usages', ['booking_id' => $booking->id, 'status' => 'RELEASED']);
    }

    public function test_combo_last_item_conflict_rolls_back_booking_usage_and_idempotency(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $combo = $this->bookingCombo($branch);
        $start = $this->payload($offering, $staff)['start_date'].'T10:00';
        $later = CarbonImmutable::parse($start)->addMinutes($offering->duration_minutes)->format('Y-m-d\TH:i');
        $this->actingAs($this->customer())->withHeader('Idempotency-Key', 'later-conflict-booking')->postJson(route('booking.store', $branch), [
            'branch_service_offering_id' => $offering->id, 'staff_profile_id' => $staff->id, 'start_at' => $later,
        ])->assertCreated();
        $this->withHeader('Idempotency-Key', 'conflicting-combo-key')->postJson(route('combos.booking.store', [$branch, $combo]), ['staff_profile_id' => $staff->id, 'start_at' => $start])->assertUnprocessable();
        $this->assertSame(1, Booking::count());
        $this->assertSame(0, $combo->fresh()->used_count);
        $this->assertDatabaseCount('combo_booking_usages', 0);
        $this->assertDatabaseMissing('idempotency_records', ['idempotency_key' => 'conflicting-combo-key']);
    }

    public function test_per_service_provider_combo_accepts_server_scoped_assignments_and_usage_limit(): void
    {
        [$branch, , $staff] = $this->fixture();
        $combo = $this->bookingCombo($branch);
        $combo->update(['staff_assignment_mode' => 'PER_SERVICE_PROVIDER', 'max_usage' => 1]);
        $assignments = $combo->items->mapWithKeys(fn ($item) => [$item->branch_service_offering_id => $staff->id])->all();
        $data = ['staff_assignments' => $assignments, 'start_at' => $this->payload($combo->items->first()->offering, $staff)['start_date'].'T10:00'];
        $this->actingAs($this->customer())->withHeader('Idempotency-Key', 'per-provider-combo')->postJson(route('combos.booking.store', [$branch, $combo]), $data)->assertCreated();
        $data['start_at'] = CarbonImmutable::parse($data['start_at'])->addDay()->format('Y-m-d\TH:i');
        $this->withHeader('Idempotency-Key', 'exhausted-combo-key')->postJson(route('combos.booking.store', [$branch, $combo]), $data)->assertUnprocessable();
        $this->assertSame(1, Booking::count());
    }

    private function bookingCombo(Branch $branch, int $firstQuantity = 1): Combo
    {
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->take(2)->get();
        return app(\App\Services\Scheduling\ComboService::class)->save($branch, ['name' => 'Booking combo', 'combo_price' => '123456.78', 'status' => 'ACTIVE', 'items' => [
            ['branch_service_offering_id' => $offerings[0]->id, 'quantity' => $firstQuantity],
            ['branch_service_offering_id' => $offerings[1]->id, 'quantity' => 1],
        ]]);
    }

    public function test_combo_recurring_preview_create_replay_and_cancel_reuse_authoritative_combo_flow(): void
    {
        [$branch, $offering, $staff] = $this->fixture();
        $combo = $this->bookingCombo($branch, 2);
        $data = $this->payload($offering, $staff);
        unset($data['branch_service_offering_id']);
        $data['combo_id'] = $combo->id;
        $data['occurrence_count'] = 2;
        $data['staff_mode'] = 'ANY_AVAILABLE';
        $this->actingAs($this->customer())->postJson(route('recurring.preview', $branch), $data)->assertOk()->assertJsonPath('available_count', 2);
        $this->withHeader('Idempotency-Key', 'combo-recurring-key')->postJson(route('recurring.store', $branch), $data)->assertCreated()->assertJsonCount(2, 'series.occurrences');
        $this->assertSame(2, Booking::count());
        $this->assertSame(6, \App\Models\BookingService::count());
        $this->assertSame(2, $combo->fresh()->used_count);
        $this->postJson(route('recurring.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame(2, Booking::count());
        $series = RecurringBookingSeries::firstOrFail();
        $this->postJson(route('recurring.cancel', $series))->assertOk();
        $this->assertSame(0, $combo->fresh()->used_count);
    }

    private function fixture(): array
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        return [$branch, $offering, $staff];
    }

    private function customer(): User
    {
        return User::where('email', 'customer@beautybook.local')->firstOrFail();
    }

    private function payload(BranchServiceOffering $offering, StaffProfile $staff): array
    {
        $start = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->startOfDay();
        while ($start->dayOfWeek === 0) {
            $start = $start->addDay();
        }
        return ['branch_service_offering_id' => $offering->id, 'staff_profile_id' => $staff->id, 'staff_mode' => 'SAME_STAFF', 'frequency' => 'WEEKLY', 'start_date' => $start->toDateString(), 'preferred_time' => '10:00', 'occurrence_count' => 3];
    }
}
