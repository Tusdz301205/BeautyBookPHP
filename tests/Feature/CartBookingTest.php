<?php

namespace Tests\Feature;

use App\Models\{Booking, Branch, BranchServiceOffering, ServiceVariant, StaffProfile, User, Voucher};
use App\Services\Promotions\{PromotionManagementService, PromotionPricingService};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function fixture(): array
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->take(2)->get();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->orderBy('id')->firstOrFail();
        foreach ($offerings as $offering) $staff->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $branch->business_id, 'branch_id' => $branch->id]]);
        $date = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays(8)->startOfDay();
        while ($date->dayOfWeek === 0) $date = $date->addDay();
        $data = ['items' => $offerings->map(fn ($offering) => ['branch_service_offering_id' => $offering->id, 'service_variant_id' => null])->all(), 'staff_profile_id' => $staff->id, 'start_at' => $date->format('Y-m-d').'T10:00'];
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->actingAs($customer);
        return [$branch, $offerings, $staff, $data, $customer];
    }

    public function test_ordered_cart_is_one_booking_with_exact_lines_snapshot_and_stable_replay(): void
    {
        [$branch, $offerings, $staff, $data] = $this->fixture();
        $this->postJson(route('booking.cart.quote', $branch), $data)->assertOk()->assertJsonCount(2, 'data.items');
        $this->withHeader('Idempotency-Key', 'cart-lines')->postJson(route('booking.cart.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', false)->assertJsonCount(2, 'booking.services');
        $booking = Booking::with(['services', 'pricingSnapshot'])->firstOrFail();
        $sum = PromotionPricingService::money($offerings->sum(fn ($o) => PromotionPricingService::cents($o->price)));
        $this->assertSame($sum, $booking->total_amount);
        $this->assertSame($sum, $booking->pricingSnapshot->subtotal_amount);
        $this->assertSame('cart-v1', $booking->pricingSnapshot->quote_version);
        $this->assertEquals($offerings->pluck('id')->all(), $booking->services->sortBy('sort_order')->pluck('branch_service_offering_id')->all());
        $this->assertTrue($booking->services[0]->item_end_at->equalTo($booking->services[1]->item_start_at));
        $this->assertDatabaseCount('combos', 0);
        $this->assertDatabaseCount('combo_booking_usages', 0);
        $this->assertDatabaseHas('notification_outbox', ['related_booking_id' => $booking->id]);
        $offerings[0]->update(['price' => '1.00']);
        $this->postJson(route('booking.cart.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('booking.total_amount', $sum);
        $data['items'] = array_reverse($data['items']);
        $this->postJson(route('booking.cart.store', $branch), $data)->assertConflict();
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_variant_buffers_do_not_overlap_and_occupied_windows_stay_in_hours(): void
    {
        [$branch, $offerings, $staff, $data] = $this->fixture();
        foreach ($offerings as $i => $offering) {
            $variant = ServiceVariant::create(['branch_service_offering_id' => $offering->id, 'code' => 'BUFFER-'.$i, 'name' => 'Có đệm', 'price_type' => 'FIXED', 'price' => '100.01', 'duration_minutes' => 30, 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 15, 'status' => 'ACTIVE', 'version' => 1]);
            $data['items'][$i]['service_variant_id'] = $variant->id;
        }
        $this->postJson(route('booking.cart.quote', $branch), $data)->assertOk()->assertJsonPath('data.duration_minutes', 85)->assertJsonPath('data.final_amount', '200.02');
        $this->withHeader('Idempotency-Key', 'cart-buffers')->postJson(route('booking.cart.store', $branch), $data)->assertCreated();
        $items = Booking::with('services')->firstOrFail()->services->sortBy('sort_order')->values();
        $this->assertTrue($items[0]->occupied_end_at->equalTo($items[1]->occupied_start_at));
        $this->assertSame(25, (int) $items[0]->item_end_at->diffInMinutes($items[1]->item_start_at));
    }

    public function test_auto_rejects_disjoint_provider_skills_instead_of_silently_splitting_cart(): void
    {
        [$branch, $offerings, $staff, $data] = $this->fixture();
        $second = StaffProfile::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'full_name' => 'Chuyên viên thứ hai', 'public_visible' => true, 'is_bookable' => true, 'status' => 'ACTIVE']);
        DB::table('staff_services')->where('branch_service_offering_id', $offerings[1]->id)->delete();
        $second->offerings()->attach($offerings[1]->id, ['business_id' => $branch->business_id, 'branch_id' => $branch->id]);
        $data['staff_profile_id'] = null;
        $this->withHeader('Idempotency-Key', 'cart-auto')->postJson(route('booking.cart.store', $branch), $data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_auto_retries_whole_itinerary_when_first_provider_is_busy_only_on_second_line(): void
    {
        [$branch, $offerings, $staff, $data, $customer] = $this->fixture();
        $second = StaffProfile::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'full_name' => 'Chuyên viên dự phòng', 'public_visible' => true, 'is_bookable' => true, 'status' => 'ACTIVE']);
        foreach ($offerings as $offering) $second->offerings()->attach($offering->id, ['business_id' => $branch->business_id, 'branch_id' => $branch->id]);
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $busy = $data;
        $busy['items'] = [$data['items'][1]];
        $busy['start_at'] = CarbonImmutable::parse($data['start_at'], 'Asia/Ho_Chi_Minh')->addMinutes($offerings[0]->duration_minutes)->format('Y-m-d\TH:i');
        $busy['contact'] = ['full_name' => 'Khách khác', 'phone' => '0900000022'];
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id])->withHeader('Idempotency-Key', 'cart-partial-busy')->postJson(route('booking.cart.store', $branch), $busy)->assertCreated();
        $data['staff_profile_id'] = null;
        $this->actingAs($customer)->withHeader('Idempotency-Key', 'cart-next-provider')->postJson(route('booking.cart.store', $branch), $data)->assertCreated();
        $booking = Booking::where('customer_profile_id', $customer->customerProfile->id)->with('services')->firstOrFail();
        $this->assertSame([$second->id], $booking->services->pluck('staff_profile_id')->unique()->values()->all());
    }

    public function test_cross_branch_and_duplicate_and_incapable_staff_are_rejected_without_partial_rows(): void
    {
        [$branch, $offerings, $staff, $data] = $this->fixture();
        $foreign = BranchServiceOffering::where('branch_id', '<>', $branch->id)->firstOrFail();
        $data['items'][1]['branch_service_offering_id'] = $foreign->id;
        $this->withHeader('Idempotency-Key', 'cart-invalid')->postJson(route('booking.cart.store', $branch), $data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0); $this->assertDatabaseCount('idempotency_records', 0);
        $data['items'][1] = $data['items'][0];
        $this->postJson(route('booking.cart.quote', $branch), $data)->assertUnprocessable();
        $data['items'][1]['branch_service_offering_id'] = $offerings[1]->id;
        DB::table('staff_services')->where('staff_profile_id', $staff->id)->where('branch_service_offering_id', $offerings[1]->id)->delete();
        $this->postJson(route('booking.cart.store', $branch), $data)->assertUnprocessable();
        $this->assertDatabaseCount('booking_services', 0);
    }

    public function test_cart_slots_cover_all_items_and_remove_busy_start_then_store_rechecks(): void
    {
        [$branch, $offerings, $staff, $data] = $this->fixture();
        $query = $data + ['date' => substr($data['start_at'], 0, 10)];
        $url = route('booking.cart.slots', $branch).'?'.http_build_query($query);
        $slots = $this->getJson($url)->assertOk()->json('slots');
        $this->assertContains($data['start_at'], array_column($slots, 'value'));
        $this->assertCount(2, $slots[0]['staff_assignments']);
        $this->withHeader('Idempotency-Key', 'cart-busy')->postJson(route('booking.cart.store', $branch), $data)->assertCreated();
        $slots = $this->getJson($url)->assertOk()->json('slots');
        $this->assertNotContains($data['start_at'], array_column($slots, 'value'));
        $this->withHeader('Idempotency-Key', 'cart-busy-second')->postJson(route('booking.cart.store', $branch), $data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 1); $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function test_voucher_is_reserved_once_for_entire_cart_and_quota_failure_rolls_back(): void
    {
        [$branch, $offerings, $staff, $data, $customer] = $this->fixture();
        $voucher = Voucher::create(['business_id' => $branch->business_id, 'name' => 'Một lượt', 'code' => 'CART10', 'discount_type' => 'PERCENTAGE', 'discount_value' => '10.00', 'min_order_value' => '0.00', 'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'total_quantity' => 1, 'max_usage_per_customer' => 1]);
        app(PromotionManagementService::class)->grant($voucher, $customer->customerProfile->id);
        $data['voucher_code'] = 'CART10';
        $this->withHeader('Idempotency-Key', 'cart-voucher')->postJson(route('booking.cart.store', $branch), $data)->assertCreated();
        $this->assertSame(1, $voucher->fresh()->used_quantity);
        $this->assertDatabaseCount('voucher_redemptions', 1);
        $this->postJson(route('booking.cart.store', $branch), $data)->assertCreated()->assertJsonPath('replayed', true);
        $data['start_at'] = substr($data['start_at'], 0, 10).'T15:00';
        $this->withHeader('Idempotency-Key', 'cart-voucher-two')->postJson(route('booking.cart.store', $branch), $data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 1); $this->assertDatabaseCount('voucher_redemptions', 1);
    }

    public function test_operational_contacts_and_workspace_scope_remain_required(): void
    {
        [$branch, $offerings, $staff, $data] = $this->fixture();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->withHeader('Idempotency-Key', 'cart-owner')->postJson(route('booking.cart.store', $branch), $data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
        $data['contact'] = ['full_name' => 'Khách điện thoại', 'phone' => '0900000011'];
        $this->postJson(route('booking.cart.store', $branch), $data)->assertCreated()->assertJsonPath('booking.customer_profile_id', null)->assertJsonPath('booking.contact.phone', '0900000011');
        $foreign = Branch::where('business_id', '<>', $branch->business_id)->firstOrFail();
        $this->postJson(route('booking.cart.quote', $foreign), $data)->assertForbidden();
    }
}
