<?php

namespace Tests\Feature;

use App\Models\BranchServiceOffering;
use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeautybookPublicPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_explore_exposes_next_page_and_listing_retains_services_without_public_providers(): void
    {
        $this->getJson('/api/v1/staff/public')->assertStatus(400);
        $first = $this->getJson('/api/v1/services/search?limit=1')->assertOk()->assertJsonPath('hasMore', true);
        $last = $first->json('totalPages');
        $this->getJson('/api/v1/services/search?limit=1&page='.$last)->assertOk()->assertJsonPath('hasMore', false);
        $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->where('bookable', true)->firstOrFail();
        $offering->staff()->update(['public_visible' => false]);
        $this->getJson('/api/v1/services/'.$offering->id)->assertOk()->assertJsonPath('availableStaffCount', 0);
        $ids = array_column($this->getJson('/api/v1/services/search?branchId='.$offering->branch_id.'&limit=50')->assertOk()->json('data'), 'id');
        $this->assertNotContains((string) $offering->id, $ids);
        $this->getJson('/api/v1/services?branchId='.$offering->branch_id)->assertOk()->assertJsonFragment(['id' => (string) $offering->id]);
    }

    public function test_detail_prices_use_real_variant_ranges_and_consultation_labels(): void
    {
        $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->where('bookable', true)->firstOrFail();
        $offering->variants()->delete();
        $variant = ServiceVariant::create(['branch_service_offering_id' => $offering->id, 'code' => 'SOURCE-RANGE', 'name' => 'Theo nhu cầu', 'price_type' => 'RANGE', 'price' => 120000, 'max_price' => 250000, 'duration_minutes' => 45, 'status' => 'ACTIVE', 'version' => 1]);
        $this->getJson('/api/v1/services/'.$offering->id)->assertOk()->assertJsonPath('priceDisplay', 'Từ 120.000đ – 250.000đ')->assertJsonPath('variants.0.priceDisplay', '120.000đ – 250.000đ');
        $variant->update(['price' => '120000.25', 'max_price' => '250000.10']);
        $this->getJson('/api/v1/services/'.$offering->id)->assertOk()->assertJsonPath('priceDisplay', 'Từ 120.000,25đ – 250.000,1đ')->assertJsonPath('variants.0.priceDisplay', '120.000,25đ – 250.000,1đ');
        $variant->update(['price_type' => 'QUOTE', 'price' => null, 'max_price' => null, 'consultation_required' => true]);
        $this->getJson('/api/v1/services/'.$offering->id)->assertOk()->assertJsonPath('priceDisplay', 'Có lựa chọn cần tư vấn')->assertJsonPath('variants.0.priceDisplay', 'Cần tư vấn')->assertJsonPath('variants.0.price', null);
    }

    public function test_service_detail_returns_only_linked_current_tenant_promotions(): void
    {
        $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->where('bookable', true)->firstOrFail();
        $data = ['business_id' => $offering->business_id, 'name' => 'Ưu đãi dịch vụ', 'discount_type' => 'PERCENTAGE', 'discount_value' => 10, 'start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'status' => 'ACTIVE', 'service_ids' => [$offering->id]];
        $promotion = \App\Models\Promotion::create($data);
        \App\Models\Promotion::create(array_merge($data, ['name' => 'Hết hạn', 'end_date' => now()->subMinute()]));
        $otherBusiness = \App\Models\Business::where('id', '!=', $offering->business_id)->firstOrFail();
        \App\Models\Promotion::create(array_merge($data, ['name' => 'Doanh nghiệp khác', 'business_id' => $otherBusiness->id]));
        $this->getJson('/api/v1/services/'.$offering->id)->assertOk()->assertJsonCount(1, 'promotionLinks')->assertJsonPath('promotionLinks.0.promotion.id', (string) $promotion->id);
    }

    public function test_service_reviews_are_offering_scoped_and_do_not_expose_anonymous_customer(): void
    {
        $offering = BranchServiceOffering::whereHas('branch', fn ($q) => $q->publicReady())->where('bookable', true)->firstOrFail();
        $other = BranchServiceOffering::where('id', '!=', $offering->id)->whereHas('branch', fn ($q) => $q->publicReady())->where('bookable', true)->firstOrFail();
        $customer = \App\Models\User::where('email', 'customer@beautybook.local')->firstOrFail();
        foreach ([[$offering, 5], [$other, 1]] as [$service, $score]) {
            $booking = \App\Models\Booking::create(['business_id' => $service->business_id, 'branch_id' => $service->branch_id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id, 'booking_code' => 'PUBLIC-SOURCE-REVIEW-'.$service->id, 'appointment_start_at' => now('UTC')->subDay(), 'appointment_end_at' => now('UTC')->subDay()->addHour(), 'status' => 'COMPLETED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => $service->price]);
            $item = \App\Models\BookingService::create(['booking_id' => $booking->id, 'business_id' => $service->business_id, 'branch_id' => $service->branch_id, 'branch_service_offering_id' => $service->id, 'business_service_id' => $service->business_service_id, 'price_at_booking' => $service->price, 'duration_minutes' => $service->duration_minutes, 'currency' => 'VND', 'service_name_snapshot' => $service->service->name, 'status' => 'COMPLETED', 'sort_order' => 0, 'item_start_at' => $booking->appointment_start_at, 'item_end_at' => $booking->appointment_end_at, 'occupied_start_at' => $booking->appointment_start_at, 'occupied_end_at' => $booking->appointment_end_at]);
            \App\Models\Review::create(['booking_id' => $booking->id, 'customer_profile_id' => $customer->customerProfile->id, 'overall_rating' => $score, 'comment' => 'Trải nghiệm đã hoàn tất', 'status' => 'APPROVED', 'is_anonymous' => true, 'service_ratings' => [['booking_service_id' => $item->id, 'business_service_id' => $service->business_service_id, 'service_name' => $service->service->name, 'rating' => $score, 'comment' => 'Trải nghiệm đã hoàn tất']]]);
        }
        $this->getJson('/api/v1/reviews/service/'.$offering->id)->assertOk()->assertJsonPath('averageRating', 5)->assertJsonPath('totalRatings', 1)->assertJsonPath('ratings.0.customerName', 'Ẩn danh')->assertJsonMissing(['email' => $customer->email]);
    }
}
