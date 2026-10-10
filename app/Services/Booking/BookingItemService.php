<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingItemService
{
    public function __construct(
        private BookingLifecycleAccessService $access,
        private BookingLifecycleService $lifecycle,
    ) {
    }

    public function transition(
        Request $request,
        Booking $booking,
        BookingService $item,
        string $action,
        int $expectedRevision,
        ?CarbonImmutable $at = null,
    ): BookingService {
        abort_unless((int) $item->booking_id === (int) $booking->id, 404);

        $context = $this->access->resolve($request, $booking);
        abort_unless($context['kind'] === 'STAFF', 403);

        $now = $at ?? CarbonImmutable::now('UTC');
        $targetAction = strtoupper($action);

        return DB::transaction(function () use (
            $request,
            $booking,
            $item,
            $context,
            $expectedRevision,
            $now,
            $targetAction,
        ) {
            $lockedBooking = Booking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->access->assertCurrent($lockedBooking, $context);

            $staff = StaffProfile::query()
                ->whereKey($context['staff_profile_id'])
                ->where('user_id', $context['actor_user_id'])
                ->where('business_id', $lockedBooking->business_id)
                ->where('branch_id', $lockedBooking->branch_id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();
            abort_unless($staff, 403);

            $lockedItem = BookingService::query()
                ->whereKey($item->id)
                ->where('booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless((int) $lockedItem->staff_profile_id === (int) $staff->id, 403);

            if ((int) $lockedItem->revision !== $expectedRevision) {
                throw new BookingConflictException('Dịch vụ đã được cập nhật ở phiên khác. Vui lòng tải lại lịch hẹn.');
            }

            if ($targetAction === 'START') {
                $this->startItem($request, $lockedBooking, $lockedItem, $now);

                return $lockedItem->refresh();
            }

            if ($targetAction === 'COMPLETE') {
                $this->completeItem($request, $lockedBooking, $lockedItem, $now);

                return $lockedItem->refresh();
            }

            abort(422);
        }, 3);
    }

    private function startItem(
        Request $request,
        Booking $booking,
        BookingService $item,
        CarbonImmutable $now,
    ): void {
        if ($item->status !== 'SCHEDULED') {
            throw new BookingConflictException('Dịch vụ này không còn ở trạng thái chờ bắt đầu.');
        }

        if (! in_array($booking->status, ['CHECKED_IN', 'IN_PROGRESS'], true)) {
            throw new BookingConflictException('Lịch hẹn phải được check-in trước khi bắt đầu dịch vụ.');
        }

        $itemStart = CarbonImmutable::parse($item->getRawOriginal('item_start_at'), 'UTC');
        if ($now->lessThan($itemStart)) {
            throw new BookingConflictException('Chưa đến giờ bắt đầu dịch vụ.');
        }

        if ($booking->status === 'CHECKED_IN') {
            $this->lifecycle->transition($request, $booking, 'IN_PROGRESS', null, false, $now);
            $booking->refresh();
        }

        $item->status = 'IN_PROGRESS';
        $item->revision = (int) $item->revision + 1;
        $item->save();
        DB::table('booking_services')->where('id',$item->id)->update(['actual_started_at'=>$now,'actual_timing_status'=>'KNOWN']);
    }

    private function completeItem(
        Request $request,
        Booking $booking,
        BookingService $item,
        CarbonImmutable $now,
    ): void {
        if ($item->status !== 'IN_PROGRESS') {
            throw new BookingConflictException('Dịch vụ này chưa ở trạng thái đang thực hiện.');
        }

        if ($booking->status !== 'IN_PROGRESS') {
            throw new BookingConflictException('Lịch hẹn không ở trạng thái đang thực hiện.');
        }

        $itemEnd = CarbonImmutable::parse($item->getRawOriginal('item_end_at'), 'UTC');
        if ($now->lessThan($itemEnd)) {
            throw new BookingConflictException('Chưa đến thời điểm hoàn thành dịch vụ.');
        }

        $item->status = 'COMPLETED';
        $item->revision = (int) $item->revision + 1;
        $item->save();
        DB::table('booking_services')->where('id',$item->id)->update(['actual_completed_at'=>$now]);

        $hasActiveItems = BookingService::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])
            ->exists();

        if (! $hasActiveItems) {
            $this->lifecycle->transition($request, $booking, 'COMPLETED', null, false, $now);
        }
    }
}

