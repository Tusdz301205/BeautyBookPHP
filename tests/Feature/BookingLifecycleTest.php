<?php

namespace Tests\Feature;

use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\BookingViolationEvent;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_customer_direct_cancel_is_allowed_at_exactly_four_hours(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-direct-four-hours');
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        CarbonImmutable::setTestNow($start->subHours(4));

        $this->actingAs($customer);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CANCELLED'])
            ->assertOk()
            ->assertJsonPath('status', 'CANCELLED');

        $this->assertSame('CANCELLED', $booking->fresh()->status);
        $this->assertSame('CANCELLED', $booking->services()->firstOrFail()->status);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'from_status' => 'PENDING',
            'to_status' => 'CANCELLED',
            'actor_user_id' => $customer->id,
        ]);
    }

    public function test_late_customer_cancel_requires_request_and_records_violation_at_request_time_once(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-late-cancel');
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        CarbonImmutable::setTestNow($start->subHours(3)->subMinutes(59));

        $this->actingAs($customer);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CANCELLED'])
            ->assertConflict();

        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
            'reason' => 'Không thể đến đúng giờ',
        ])->assertCreated();

        $request = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));
        $event = BookingViolationEvent::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->assertSame('LATE_CANCELLATION', $event->kind);
        $this->assertSame($request->id, $event->source_request_id);
        $this->assertSame(
            CarbonImmutable::parse($request->getRawOriginal('created_at'), 'UTC')->format('Y-m-d H:i:s.u'),
            CarbonImmutable::parse($event->getRawOriginal('occurred_at'), 'UTC')->format('Y-m-d H:i:s.u'),
        );

        $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
        ])->assertConflict();
        $this->assertSame(1, BookingViolationEvent::query()->where('booking_id', $booking->id)->count());
    }

    public function test_no_show_is_strictly_after_fifteen_minutes_and_records_violation(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-no-show-boundary');
        $booking->update(['status' => 'CONFIRMED']);
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        CarbonImmutable::setTestNow($start->addMinutes(15));
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), [
            'status' => 'NO_SHOW',
            'confirmed_no_show' => true,
        ])->assertConflict();

        CarbonImmutable::setTestNow($start->addMinutes(15)->addSecond());
        $this->postJsonWithCsrf(route('bookings.transition', $booking), [
            'status' => 'NO_SHOW',
            'confirmed_no_show' => true,
        ])->assertOk()->assertJsonPath('status', 'NO_SHOW');

        $this->assertDatabaseHas('booking_violation_events', [
            'booking_id' => $booking->id,
            'kind' => 'NO_SHOW',
            'source_request_id' => null,
        ]);
        $this->assertSame('CANCELLED', $booking->services()->firstOrFail()->status);
    }

    public function test_cancellation_request_evidence_blocks_no_show_and_can_be_approved(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-cancel-request-approval');
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        CarbonImmutable::setTestNow($start->subHours(5));
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
            'reason' => 'Xin hủy lịch',
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));

        CarbonImmutable::setTestNow($start->addMinutes(16));
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), [
            'status' => 'NO_SHOW',
            'confirmed_no_show' => true,
        ])->assertConflict();

        CarbonImmutable::setTestNow($start->subHours(4)->subMinute());
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$booking, $changeRequest]), [
            'review_note' => 'Đã duyệt yêu cầu hủy',
        ])->assertOk()
            ->assertJsonPath('status', 'APPROVED')
            ->assertJsonPath('booking_status', 'CANCELLED');

        $this->assertSame('CANCELLED', $booking->fresh()->status);
        $this->assertSame('APPROVED', $changeRequest->fresh()->status);
        $this->assertSame(0, BookingViolationEvent::query()->where('booking_id', $booking->id)->count());
    }

    public function test_expired_change_request_cannot_be_approved_and_expiration_persists(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-expired-request');
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        $createdAt = $start->subHours(5);
        CarbonImmutable::setTestNow($createdAt);
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
            'reason' => 'Xin hủy lịch',
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));
        $changeRequest->update(['expires_at' => $createdAt->addMinute()]);

        CarbonImmutable::setTestNow($createdAt->addMinutes(2));
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$booking, $changeRequest]), [])
            ->assertConflict();

        $this->assertSame('EXPIRED', $changeRequest->fresh()->status);
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
    }

    public function test_receptionist_and_staff_follow_checkin_start_and_completion_time_gates(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-role-time-gates');
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $receptionistAssignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $staff = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staffAssignment = $staff->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $end = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');

        $this->actingAs($receptionist)->withSession(['workspace_assignment_id' => $receptionistAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CONFIRMED'])
            ->assertOk()
            ->assertJsonPath('status', 'CONFIRMED');

        CarbonImmutable::setTestNow($start->subMinutes(31));
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CHECKED_IN'])
            ->assertConflict();

        CarbonImmutable::setTestNow($start->subMinutes(30));
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CHECKED_IN'])
            ->assertOk()
            ->assertJsonPath('status', 'CHECKED_IN');

        $this->actingAs($staff)->withSession(['workspace_assignment_id' => $staffAssignment->id]);
        CarbonImmutable::setTestNow($start->subSecond());
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'IN_PROGRESS'])
            ->assertConflict();

        CarbonImmutable::setTestNow($start);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'IN_PROGRESS'])
            ->assertOk()
            ->assertJsonPath('status', 'IN_PROGRESS');

        CarbonImmutable::setTestNow($end->subSecond());
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'COMPLETED'])
            ->assertConflict();

        CarbonImmutable::setTestNow($end);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'COMPLETED'])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');

        $this->assertSame('COMPLETED', $booking->fresh()->status);
    }

    public function test_staff_cannot_confirm_pending_and_admin_cannot_mark_no_show(): void
    {
        $booking = $this->createCustomerBooking('lifecycle-role-denials');
        $staff = User::where('email', 'staff@beautybook.local')->firstOrFail();
        $staffAssignment = $staff->roleAssignments()->where('role_name', 'STAFF')->firstOrFail();

        $this->actingAs($staff)->withSession(['workspace_assignment_id' => $staffAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), ['status' => 'CONFIRMED'])
            ->assertForbidden();

        $booking->update(['status' => 'CONFIRMED']);
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        $adminAssignment = $admin->roleAssignments()->where('role_name', 'PLATFORM_ADMIN')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        CarbonImmutable::setTestNow($start->addMinutes(16));

        $this->actingAs($admin)->withSession(['workspace_assignment_id' => $adminAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $booking), [
            'status' => 'NO_SHOW',
            'confirmed_no_show' => true,
        ])->assertForbidden();

        $this->assertSame('CONFIRMED', $booking->fresh()->status);
        $this->assertSame(0, BookingViolationEvent::query()->where('booking_id', $booking->id)->count());
    }

    public function test_arrival_history_and_service_progress_each_block_no_show(): void
    {
        $arrivalBooking = $this->createCustomerBooking('lifecycle-no-show-arrival-history', 10);
        $arrivalBooking->update(['status' => 'CONFIRMED']);
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        BookingStatusHistory::query()->create([
            'booking_id' => $arrivalBooking->id,
            'actor_user_id' => $owner->id,
            'from_status' => 'CONFIRMED',
            'to_status' => 'CHECKED_IN',
            'reason' => 'Arrival evidence',
        ]);

        $arrivalStart = CarbonImmutable::parse($arrivalBooking->getRawOriginal('appointment_start_at'), 'UTC');
        CarbonImmutable::setTestNow($arrivalStart->addMinutes(16));
        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $arrivalBooking), [
            'status' => 'NO_SHOW',
            'confirmed_no_show' => true,
        ])->assertConflict();

        CarbonImmutable::setTestNow();
        $progressBooking = $this->createCustomerBooking('lifecycle-no-show-service-progress', 14);
        $progressBooking->update(['status' => 'CONFIRMED']);
        $progressBooking->services()->update(['status' => 'IN_PROGRESS']);
        $progressStart = CarbonImmutable::parse($progressBooking->getRawOriginal('appointment_start_at'), 'UTC');
        CarbonImmutable::setTestNow($progressStart->addMinutes(16));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.transition', $progressBooking), [
            'status' => 'NO_SHOW',
            'confirmed_no_show' => true,
        ])->assertConflict();

        $this->assertSame('CONFIRMED', $arrivalBooking->fresh()->status);
        $this->assertSame('CONFIRMED', $progressBooking->fresh()->status);
        $this->assertSame(0, BookingViolationEvent::query()->whereIn('booking_id', [$arrivalBooking->id, $progressBooking->id])->count());
    }

    private function createCustomerBooking(string $idempotencyKey, int $hour = 10): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = $this->openStart($hour);

        $this->actingAs($customer);
        $response = $this->postJsonWithCsrf(route('booking.store', $branch), [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $start,
        ], $idempotencyKey)->assertCreated();

        return Booking::query()->findOrFail($response->json('booking_id'));
    }

    private function postJsonWithCsrf(string $uri, array $data, ?string $idempotencyKey = null): TestResponse
    {
        $token = 'beautybook-lifecycle-test-csrf';
        $headers = ['X-CSRF-TOKEN' => $token];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withSession(['_token' => $token])->withHeaders($headers)->postJson($uri, $data);
    }

    private function openStart(int $hour): string
    {
        $time = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTime($hour, 0);
        while ($time->dayOfWeek === 0) {
            $time = $time->addDay();
        }

        return $time->format('Y-m-d\TH:i');
    }
}
