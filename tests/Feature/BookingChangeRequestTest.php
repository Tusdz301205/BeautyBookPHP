<?php

namespace Tests\Feature;

use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookingChangeRequestTest extends TestCase
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

    public function test_local_reschedule_form_uses_branch_timezone_and_preserves_duration(): void
    {
        $booking = $this->createCustomerBooking('change-local-form');
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $oldStart = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $oldEnd = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
        $local = $oldStart->addDay()->setTimezone($booking->branch->timezone);
        $this->actingAs($customer);
        $response = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'RESCHEDULE', 'proposed_local_start_at' => $local->format('Y-m-d\TH:i'),
        ])->assertCreated();
        $change = AppointmentChangeRequest::findOrFail($response->json('request_id'));
        $start = CarbonImmutable::parse($change->getRawOriginal('proposed_start_at'), 'UTC');
        $end = CarbonImmutable::parse($change->getRawOriginal('proposed_end_at'), 'UTC');
        $this->assertSame($local->utc()->format('Y-m-d H:i'), $start->format('Y-m-d H:i'));
        $this->assertSame($oldStart->diffInSeconds($oldEnd), $start->diffInSeconds($end));
    }

    public function test_customer_cancellation_request_is_rejected_at_and_after_appointment_start(): void
    {
        $booking = $this->createCustomerBooking('change-cancel-after-start');
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        $this->actingAs($customer);
        CarbonImmutable::setTestNow($start);
        $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
        ])->assertConflict();

        CarbonImmutable::setTestNow($start->addSecond());
        $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
        ])->assertConflict();

        $this->assertSame(0, AppointmentChangeRequest::query()->where('booking_id', $booking->id)->count());
        $this->assertDatabaseCount('booking_violation_events', 0);
    }

    public function test_rejected_request_cannot_be_rejected_or_approved_again(): void
    {
        $booking = $this->createCustomerBooking('change-reject-replay');
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        CarbonImmutable::setTestNow($start->subHours(5));
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'CANCEL',
            'reason' => 'Không thể đến',
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.reject', [$booking, $changeRequest]), [
            'review_note' => 'Giữ nguyên lịch hẹn',
        ])->assertOk()->assertJsonPath('status', 'REJECTED');

        $this->postJsonWithCsrf(route('bookings.change-requests.reject', [$booking, $changeRequest]), [])
            ->assertConflict();
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$booking, $changeRequest]), [])
            ->assertConflict();

        $fresh = $changeRequest->fresh();
        $this->assertSame('REJECTED', $fresh->status);
        $this->assertSame($owner->id, $fresh->reviewed_by_user_id);
        $this->assertSame('Giữ nguyên lịch hẹn', $fresh->review_note);
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
    }

    public function test_reschedule_approval_moves_booking_and_item_times_without_changing_duration(): void
    {
        $booking = $this->createCustomerBooking('change-reschedule-apply');
        $booking->update(['status' => 'CONFIRMED']);
        $item = $booking->services()->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $oldStart = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $oldEnd = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
        $oldItemStart = CarbonImmutable::parse($item->getRawOriginal('item_start_at'), 'UTC');
        $oldItemEnd = CarbonImmutable::parse($item->getRawOriginal('item_end_at'), 'UTC');
        $oldOccupiedStart = CarbonImmutable::parse($item->getRawOriginal('occupied_start_at'), 'UTC');
        $oldOccupiedEnd = CarbonImmutable::parse($item->getRawOriginal('occupied_end_at'), 'UTC');
        $oldRevision = (int) $item->revision;
        $newStart = $oldStart->addHours(3);
        $newEnd = $oldEnd->addHours(3);

        CarbonImmutable::setTestNow($oldStart->subHours(6));
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'RESCHEDULE',
            'proposed_start_at' => $newStart->toIso8601String(),
            'proposed_end_at' => $newEnd->toIso8601String(),
            'reason' => 'Xin dời lịch',
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$booking, $changeRequest]), [
            'review_note' => 'Đã kiểm tra lịch trống',
        ])->assertOk()
            ->assertJsonPath('status', 'APPROVED')
            ->assertJsonPath('booking_status', 'CONFIRMED');

        $freshBooking = $booking->fresh();
        $freshItem = $item->fresh();
        $this->assertTrue(CarbonImmutable::parse($freshBooking->getRawOriginal('appointment_start_at'), 'UTC')->equalTo($newStart));
        $this->assertTrue(CarbonImmutable::parse($freshBooking->getRawOriginal('appointment_end_at'), 'UTC')->equalTo($newEnd));
        $this->assertTrue(CarbonImmutable::parse($freshItem->getRawOriginal('item_start_at'), 'UTC')->equalTo($oldItemStart->addHours(3)));
        $this->assertTrue(CarbonImmutable::parse($freshItem->getRawOriginal('item_end_at'), 'UTC')->equalTo($oldItemEnd->addHours(3)));
        $this->assertTrue(CarbonImmutable::parse($freshItem->getRawOriginal('occupied_start_at'), 'UTC')->equalTo($oldOccupiedStart->addHours(3)));
        $this->assertTrue(CarbonImmutable::parse($freshItem->getRawOriginal('occupied_end_at'), 'UTC')->equalTo($oldOccupiedEnd->addHours(3)));
        $this->assertSame($oldRevision + 1, (int) $freshItem->revision);
    }

    public function test_reschedule_request_must_preserve_total_duration(): void
    {
        $booking = $this->createCustomerBooking('change-reschedule-duration');
        $booking->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $end = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');

        CarbonImmutable::setTestNow($start->subHours(6));
        $this->actingAs($customer);
        $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'RESCHEDULE',
            'proposed_start_at' => $start->addHours(3)->toIso8601String(),
            'proposed_end_at' => $end->addHours(3)->addMinute()->toIso8601String(),
        ])->assertConflict();

        $this->assertSame(0, AppointmentChangeRequest::query()->where('booking_id', $booking->id)->count());
    }

    public function test_reschedule_approval_rejects_customer_or_staff_overlap_and_keeps_request_pending(): void
    {
        $booking = $this->createCustomerBooking('change-reschedule-overlap-a', 10);
        $conflicting = $this->createCustomerBooking('change-reschedule-overlap-b', 14);
        $booking->update(['status' => 'CONFIRMED']);
        $conflicting->update(['status' => 'CONFIRMED']);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $oldStart = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $oldEnd = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
        $conflictingStart = CarbonImmutable::parse($conflicting->getRawOriginal('appointment_start_at'), 'UTC');
        $conflictingEnd = CarbonImmutable::parse($conflicting->getRawOriginal('appointment_end_at'), 'UTC');

        CarbonImmutable::setTestNow($oldStart->subHours(6));
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'RESCHEDULE',
            'proposed_start_at' => $conflictingStart->toIso8601String(),
            'proposed_end_at' => $conflictingEnd->toIso8601String(),
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$booking, $changeRequest]), [])
            ->assertConflict();

        $this->assertSame('PENDING', $changeRequest->fresh()->status);
        $this->assertTrue(CarbonImmutable::parse($booking->fresh()->getRawOriginal('appointment_start_at'), 'UTC')->equalTo($oldStart));
        $this->assertTrue(CarbonImmutable::parse($booking->fresh()->getRawOriginal('appointment_end_at'), 'UTC')->equalTo($oldEnd));
    }

    public function test_staff_change_approval_rejects_wrong_scope_and_missing_capability(): void
    {
        $scopeBooking = $this->createCustomerBooking('change-staff-scope', 10);
        $capabilityBooking = $this->createCustomerBooking('change-staff-capability', 14);
        $scopeBooking->update(['status' => 'CONFIRMED']);
        $capabilityBooking->update(['status' => 'CONFIRMED']);
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $otherBranch = Branch::where('business_id', $branch->business_id)->whereKeyNot($branch->id)->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $wrongScopeStaff = $this->createStaff($otherBranch, null, 'WRONG-SCOPE');
        $unqualifiedStaff = $this->createStaff($branch, null, 'NO-CAPABILITY');
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $scopeStart = CarbonImmutable::parse($scopeBooking->getRawOriginal('appointment_start_at'), 'UTC');

        CarbonImmutable::setTestNow($scopeStart->subHours(6));
        $this->actingAs($customer);
        $scopeCreated = $this->postJsonWithCsrf(route('bookings.change-requests.store', $scopeBooking), [
            'request_type' => 'STAFF_CHANGE',
            'proposed_staff_profile_id' => $wrongScopeStaff->id,
        ])->assertCreated();
        $scopeRequest = AppointmentChangeRequest::query()->findOrFail($scopeCreated->json('request_id'));

        $capabilityCreated = $this->postJsonWithCsrf(route('bookings.change-requests.store', $capabilityBooking), [
            'request_type' => 'STAFF_CHANGE',
            'proposed_staff_profile_id' => $unqualifiedStaff->id,
        ])->assertCreated();
        $capabilityRequest = AppointmentChangeRequest::query()->findOrFail($capabilityCreated->json('request_id'));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$scopeBooking, $scopeRequest]), [])
            ->assertConflict();
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$capabilityBooking, $capabilityRequest]), [])
            ->assertConflict();

        $this->assertSame('PENDING', $scopeRequest->fresh()->status);
        $this->assertSame('PENDING', $capabilityRequest->fresh()->status);
        $this->assertNotSame($wrongScopeStaff->id, $scopeBooking->services()->firstOrFail()->staff_profile_id);
        $this->assertNotSame($unqualifiedStaff->id, $capabilityBooking->services()->firstOrFail()->staff_profile_id);
        $this->assertSame($offering->id, $capabilityBooking->services()->firstOrFail()->branch_service_offering_id);
    }

    public function test_staff_change_approval_updates_staff_and_revision_when_candidate_is_qualified(): void
    {
        $booking = $this->createCustomerBooking('change-staff-success');
        $booking->update(['status' => 'CONFIRMED']);
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $candidate = $this->createStaff($branch, $offering, 'QUALIFIED');
        $item = $booking->services()->firstOrFail();
        $oldRevision = (int) $item->revision;
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $start = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');

        CarbonImmutable::setTestNow($start->subHours(6));
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $booking), [
            'request_type' => 'STAFF_CHANGE',
            'proposed_staff_profile_id' => $candidate->id,
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$booking, $changeRequest]), [])
            ->assertOk()
            ->assertJsonPath('status', 'APPROVED');

        $freshItem = $item->fresh();
        $this->assertSame($candidate->id, $freshItem->staff_profile_id);
        $this->assertSame($oldRevision + 1, (int) $freshItem->revision);
    }

    public function test_staff_change_approval_rejects_candidate_with_overlapping_booking(): void
    {
        $target = $this->createCustomerBooking('change-staff-overlap-target', 10);
        $target->update(['status' => 'CONFIRMED']);
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $candidate = $this->createStaff($branch, $offering, 'OVERLAP');
        $this->createOperationalBooking('change-staff-overlap-blocker', 10, $candidate);
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $ownerAssignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();
        $start = CarbonImmutable::parse($target->getRawOriginal('appointment_start_at'), 'UTC');
        $originalStaffId = $target->services()->firstOrFail()->staff_profile_id;

        CarbonImmutable::setTestNow($start->subHours(6));
        $this->actingAs($customer);
        $created = $this->postJsonWithCsrf(route('bookings.change-requests.store', $target), [
            'request_type' => 'STAFF_CHANGE',
            'proposed_staff_profile_id' => $candidate->id,
        ])->assertCreated();
        $changeRequest = AppointmentChangeRequest::query()->findOrFail($created->json('request_id'));

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $ownerAssignment->id]);
        $this->postJsonWithCsrf(route('bookings.change-requests.approve', [$target, $changeRequest]), [])
            ->assertConflict();

        $this->assertSame('PENDING', $changeRequest->fresh()->status);
        $this->assertSame($originalStaffId, $target->services()->firstOrFail()->staff_profile_id);
    }

    private function createCustomerBooking(string $idempotencyKey, int $hour = 10, ?StaffProfile $staff = null): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $staff ??= StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->firstOrFail();
        $customer = User::where('email', 'customer@beautybook.local')->firstOrFail();

        $this->actingAs($customer);
        $response = $this->postJsonWithCsrf(route('booking.store', $branch), [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $this->openStart($hour),
        ], $idempotencyKey)->assertCreated();

        return Booking::query()->findOrFail($response->json('booking_id'));
    }

    private function createOperationalBooking(string $idempotencyKey, int $hour, StaffProfile $staff): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $assignment = $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')->firstOrFail();

        $this->actingAs($owner)->withSession(['workspace_assignment_id' => $assignment->id]);
        $response = $this->postJsonWithCsrf(route('booking.store', $branch), [
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => null,
            'staff_profile_id' => $staff->id,
            'start_at' => $this->openStart($hour),
            'contact' => [
                'full_name' => 'Khách blocker',
                'phone' => '0909000888',
                'email' => null,
            ],
        ], $idempotencyKey)->assertCreated();

        return Booking::query()->findOrFail($response->json('booking_id'));
    }

    private function createStaff(Branch $branch, ?BranchServiceOffering $offering, string $suffix): StaffProfile
    {
        $staff = StaffProfile::query()->create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'user_id' => null,
            'full_name' => 'Test Staff '.$suffix,
            'position' => 'Chuyên viên',
            'employee_code' => 'TEST-'.$suffix,
            'experience_years' => 2,
            'public_visible' => true,
            'is_bookable' => true,
            'status' => 'ACTIVE',
            'hired_at' => '2025-01-01',
        ]);

        if ($offering !== null) {
            $staff->offerings()->attach($offering->id, [
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
            ]);
        }

        return $staff;
    }

    private function postJsonWithCsrf(string $uri, array $data, ?string $idempotencyKey = null): TestResponse
    {
        $token = 'beautybook-change-request-test-csrf';
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
