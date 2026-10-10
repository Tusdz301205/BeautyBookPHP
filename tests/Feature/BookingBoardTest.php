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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookingBoardTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 0;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_platform_admin_can_read_and_filter_platform_bookings_without_operational_controls(): void
    {
        $this->seed();
        $now = CarbonImmutable::now('UTC');
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $assignment = $admin->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $profile = CustomerProfile::where('user_id', $customer->id)->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $other = Branch::where('business_id', '!=', $branch->business_id)->firstOrFail();
        $first = $this->createBooking($branch, $profile, 'CONFIRMED', $now->subHour(), $now->addHour(), $customer);
        $second = $this->createBooking($other, $profile, 'PENDING', $now->addDay(), $now->addDay()->addHour(), $customer);
        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->get(route('bookings.index'))->assertOk()->assertSee($first->booking_code)->assertSee($second->booking_code)
            ->assertDontSee('data-booking-transition', false)->assertDontSee('data-booking-change-form', false);
        $this->get(route('bookings.index', ['business_id' => $branch->business_id]))->assertOk()
            ->assertSee($first->booking_code)->assertDontSee($second->booking_code);
        $this->postJsonWithCsrf(route('bookings.transition', $first), ['status' => 'NO_SHOW', 'confirmed_no_show' => true])->assertForbidden();
        $this->assertSame('CONFIRMED', $first->fresh()->status);
    }

    public function test_customer_board_only_shows_own_bookings_and_exposes_direct_and_late_cancel_controls(): void
    {
        $this->seed();
        $now = CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $customerProfile = CustomerProfile::where('user_id', $customer->id)->firstOrFail();
        $otherProfile = CustomerProfile::whereKeyNot($customerProfile->id)->firstOrFail();
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();

        $direct = $this->createBooking($branch, $customerProfile, 'PENDING', $now->addHours(6), $now->addHours(7), $customer);
        $late = $this->createBooking($branch, $customerProfile, 'CONFIRMED', $now->addHours(2), $now->addHours(3), $customer);
        $other = $this->createBooking($branch, $otherProfile, 'PENDING', $now->addHours(8), $now->addHours(9), $customer);

        $response = $this->actingAs($customer)->get(route('bookings.index'));

        $response->assertOk()
            ->assertSee($direct->booking_code)
            ->assertSee($late->booking_code)
            ->assertDontSee($other->booking_code)
            ->assertSee('data-status="CANCELLED"', false)
            ->assertSee('data-request-type="CANCEL"', false)
            ->assertSee('Gửi yêu cầu hủy muộn');
    }

    public function test_owner_sees_business_scope_while_receptionist_is_limited_to_exact_branch(): void
    {
        $this->seed();
        $now = CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $customerProfile = CustomerProfile::query()->firstOrFail();
        $tanTao = Branch::where('slug', 'tan-tao')->firstOrFail();
        $sameBusinessBranch = Branch::where('business_id', $tanTao->business_id)->whereKeyNot($tanTao->id)->firstOrFail();
        $otherBusinessBranch = Branch::where('business_id', '!=', $tanTao->business_id)->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();

        $local = $this->createBooking($tanTao, $customerProfile, 'PENDING', $now->addHours(5), $now->addHours(6), $owner);
        $sameBusiness = $this->createBooking($sameBusinessBranch, $customerProfile, 'PENDING', $now->addHours(7), $now->addHours(8), $owner);
        $foreignBusiness = $this->createBooking($otherBusinessBranch, $customerProfile, 'PENDING', $now->addHours(9), $now->addHours(10), $owner);

        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $this->actingAs($owner)
            ->withSession(['workspace_assignment_id' => $ownerAssignment->id])
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($local->booking_code)
            ->assertSee($sameBusiness->booking_code)
            ->assertDontSee($foreignBusiness->booking_code);

        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $receptionistAssignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $this->actingAs($receptionist)
            ->withSession(['workspace_assignment_id' => $receptionistAssignment->id])
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($local->booking_code)
            ->assertDontSee($sameBusiness->booking_code)
            ->assertDontSee($foreignBusiness->booking_code)
            ->assertSee('Xác nhận');
    }

    public function test_staff_board_only_shows_assigned_bookings_and_assigned_items(): void
    {
        $this->seed();
        $now = CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $staffUser = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staffProfile = StaffProfile::where('user_id', $staffUser->id)->firstOrFail();
        $branch = $staffProfile->branch()->firstOrFail();
        $customerProfile = CustomerProfile::query()->firstOrFail();
        $otherStaff = $this->createUnlinkedStaff($branch, 'BOARD-OTHER');

        $visibleBooking = $this->createBooking($branch, $customerProfile, 'CHECKED_IN', $now, $now->addHour(), $staffUser);
        $this->createItem($visibleBooking, $staffProfile, $now, $now->addMinutes(30), 'Dịch vụ của tôi');
        $this->createItem($visibleBooking, $otherStaff, $now, $now->addMinutes(30), 'Dịch vụ người khác');

        $hiddenBooking = $this->createBooking($branch, $customerProfile, 'CHECKED_IN', $now->addHours(2), $now->addHours(3), $staffUser);
        $this->createItem($hiddenBooking, $otherStaff, $now->addHours(2), $now->addHours(3), 'Lịch không được phân công');

        $assignment = $staffUser->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $this->actingAs($staffUser)
            ->withSession(['workspace_assignment_id' => $assignment->id])
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($visibleBooking->booking_code)
            ->assertDontSee($hiddenBooking->booking_code)
            ->assertSee('Dịch vụ của tôi')
            ->assertDontSee('Dịch vụ người khác')
            ->assertSee('Bắt đầu dịch vụ')
            ->assertDontSee('Xác nhận');
    }

    public function test_assigned_staff_can_start_and_complete_item_at_exact_boundaries(): void
    {
        $this->seed();
        $start = CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC');
        CarbonImmutable::setTestNow($start);

        $staffUser = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staffProfile = StaffProfile::where('user_id', $staffUser->id)->firstOrFail();
        $branch = $staffProfile->branch()->firstOrFail();
        $customerProfile = CustomerProfile::query()->firstOrFail();
        $booking = $this->createBooking($branch, $customerProfile, 'CHECKED_IN', $start, $start->addHour(), $staffUser);
        $item = $this->createItem($booking, $staffProfile, $start, $start->addHour(), 'Boundary item');
        $assignment = $staffUser->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();

        $this->actingAs($staffUser)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('bookings.items.transition', [$booking, $item]), [
            'action' => 'START',
            'expected_revision' => 1,
        ])->assertOk()
            ->assertJsonPath('booking_status', 'IN_PROGRESS')
            ->assertJsonPath('item_status', 'IN_PROGRESS')
            ->assertJsonPath('revision', 2);

        $this->assertSame('IN_PROGRESS', $booking->fresh()->status);
        $this->assertSame('IN_PROGRESS', $item->fresh()->status);
        $this->assertSame(2, $item->fresh()->revision);

        CarbonImmutable::setTestNow($start->addHour());
        $this->postJsonWithCsrf(route('bookings.items.transition', [$booking, $item]), [
            'action' => 'COMPLETE',
            'expected_revision' => 2,
        ])->assertOk()
            ->assertJsonPath('booking_status', 'COMPLETED')
            ->assertJsonPath('item_status', 'COMPLETED')
            ->assertJsonPath('revision', 3);

        $this->assertSame('COMPLETED', $booking->fresh()->status);
        $this->assertSame('COMPLETED', $item->fresh()->status);
        $this->assertSame(3, $item->fresh()->revision);
    }

    public function test_item_transition_rejects_unassigned_staff_too_early_start_and_stale_revision(): void
    {
        $this->seed();
        $start = CarbonImmutable::parse('2026-09-24 03:00:00', 'UTC');

        $staffUser = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staffProfile = StaffProfile::where('user_id', $staffUser->id)->firstOrFail();
        $branch = $staffProfile->branch()->firstOrFail();
        $customerProfile = CustomerProfile::query()->firstOrFail();
        $assignment = $staffUser->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $otherStaff = $this->createUnlinkedStaff($branch, 'BOARD-DENIED');

        $unassignedBooking = $this->createBooking($branch, $customerProfile, 'CHECKED_IN', $start, $start->addHour(), $staffUser);
        $unassignedItem = $this->createItem($unassignedBooking, $otherStaff, $start, $start->addHour(), 'Unassigned item');

        CarbonImmutable::setTestNow($start);
        $this->actingAs($staffUser)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('bookings.items.transition', [$unassignedBooking, $unassignedItem]), [
            'action' => 'START',
            'expected_revision' => 1,
        ])->assertForbidden();

        $booking = $this->createBooking($branch, $customerProfile, 'CHECKED_IN', $start, $start->addHour(), $staffUser);
        $item = $this->createItem($booking, $staffProfile, $start, $start->addHour(), 'Protected item');

        CarbonImmutable::setTestNow($start->subMinute());
        $this->postJsonWithCsrf(route('bookings.items.transition', [$booking, $item]), [
            'action' => 'START',
            'expected_revision' => 1,
        ])->assertConflict();

        $this->assertSame('CHECKED_IN', $booking->fresh()->status);
        $this->assertSame('SCHEDULED', $item->fresh()->status);
        $this->assertSame(1, $item->fresh()->revision);

        CarbonImmutable::setTestNow($start);
        $this->postJsonWithCsrf(route('bookings.items.transition', [$booking, $item]), [
            'action' => 'START',
            'expected_revision' => 99,
        ])->assertConflict();

        $this->assertSame('CHECKED_IN', $booking->fresh()->status);
        $this->assertSame('SCHEDULED', $item->fresh()->status);
        $this->assertSame(1, $item->fresh()->revision);
    }

    private function createBooking(
        Branch $branch,
        CustomerProfile $customerProfile,
        string $status,
        CarbonImmutable $start,
        CarbonImmutable $end,
        User $creator,
    ): Booking {
        $this->bookingSequence++;

        return Booking::query()->create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'customer_profile_id' => $customerProfile->id,
            'created_by_user_id' => $creator->id,
            'booking_code' => 'BOARD-TEST-'.$this->bookingSequence,
            'appointment_start_at' => $start,
            'appointment_end_at' => $end,
            'status' => $status,
            'source' => 'ONLINE_WEB',
            'currency' => 'VND',
            'total_amount' => 250000,
        ]);
    }

    private function createItem(
        Booking $booking,
        StaffProfile $staff,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $snapshot,
    ): BookingService {
        $offering = BranchServiceOffering::query()
            ->where('business_id', $booking->business_id)
            ->where('branch_id', $booking->branch_id)
            ->with('service')
            ->orderBy('id')
            ->firstOrFail();

        return BookingService::query()->create([
            'booking_id' => $booking->id,
            'business_id' => $booking->business_id,
            'branch_id' => $booking->branch_id,
            'branch_service_offering_id' => $offering->id,
            'business_service_id' => $offering->business_service_id,
            'canonical_service_id' => $offering->service->canonical_service_id,
            'staff_profile_id' => $staff->id,
            'service_variant_id' => null,
            'price_at_booking' => $offering->price,
            'currency' => 'VND',
            'duration_minutes' => max(1, $start->diffInMinutes($end)),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'service_name_snapshot' => $snapshot,
            'variant_name_snapshot' => null,
            'sort_order' => 0,
            'status' => 'SCHEDULED',
            'item_start_at' => $start,
            'item_end_at' => $end,
            'occupied_start_at' => $start,
            'occupied_end_at' => $end,
            'revision' => 1,
        ]);
    }

    private function createUnlinkedStaff(Branch $branch, string $employeeCode): StaffProfile
    {
        return StaffProfile::query()->create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'user_id' => null,
            'full_name' => 'Nhân viên test '.$employeeCode,
            'position' => 'Chuyên viên',
            'employee_code' => $employeeCode,
            'experience_years' => 2,
            'public_visible' => true,
            'is_bookable' => true,
            'status' => 'ACTIVE',
            'hired_at' => '2025-01-01',
        ]);
    }

    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        $token = 'beautybook-booking-board-test-csrf';

        return $this->withSession(['_token' => $token])
            ->withHeaders(['X-CSRF-TOKEN' => $token])
            ->postJson($uri, $data);
    }
}
