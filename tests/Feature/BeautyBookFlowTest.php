<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\Business;
use App\Models\District;
use App\Models\Province;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeautyBookFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;
    protected User $owner;
    protected Business $business;
    protected Branch $branch;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Users
        $this->customer = User::create([
            'name' => 'Khách hàng Test',
            'email' => 'customer_test@example.com',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Chủ Salon Test',
            'email' => 'owner_test@example.com',
            'password' => bcrypt('password'),
            'role' => 'business_owner',
            'is_active' => true,
        ]);

        // 2. Create Business & Branch
        $this->business = Business::create([
            'owner_id' => $this->owner->id,
            'name' => 'Test Salon Spa',
            'slug' => 'test-salon-spa',
            'status' => 'active',
        ]);

        $province = Province::create(['name' => 'TP. Hồ Chí Minh', 'slug' => 'tp-ho-chi-minh']);
        $district = District::create(['province_id' => $province->id, 'name' => 'Quận 1', 'slug' => 'quan-1']);

        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'district_id' => $district->id,
            'name' => 'Chi nhánh Quận 1',
            'slug' => 'chi-nhanh-quan-1',
            'address' => '123 Đồng Khởi',
            'status' => 'active',
        ]);

        // 3. Create Service
        $this->service = Service::create([
            'branch_id' => $this->branch->id,
            'name' => 'Gội đầu dưỡng sinh thư giãn',
            'slug' => 'goi-dau-duong-sinh',
            'price' => 200000,
            'discount_price' => 180000,
            'duration_minutes' => 45,
            'status' => 'active',
            'is_bookable' => true,
        ]);
    }

    /* =========================================================================
       1. Authentication & Role Authorization Tests
       ========================================================================= */

    public function test_guest_cannot_access_customer_dashboard(): void
    {
        $response = $this->get('/customer/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_guest_cannot_access_owner_dashboard(): void
    {
        $response = $this->get('/owner/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_customer_cannot_access_owner_dashboard(): void
    {
        $response = $this->actingAs($this->customer)->get('/owner/dashboard');
        $response->assertStatus(403);
    }

    public function test_owner_cannot_access_customer_dashboard(): void
    {
        $response = $this->actingAs($this->owner)->get('/customer/dashboard');
        $response->assertStatus(403);
    }

    public function test_customer_can_login_and_redirects_to_customer_dashboard(): void
    {
        $response = $this->post('/login', [
            'email' => $this->customer->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($this->customer);
        $response->assertRedirect(route('customer.dashboard', absolute: false));
    }

    public function test_owner_can_login_and_redirects_to_owner_dashboard(): void
    {
        $response = $this->post('/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($this->owner);
        $response->assertRedirect(route('owner.dashboard', absolute: false));
    }

    /* =========================================================================
       2. Public Exploration Tests
       ========================================================================= */

    public function test_public_can_view_explore_homepage(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Gội đầu dưỡng sinh thư giãn');
        $response->assertSee('Test Salon Spa');
    }

    public function test_public_ajax_live_search(): void
    {
        $response = $this->getJson('/search/ajax?q=dưỡng sinh');
        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Gội đầu dưỡng sinh thư giãn']);
    }

    public function test_public_can_view_salon_details(): void
    {
        $response = $this->get('/salons/' . $this->business->slug);
        $response->assertStatus(200);
        $response->assertSee($this->business->name);
        $response->assertSee($this->branch->name);
    }

    public function test_public_can_view_service_details(): void
    {
        $response = $this->get('/services/' . $this->service->id);
        $response->assertStatus(200);
        $response->assertSee($this->service->name);
        $response->assertSee('180.000');
    }

    /* =========================================================================
       3. Booking Life Cycle Tests (TDD)
       ========================================================================= */

    public function test_customer_can_create_booking(): void
    {
        $appointmentTime = Carbon::now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i');

        $response = $this->actingAs($this->customer)->post('/customer/bookings', [
            'branch_id' => $this->branch->id,
            'service_id' => $this->service->id,
            'appointment_date' => $appointmentTime,
            'notes' => 'Khách hàng có hẹn đặc biệt',
        ]);

        $this->assertDatabaseHas('bookings', [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'status' => 'pending',
            'total_price' => 180000,
        ]);

        $booking = Booking::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($booking);
        $response->assertRedirect(route('customer.bookings.show', $booking->id));

        // Audit trail history created
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'pending',
        ]);
    }

    public function test_customer_can_cancel_their_own_pending_booking(): void
    {
        $booking = Booking::create([
            'booking_code' => 'BK-TEST1234',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'appointment_date' => Carbon::now()->addDay(),
            'total_price' => 180000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->customer)->patchJson("/customer/bookings/{$booking->id}/cancel", [
            'cancel_reason' => 'Bận đột xuất',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $booking->fresh()->status);
        $this->assertEquals('customer', $booking->fresh()->cancelled_by);

        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'to_status' => 'cancelled',
        ]);
    }

    public function test_owner_can_update_booking_status(): void
    {
        $booking = Booking::create([
            'booking_code' => 'BK-OWNERTEST',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'appointment_date' => Carbon::now()->addDay(),
            'total_price' => 180000,
            'status' => 'pending',
        ]);

        // Confirm
        $response = $this->actingAs($this->owner)->patchJson("/owner/bookings/{$booking->id}/status", [
            'status' => 'confirmed',
            'note' => 'Đã xếp nhân viên phục vụ',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $booking->fresh()->status);

        // Complete
        $this->actingAs($this->owner)->patchJson("/owner/bookings/{$booking->id}/status", [
            'status' => 'completed',
        ]);

        $this->assertEquals('completed', $booking->fresh()->status);
    }

    /* =========================================================================
       4. Review & Rating Tests
       ========================================================================= */

    public function test_customer_can_review_completed_booking(): void
    {
        $booking = Booking::create([
            'booking_code' => 'BK-REVTEST',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'appointment_date' => Carbon::now()->subDays(2),
            'total_price' => 180000,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->customer)->post('/customer/reviews', [
            'booking_id' => $booking->id,
            'overall_rating' => 5,
            'comment' => 'Dịch vụ gội đầu dưỡng sinh cực kỳ thư thái, nhân viên nhiệt tình.',
            'is_anonymous' => 0,
        ]);

        $response->assertRedirect(route('customer.bookings.show', $booking->id));
        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id,
            'customer_id' => $this->customer->id,
            'overall_rating' => 5,
        ]);
    }

    public function test_owner_can_reply_to_review(): void
    {
        $booking = Booking::create([
            'booking_code' => 'BK-REPLYTEST',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'appointment_date' => Carbon::now()->subDays(2),
            'total_price' => 180000,
            'status' => 'completed',
        ]);

        $review = Review::create([
            'booking_id' => $booking->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'overall_rating' => 5,
            'comment' => 'Rất hài lòng!',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->owner)->postJson("/owner/reviews/{$review->id}/reply", [
            'business_reply' => 'Luxe Spa chân thành cảm ơn quý khách!',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Luxe Spa chân thành cảm ơn quý khách!', $review->fresh()->business_reply);
        $this->assertNotNull($review->fresh()->replied_at);
    }

    /* =========================================================================
       5. Customer Favorites (AJAX Toggle) Tests
       ========================================================================= */

    public function test_customer_can_toggle_favorite_service(): void
    {
        // 1. Toggle ON
        $response1 = $this->actingAs($this->customer)->postJson("/customer/favorites/{$this->service->id}/toggle");
        $response1->assertStatus(200);
        $response1->assertJson(['saved' => true]);
        $this->assertTrue($this->customer->savedServices()->where('service_id', $this->service->id)->exists());

        // 2. Toggle OFF
        $response2 = $this->actingAs($this->customer)->postJson("/customer/favorites/{$this->service->id}/toggle");
        $response2->assertStatus(200);
        $response2->assertJson(['saved' => false]);
        $this->assertFalse($this->customer->savedServices()->where('service_id', $this->service->id)->exists());
    }
}
