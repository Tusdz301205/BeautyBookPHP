<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(Branch $branch, CustomerProfile $profile, string $code, string $start, string $end): Booking
    {
        return Booking::create([
            'business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $profile->id,
            'created_by_user_id' => $profile->user_id, 'booking_code' => $code,
            'appointment_start_at' => CarbonImmutable::parse($start, 'UTC'), 'appointment_end_at' => CarbonImmutable::parse($end, 'UTC'),
            'status' => 'CONFIRMED', 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 250000,
        ]);
    }

    public function test_customer_week_includes_cross_midnight_overlap_and_hides_other_customer(): void
    {
        $this->seed();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $profile = CustomerProfile::where('user_id', $customer->id)->firstOrFail();
        $other = CustomerProfile::whereKeyNot($profile->id)->firstOrFail();
        $branch = Branch::firstOrFail();
        $visible = $this->appointment($branch, $profile, 'CAL-BOUNDARY', '2026-10-04 16:30', '2026-10-04 17:30');
        $hidden = $this->appointment($branch, $other, 'CAL-FOREIGN', '2026-10-05 02:00', '2026-10-05 03:00');
        $previous = $this->appointment($branch, $profile, 'CAL-PREVIOUS', '2026-10-04 15:00', '2026-10-04 16:00');
        $response = $this->actingAs($customer)->get(route('bookings.calendar', ['date' => '2026-10-07', 'view' => 'week']));
        $response->assertOk()->assertSee('data-calendar-booking="'.$visible->id.'"', false)
            ->assertDontSee('data-calendar-booking="'.$hidden->id.'"', false)
            ->assertDontSee('data-calendar-booking="'.$previous->id.'"', false)
            ->assertSee('Giờ Việt Nam (UTC+7)');
        $this->assertCount(7, $response->viewData('days'));
        $this->assertSame('2026-10-05', $response->viewData('from')->toDateString());
        $this->assertCount(1, $response->viewData('groups')['2026-10-05']);
    }

    public function test_owner_calendar_remains_tenant_scoped_even_with_foreign_filters(): void
    {
        $this->seed();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $local = Branch::where('business_id', $assignment->business_id)->firstOrFail();
        $foreign = Branch::where('business_id', '!=', $assignment->business_id)->firstOrFail();
        $profile = CustomerProfile::firstOrFail();
        $localBooking = $this->appointment($local, $profile, 'CAL-LOCAL', '2026-10-07 02:00', '2026-10-07 03:00');
        $foreignBooking = $this->appointment($foreign, $profile, 'CAL-OUTSIDE', '2026-10-07 02:00', '2026-10-07 03:00');
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->get(route('bookings.calendar', ['date' => '2026-10-07', 'view' => 'day']))->assertOk()
            ->assertSee('data-calendar-booking="'.$localBooking->id.'"', false)
            ->assertDontSee('data-calendar-booking="'.$foreignBooking->id.'"', false);
        $this->get(route('bookings.calendar', ['date' => '2026-10-07', 'branch_id' => $foreign->id, 'business_id' => $foreign->business_id]))
            ->assertOk()->assertDontSee('data-calendar-booking=', false);
    }

    public function test_staff_calendar_hides_unassigned_booking_and_other_staff_service_details(): void
    {
        $this->seed();
        $staffUser = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staff = StaffProfile::where('user_id', $staffUser->id)->firstOrFail();
        $profile = CustomerProfile::firstOrFail();
        $visible = $this->appointment($staff->branch, $profile, 'CAL-ASSIGNED', '2026-10-07 02:00', '2026-10-07 03:00');
        $hidden = $this->appointment($staff->branch, $profile, 'CAL-UNASSIGNED', '2026-10-07 04:00', '2026-10-07 05:00');
        $offering = BranchServiceOffering::where('branch_id', $staff->branch_id)->with('service')->firstOrFail();
        $base = [
            'booking_id' => $visible->id, 'business_id' => $staff->business_id, 'branch_id' => $staff->branch_id,
            'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
            'canonical_service_id' => $offering->service->canonical_service_id, 'staff_profile_id' => $staff->id,
            'price_at_booking' => $offering->price, 'currency' => 'VND', 'duration_minutes' => 30,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'service_name_snapshot' => 'CAL-SERVICE-OWN',
            'sort_order' => 0, 'status' => 'SCHEDULED', 'item_start_at' => $visible->appointment_start_at->addMinutes(15),
            'item_end_at' => $visible->appointment_start_at->addMinutes(45), 'occupied_start_at' => $visible->appointment_start_at->addMinutes(15),
            'occupied_end_at' => $visible->appointment_start_at->addMinutes(45), 'revision' => 1,
        ];
        BookingService::create($base);
        $otherStaff = StaffProfile::create(['business_id' => $staff->business_id, 'branch_id' => $staff->branch_id,
            'full_name' => 'Other calendar staff', 'employee_code' => 'CAL-OTHER', 'status' => 'ACTIVE', 'is_bookable' => true,
            'public_visible' => true, 'position' => 'Chuyên viên', 'experience_years' => 1, 'hired_at' => '2025-01-01']);
        BookingService::create(array_replace($base, ['staff_profile_id' => $otherStaff->id, 'sort_order' => 1, 'service_name_snapshot' => 'CAL-SERVICE-PRIVATE']));
        $assignment = $staffUser->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $this->actingAs($staffUser)->withSession(['workspace_assignment_id' => $assignment->id])
            ->get(route('bookings.calendar', ['date' => '2026-10-07', 'view' => 'day']))->assertOk()
            ->assertSee('data-calendar-booking="'.$visible->id.'"', false)->assertSee('CAL-SERVICE-OWN')
            ->assertDontSee('data-calendar-booking="'.$hidden->id.'"', false)->assertDontSee('CAL-SERVICE-PRIVATE')
            ->assertSee('09:15–09:45')->assertDontSee('09:00–10:00');
    }
}
