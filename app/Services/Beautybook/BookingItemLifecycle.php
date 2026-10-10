<?php

namespace App\Services\Beautybook;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingLifecycleAccessService;
use App\Services\Booking\BookingLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Source item completion records execution; completing the appointment is a separate action. */
class BookingItemLifecycle
{
    public function __construct(private BookingLifecycleAccessService $access, private BookingLifecycleService $lifecycle, private AvailabilityService $availability) {}

    public function transition(Request $request, Booking $booking, int $itemId, array $data): Booking
    {
        return DB::transaction(function () use ($request, $booking, $itemId, $data) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $assignment = app(ApiScope::class)->forRoles($request, ['BUSINESS_OWNER', 'STAFF'], $booking->business_id, $booking->branch_id);
            $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id', $request->user()->id)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->lockForUpdate()->firstOrFail();
            // Reuse the audited lifecycle with a matching grant without changing the authenticated session's selection.
            $executionRequest = clone $request;
            $executionSession = clone $request->session();
            $executionSession->put('workspace_assignment_id', $assignment->id);
            $executionRequest->setLaravelSession($executionSession);
            $context = $this->access->resolve($executionRequest, $booking);
            $this->access->assertCurrent($booking, $context);
            abort_unless(in_array($context['kind'], ['OWNER', 'STAFF'], true), 403);
            $item = $booking->services()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if ($context['kind'] === 'STAFF') {
                $profile = StaffProfile::whereKey($context['staff_profile_id'])->where('user_id', $request->user()->id)->where('business_id', $booking->business_id)->where('branch_id', $booking->branch_id)->where('status', 'ACTIVE')->lockForUpdate()->first();
                abort_unless($profile && (int) $item->staff_profile_id === (int) $profile->id, 403);
            }
            abort_unless((int) $item->revision === (int) $data['expectedRevision'], 409, 'Dịch vụ vừa thay đổi; vui lòng tải lại.');
            $before = $item->getAttributes();
            $now = CarbonImmutable::now('UTC');
            if ($data['action'] === 'START') {
                abort_unless($item->status === 'SCHEDULED' && in_array($booking->status, ['CHECKED_IN', 'IN_PROGRESS'], true), 409);
                abort_unless($now->greaterThanOrEqualTo($item->item_start_at), 409, 'Chưa đến giờ bắt đầu dịch vụ.');
                $staff = $item->staff_profile_id ? $this->availability->resolveStaff($booking->branch, $item->offering, $item->staff_profile_id, false, true) : null;
                abort_if($staff && BookingService::where('staff_profile_id', $staff->id)->where('booking_id', '<>', $booking->id)->where('status', 'IN_PROGRESS')->lockForUpdate()->first(), 409, 'Nhân viên còn đang thực hiện lịch hẹn khác; cần ghi nhận hoàn tất trước.');
                abort_if($staff && $booking->services()->where('id', '<>', $item->id)->where('staff_profile_id', $staff->id)->where('status', 'IN_PROGRESS')->exists(), 409, 'Nhân viên đang thực hiện dịch vụ khác trong lịch.');
                $this->availability->check($booking->branch, $staff, ['duration_minutes' => $item->duration_minutes, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => $item->buffer_after_minutes], $now->setTimezone($booking->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), null, true, $booking->id, true, 'STAFF_CREATED');
                if ($booking->status === 'CHECKED_IN') {
                    $this->lifecycle->transition($executionRequest, $booking, 'IN_PROGRESS', trim($data['reason']));
                }
                DB::table('booking_services')->where('id', $item->id)->update(['status' => 'IN_PROGRESS', 'actual_started_at' => $now, 'actual_timing_status' => 'KNOWN', 'revision' => $item->revision + 1, 'updated_at' => $now]);
            } elseif ($data['action'] === 'COMPLETE') {
                abort_unless($item->status === 'IN_PROGRESS' && $booking->status === 'IN_PROGRESS', 409);
                DB::table('booking_services')->where('id', $item->id)->update(['status' => 'COMPLETED', 'actual_completed_at' => $now, 'revision' => $item->revision + 1, 'updated_at' => $now]);
            } else {
                abort(422);
            }
            DB::table('beautybook_booking_edits')->insert(['booking_id' => $booking->id, 'booking_service_id' => $item->id, 'actor_user_id' => $request->user()->id, 'action' => $data['action'], 'reason' => trim($data['reason']), 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($item->refresh()->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);

            return $booking->refresh();
        }, 3);
    }
}
