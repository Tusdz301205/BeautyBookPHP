<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Scheduling\ComboService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_weekly_hours_merge_touching_shifts_warn_and_preserve_existing_booking(): void
    {
        [$staff, $payload, $at] = $this->appointment();
        $booking = $this->book($payload);
        $originalItem = $booking->services()->firstOrFail()->getAttributes();
        $hours = "/api/v1/staff/{$staff->id}/working-hours";
        $this->actor('owner');
        $this->getJson($hours.'/manage')->assertOk()->assertExactJson(['configured' => false, 'days' => []]);

        $week = $this->week($at->dayOfWeek, [['13:00', '24:00'], ['09:00', '10:00'], ['12:00', '13:00']]);
        $this->putJson($hours, $week)->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath("days.{$at->dayOfWeek}.intervals.1", ['startTime' => '12:00', 'endTime' => '24:00'])
            ->assertJsonPath('conflictCount', 1)
            ->assertJsonPath('conflicts.0.bookingId', (string) $booking->id)
            ->assertJsonPath('existingBookingsPreserved', true);

        $overlap = $this->week($at->dayOfWeek, [['09:00', '12:00'], ['11:00', '14:00']]);
        $this->putJson($hours, $overlap)->assertUnprocessable();
        $this->getJson($hours)->assertOk()->assertJsonPath("days.{$at->dayOfWeek}.intervals.0.endTime", '10:00');
        $this->assertSame($booking->status, $booking->fresh()->status);
        $this->assertSame($originalItem, $booking->services()->firstOrFail()->getAttributes());
        $this->assertDatabaseHas('audit_logs', ['action' => 'STAFF_HOURS_UPDATE', 'entity_id' => (string) $staff->id]);
    }

    public function test_time_off_crud_uses_half_open_millisecond_windows_and_private_reasons(): void
    {
        [$staff, $payload, $at] = $this->appointment();
        $booking = $this->book($payload);
        $originalItem = $booking->services()->firstOrFail()->getAttributes();
        $this->actor('owner');
        $path = "/api/v1/staff/{$staff->id}/time-off";
        $boundary = $at->addMinutes(10);
        $body = ['startsAt' => $at->format('Y-m-d\TH:i:s.vP'),
            'endsAt' => $boundary->format('Y-m-d\TH:i:s.vP'), 'reason' => 'Lý do riêng'];
        $this->postJson($path, ['startsAt' => $at->format('Y-m-d\TH:i:s')] + $body)->assertUnprocessable();
        $this->postJson($path, ['startsAt' => '2030-02-30T09:00:00+07:00'] + $body)->assertUnprocessable();
        $absence = $this->postJson($path, $body)->assertCreated()
            ->assertJsonPath('conflictCount', 1)->assertJsonPath('conflicts.0.bookingId', (string) $booking->id)
            ->assertJsonPath('existingBookingsPreserved', true)->json();
        $this->assertSame($at->utc()->format('Y-m-d\TH:i:s.v\Z'), $absence['startsAt']);

        $adjacent = ['startsAt' => $body['endsAt'], 'endsAt' => $boundary->addMinute()->format('Y-m-d\TH:i:s.vP')];
        $next = $this->postJson($path, $adjacent)->assertCreated()->json('id');
        $this->putJson($path.'/'.$absence['id'], ['endsAt' => $boundary->addMillisecond()->format('Y-m-d\TH:i:s.vP')] + $body)->assertConflict();
        $range = http_build_query(['from' => $adjacent['startsAt'], 'to' => $adjacent['endsAt'], 'limit' => 1]);
        $this->getJson($path.'?'.$range)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $next);
        $privateRange = http_build_query(['from' => $body['startsAt'], 'to' => $body['endsAt']]);
        $publicItem = $this->getJson($path.'?'.$privateRange)->assertOk()->json('items.0');
        $this->assertArrayNotHasKey('reason', $publicItem);
        $this->assertArrayNotHasKey('recordedById', $publicItem);
        $this->getJson($path.'/manage?'.$privateRange)->assertOk()->assertJsonPath('items.0.reason', 'Lý do riêng');

        $other = StaffProfile::where('business_id', $staff->business_id)->whereKeyNot($staff->id)->firstOrFail();
        $this->putJson("/api/v1/staff/{$other->id}/time-off/{$absence['id']}", $body)->assertNotFound();
        $this->deleteJson("/api/v1/staff/{$other->id}/time-off/{$absence['id']}")->assertOk()->assertJsonPath('removed', false);
        $this->putJson($path.'/'.$absence['id'], ['reason' => 'Đã đổi lý do'] + $body)->assertOk()
            ->assertJsonPath('reason', 'Đã đổi lý do')->assertJsonPath('recordedById', $absence['recordedById']);
        $this->deleteJson($path.'/'.$absence['id'])->assertOk()->assertJsonPath('removed', true);
        $this->deleteJson($path.'/'.$absence['id'])->assertOk()->assertJsonPath('removed', false);
        $this->assertSame($booking->status, $booking->fresh()->status);
        $this->assertSame($originalItem, $booking->services()->firstOrFail()->getAttributes());
    }

    public function test_calendar_access_uses_live_owner_and_receptionist_branch_grants(): void
    {
        $receptionist = $this->actor('receptionist');
        $assignment = $receptionist->roleAssignments()->where('role_name', 'RECEPTIONIST')->firstOrFail();
        $local = StaffProfile::where('branch_id', $assignment->branch_id)->firstOrFail();
        $sibling = StaffProfile::where('business_id', $assignment->business_id)->where('branch_id', '<>', $assignment->branch_id)->firstOrFail();
        $path = "/api/v1/staff/{$local->id}";
        $this->getJson($path.'/working-hours/manage')->assertOk();
        $this->getJson("/api/v1/staff/{$sibling->id}/working-hours/manage")->assertForbidden();
        $this->putJson($path.'/working-hours', $this->week(1, [['09:00', '12:00']]))->assertForbidden();
        $this->postJson($path.'/time-off', [])->assertForbidden();
        $this->actor('owner2');
        $this->getJson($path.'/working-hours/manage')->assertForbidden();
        $this->putJson($path.'/working-hours', [])->assertForbidden();
        $this->actor('owner');
        $this->putJson($path.'/working-hours', $this->week(1, [['09:00', '12:00']]))->assertOk();
        $local->update(['public_visible' => false]);
        $this->getJson($path.'/working-hours')->assertNotFound();
    }

    public function test_slot_and_single_booking_use_current_shifts_and_time_off(): void
    {
        [$staff, $payload, $at] = $this->appointment();
        $slot = ['start' => $payload['appointmentDate']];
        $slots = '/api/v1/bookings/available-slots?'.http_build_query($payload + ['date' => $at->toDateString()]);
        $calendar = "/api/v1/staff/{$staff->id}";
        $this->actor('customer');
        $this->getJson($slots)->assertOk()->assertJsonFragment($slot);

        $this->actor('owner');
        $absence = $this->postJson($calendar.'/time-off', [
            'startsAt' => $at->toIso8601String(), 'endsAt' => $at->addMinutes(15)->toIso8601String(),
        ])->assertCreated()->json('id');
        $this->actor('customer');
        $this->getJson($slots)->assertOk()->assertJsonMissing($slot);
        $this->withHeader('Idempotency-Key', 'stale-time-off')->postJson('/api/v1/bookings', $payload)->assertUnprocessable()
            ->assertJsonPath('message', 'Nhân viên nghỉ trong khung giờ này.');

        $this->actor('owner');
        $this->deleteJson($calendar.'/time-off/'.$absence)->assertOk();
        $this->actor('customer');
        $this->getJson($slots)->assertOk()->assertJsonFragment($slot);

        $this->actor('owner');
        $this->putJson($calendar.'/working-hours', $this->week($at->dayOfWeek, [['09:00', '10:00'], ['13:00', '18:00']]))->assertOk();
        $this->actor('customer');
        $this->getJson($slots)->assertOk()->assertJsonMissing($slot);
        $this->withHeader('Idempotency-Key', 'stale-weekly-shift')->postJson('/api/v1/bookings', $payload)->assertUnprocessable()
            ->assertJsonPath('message', 'Khung giờ nằm ngoài ca làm của nhân viên.');
        $this->assertDatabaseCount('bookings', 0);

        $this->actor('owner');
        $this->putJson($calendar.'/working-hours', $this->week($at->dayOfWeek, [['09:00', '18:00']]))->assertOk();
        $this->actor('customer');
        $this->getJson($slots)->assertOk()->assertJsonFragment($slot);
        $this->book($payload);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_review_rejects_time_off_added_after_the_reschedule_request(): void
    {
        [$staff, $payload, $at] = $this->appointment();
        $booking = $this->book($payload);
        $originalStart = $booking->getRawOriginal('appointment_start_at');
        $shiftedStart = $at->addHours(3);
        $shiftedEnd = $booking->appointment_end_at->copy()->addHours(3);
        $request = $this->postJson("/api/v1/bookings/{$booking->id}/change-requests", [
            'requestType' => 'RESCHEDULE', 'proposedStartTime' => $shiftedStart->toIso8601String(),
            'proposedEndTime' => $shiftedEnd->toIso8601String(),
        ])->assertOk()->json('id');

        $this->actor('owner');
        $path = "/api/v1/staff/{$staff->id}/time-off";
        $absence = $this->postJson($path, [
            'startsAt' => $shiftedStart->toIso8601String(), 'endsAt' => $shiftedEnd->toIso8601String(),
        ])->assertCreated()->json('id');
        $review = "/api/v1/bookings/change-requests/{$request}/approve";
        $this->patchJson($review, [])->assertConflict()->assertJsonPath('message', 'Nhân viên nghỉ trong khung giờ này.');
        $this->assertSame($originalStart, $booking->fresh()->getRawOriginal('appointment_start_at'));
        $this->assertDatabaseHas('appointment_change_requests', ['id' => $request, 'status' => 'PENDING']);

        $this->deleteJson($path.'/'.$absence)->assertOk();
        $this->patchJson($review, [])->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertSame($shiftedStart->utc()->getTimestamp(), $booking->fresh()->appointment_start_at->getTimestamp());
    }

    public function test_combo_and_recurring_writes_respect_employee_time_off(): void
    {
        [$staff, $payload, $at] = $this->appointment();
        $branch = $staff->branch;
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->where('bookable', true)->orderBy('id')->take(2)->get();
        foreach ($offerings as $offering) {
            $staff->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $branch->business_id, 'branch_id' => $branch->id]]);
        }
        $combo = app(ComboService::class)->save($branch, [
            'name' => 'Combo thử lịch nhân viên', 'combo_price' => '200000.00', 'status' => 'ACTIVE',
            'pricing_mode' => 'FIXED_PRICE', 'staff_assignment_mode' => 'SINGLE_PROVIDER',
            'items' => $offerings->map(fn ($offering) => ['branch_service_offering_id' => $offering->id,
                'quantity' => 1, 'transition_minutes' => 0])->all(),
        ]);
        $this->actor('owner');
        $this->postJson("/api/v1/staff/{$staff->id}/time-off", [
            'startsAt' => $at->toIso8601String(), 'endsAt' => $at->addMinutes(15)->toIso8601String(),
        ])->assertCreated();

        $this->actor('customer');
        $this->withHeader('Idempotency-Key', 'calendar-combo-block')->postJson('/api/v1/bookings', $payload + ['comboId' => $combo->id])
            ->assertUnprocessable()->assertJsonPath('message', 'Nhân viên nghỉ trong khung giờ này.');
        $recurring = $payload + ['staffMode' => 'SAME_STAFF', 'frequency' => 'WEEKLY',
            'startDate' => $at->toDateString(), 'preferredTime' => $at->format('H:i'),
            'occurrenceCount' => 2, 'skipConflicts' => false];
        $this->postJson('/api/v1/recurring/preview', $recurring)->assertOk()
            ->assertJsonPath('conflictCount', 1)->assertJsonPath('occurrences.0.available', false)
            ->assertJsonPath('occurrences.1.available', true);
        $this->withHeader('Idempotency-Key', 'calendar-recurring-block')->postJson('/api/v1/recurring', $recurring)->assertConflict();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('recurring_booking_series', 0);
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $assignment = $user->roleAssignments()->firstOrFail();
        $token = 'staff-calendar-test';
        $this->actingAs($user)->withHeader('Authorization', 'Bearer '.$token)->withSession([
            'workspace_assignment_id' => $assignment->id,
            'beautybook_access_hash' => hash('sha256', $token),
            'beautybook_access_expires' => time() + 3600,
        ]);

        return $user;
    }

    private function appointment(): array
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $staff = StaffProfile::publicBookable()->where('branch_id', $branch->id)->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->where('bookable', true)->firstOrFail();
        $staff->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $branch->business_id, 'branch_id' => $branch->id]]);
        $at = CarbonImmutable::now($branch->timezone)->addDays(8)->setTime(10, 30);
        if ($at->isSunday()) {
            $at = $at->addDay();
        }

        return [$staff, ['branchId' => (string) $branch->id, 'serviceIds' => [(string) $offering->id],
            'staffId' => (string) $staff->id, 'appointmentDate' => $at->toIso8601String(),
            'source' => 'ONLINE_WEB', 'customerName' => 'Khách thử lịch', 'customerPhone' => '0901234567', 'contactConsent' => true], $at];
    }

    private function book(array $payload): Booking
    {
        $this->actor('customer');
        $id = $this->withHeader('Idempotency-Key', 'staff-calendar-booking')->postJson('/api/v1/bookings', $payload)->assertCreated()->json('id');

        return Booking::findOrFail($id);
    }

    private function week(int $weekday, array $intervals): array
    {
        return ['days' => array_map(fn ($day) => ['dayOfWeek' => $day, 'isOff' => $day !== $weekday,
            'intervals' => $day === $weekday ? array_map(fn ($times) => ['startTime' => $times[0], 'endTime' => $times[1]], $intervals) : []],
            range(0, 6))];
    }
}
