<?php

namespace App\Services\Beautybook;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\StaffProfile;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingLifecycleAccessService;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\BookingNotificationService;
use App\Services\Finance\Money;
use App\Services\Pricing\QuoteService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingCommands
{
    public function __construct(private BookingLifecycleAccessService $access, private AvailabilityService $availability) {}

    private function lock(Request $r, Booking $booking): array
    {
        $b = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $operatingRequest = app(ApiScope::class)->requestForRoles($r, ['BUSINESS_OWNER', 'RECEPTIONIST'], $b->business_id, $b->branch_id);
        $c = $this->access->resolve($operatingRequest, $b);
        $this->access->assertCurrent($b, $c);
        abort_unless(in_array($c['kind'], ['OWNER', 'RECEPTIONIST'], true), 403);

        return [$b, $c];
    }

    private function audit(Request $r, Booking $b, string $action, array $before, array $after, ?int $item = null, ?string $reason = null): void
    {
        DB::table('beautybook_booking_edits')->insert(['booking_id' => $b->id, 'booking_service_id' => $item, 'actor_user_id' => $r->user()->id, 'action' => $action, 'reason' => $reason, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function move(Request $r, Booking $booking, string $start, ?string $end, ?int $staffId): Booking
    {
        return DB::transaction(function () use ($r, $booking, $start, $end, $staffId) {
            [$b,$c] = $this->lock($r, $booking);
            abort_unless(in_array($b->status, ['PENDING', 'CONFIRMED'], true), 409, 'Chỉ được di chuyển lịch chưa check-in.');
            $branch = $b->branch()->lockForUpdate()->firstOrFail();
            $items = $b->services()->orderBy('sort_order')->lockForUpdate()->get();
            $new = CarbonImmutable::parse($start)->utc();
            $shift = (int) $b->appointment_start_at->diffInMinutes($new, false);
            $before = $items->map->getAttributes()->all();
            $last = $items->last();
            $expectedEnd = $last->item_end_at->addMinutes($shift);
            if ($end) {
                abort_unless($expectedEnd->equalTo(CarbonImmutable::parse($end)->utc()), 422, 'Di chuyển phải giữ nguyên thời lượng; dùng resize để chỉnh thời lượng.');
            }
            foreach ($items as $i) {
                $staff = $this->availability->resolveStaff($branch, $i->offering, $staffId ?: $i->staff_profile_id, false, true);
                $quote = ['duration_minutes' => $i->duration_minutes, 'buffer_before_minutes' => $i->buffer_before_minutes, 'buffer_after_minutes' => $i->buffer_after_minutes];
                $window = $this->availability->check($branch, $staff, $quote, $i->item_start_at->addMinutes($shift)->setTimezone($branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), $b->customer_profile_id, true, $b->id);
                $i->update(['staff_profile_id' => $staff->id, 'item_start_at' => $window['item_start_at'], 'item_end_at' => $window['item_end_at'], 'occupied_start_at' => $window['occupied_start_at'], 'occupied_end_at' => $window['occupied_end_at'], 'revision' => $i->revision + 1]);
            }
            $b->update(['appointment_start_at' => $new, 'appointment_end_at' => $expectedEnd]);
            $this->audit($r, $b, 'MOVE', $before, $items->map->getAttributes()->all());
            app(BookingNotificationService::class)->record($b->customerProfile?->user_id ?: $r->user()->id, 'BOOKING_MOVED', 'Lịch hẹn đã thay đổi', 'Giờ hẹn '.$b->booking_code.' đã được cập nhật.', $b->id, 'move:'.$b->id.':'.now()->timestamp);

            return $b->refresh();
        }, 3);
    }

    public function resize(Request $r, Booking $booking, string $end): Booking
    {
        return DB::transaction(function () use ($r, $booking, $end) {
            [$b,$c] = $this->lock($r, $booking);
            abort_unless(in_array($b->status, ['PENDING', 'CONFIRMED'], true), 409);
            $i = $b->services()->orderByDesc('sort_order')->lockForUpdate()->firstOrFail();
            $new = CarbonImmutable::parse($end)->utc();
            $minutes = (int) $i->item_start_at->diffInMinutes($new, false);
            abort_unless($minutes > 0 && $minutes <= 1440, 422);
            $before = $i->getAttributes();
            $staff = $this->availability->resolveStaff($b->branch, $i->offering, $i->staff_profile_id, false, true);
            $w = $this->availability->check($b->branch, $staff, ['duration_minutes' => $minutes, 'buffer_before_minutes' => $i->buffer_before_minutes, 'buffer_after_minutes' => $i->buffer_after_minutes], $i->item_start_at->setTimezone($b->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), $b->customer_profile_id, true, $b->id);
            $i->update(['duration_minutes' => $minutes, 'item_end_at' => $w['item_end_at'], 'occupied_end_at' => $w['occupied_end_at'], 'revision' => $i->revision + 1]);
            $b->update(['appointment_end_at' => $w['item_end_at']]);
            $this->audit($r, $b, 'RESIZE', $before, $i->getAttributes(), $i->id);

            return $b->refresh();
        }, 3);
    }

    public function actual(Request $r, Booking $booking, int $itemId, array $data): BookingService
    {
        return DB::transaction(function () use ($r, $booking, $itemId, $data) {
            [$b,$c] = $this->lock($r, $booking);
            abort_unless($c['kind'] === 'OWNER', 403);
            $i = $b->services()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($i->status, ['SCHEDULED', 'IN_PROGRESS', 'COMPLETED'], true) && in_array($b->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true), 409);
            abort_if($i->staffProfile?->user_id === $r->user()->id, 403, 'Không được đính chính dịch vụ của chính mình.');
            abort_unless((int) $i->revision === (int) $data['expectedRevision'], 409, 'Dịch vụ đã được sửa ở phiên khác.');
            $unknown = $data['actualStartedAt'] === null && $data['actualCompletedAt'] === null;
            $timingStatus = $unknown ? 'UNKNOWN' : 'KNOWN';
            abort_unless(! isset($data['actualTimingStatus']) || $data['actualTimingStatus'] === $timingStatus, 422, 'Trạng thái phải khớp với hai mốc thực tế.');
            $start = $end = null;
            if (! $unknown) {
                foreach (['actualStartedAt', 'actualCompletedAt'] as $field) {
                    abort_unless(is_string($data[$field]) && preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/', $data[$field]), 422, 'Cần cả hai mốc ISO có múi giờ hoặc null/null.');
                }
                $start = CarbonImmutable::parse($data['actualStartedAt'])->utc();
                $end = CarbonImmutable::parse($data['actualCompletedAt'])->utc();
                abort_unless($start->lessThan($end) && $end->lessThanOrEqualTo(CarbonImmutable::now('UTC')), 422);
                if ($i->staff_profile_id) {
                    StaffProfile::whereKey($i->staff_profile_id)->lockForUpdate()->firstOrFail();
                    foreach (BookingService::where('staff_profile_id', $i->staff_profile_id)->where('id', '<>', $i->id)->whereNotNull('actual_started_at')->lockForUpdate()->get() as $other) {
                        $observedStart = CarbonImmutable::parse($other->actual_started_at, 'UTC');
                        if (! $observedStart->lessThan($end)) {
                            continue;
                        }
                        $observedEnd = $other->actual_completed_at ? CarbonImmutable::parse($other->actual_completed_at, 'UTC') : null;
                        if (! $observedEnd && $other->status === 'CANCELLED') {
                            $released = DB::table('booking_status_histories')->where('booking_id', $other->booking_id)->whereIn('to_status', ['CANCELLED', 'REJECTED', 'EXPIRED'])->where('created_at', '>=', $observedStart)->orderBy('created_at')->value('created_at');
                            $observedEnd = $released ? CarbonImmutable::parse($released, 'UTC') : null;
                        }
                        if (! $observedEnd && $other->status === 'IN_PROGRESS') {
                            $observedEnd = CarbonImmutable::now('UTC');
                        }
                        abort_if(! $observedEnd || $observedEnd->greaterThan($start), 409, 'Thời gian thực tế trùng dịch vụ khác hoặc thiếu bằng chứng thời điểm giải phóng.');
                    }
                }
            }
            $before = $i->getAttributes();
            DB::table('booking_services')->where('id', $i->id)->update(['status' => 'COMPLETED', 'actual_started_at' => $start, 'actual_completed_at' => $end, 'actual_timing_status' => $timingStatus, 'revision' => $i->revision + 1, 'updated_at' => now()]);
            $i->refresh();
            $this->audit($r, $b, 'ACTUAL_TIME', $before, $i->getAttributes(), $i->id, $data['reason']);

            return $i;
        }, 3);
    }

    private function amount(Booking $b, int $delta): void
    {
        $total = Money::cents($b->total_amount);
        $next = max(0, $total + $delta);
        abort_unless($next <= 99999999999999, 422);
        $b->update(['total_amount' => Money::decimal($next)]);
    }

    public function add(Request $r, Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($r, $booking, $data) {
            [$b,$c] = $this->lock($r, $booking);
            abort_unless(in_array($b->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'], true), 409);
            if (isset($data['price']) || isset($data['durationMinutes'])) {
                abort_unless($c['kind'] === 'OWNER', 403);
            }$quote = app(QuoteService::class)->quote($b->branch, (int) $data['serviceId'], isset($data['variantId']) ? (int) $data['variantId'] : null, true);
            $items = $b->services()->lockForUpdate()->orderBy('sort_order')->get();
            $start = $items->last()?->item_end_at ?? $b->appointment_start_at;
            $duration = $data['durationMinutes'] ?? $quote['duration_minutes'];
            $price = isset($data['price']) ? (string) $data['price'] : $quote['unit_price'];
            $staffId = (int) ($data['staffId'] ?? $items->last()?->staff_profile_id);
            $staff = $this->availability->resolveStaff($b->branch, $quote['offering'], $staffId, false, true);
            $quote['duration_minutes'] = $duration;
            $w = $this->availability->check($b->branch, $staff, $quote, $start->setTimezone($b->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), $b->customer_profile_id, true, $b->id);
            $i = BookingService::create(['booking_id' => $b->id, 'business_id' => $b->business_id, 'branch_id' => $b->branch_id, 'branch_service_offering_id' => $quote['offering']->id, 'business_service_id' => $quote['offering']->business_service_id, 'canonical_service_id' => $quote['offering']->service->canonical_service_id, 'staff_profile_id' => $staff->id, 'service_variant_id' => $quote['variant']?->id, 'price_at_booking' => $price, 'currency' => 'VND', 'duration_minutes' => $duration, 'buffer_before_minutes' => $quote['buffer_before_minutes'], 'buffer_after_minutes' => $quote['buffer_after_minutes'], 'service_name_snapshot' => $quote['offering']->service->name, 'variant_name_snapshot' => $quote['variant']?->name, 'sort_order' => $items->count(), 'status' => 'SCHEDULED', 'revision' => 1, 'item_start_at' => $w['item_start_at'], 'item_end_at' => $w['item_end_at'], 'occupied_start_at' => $w['occupied_start_at'], 'occupied_end_at' => $w['occupied_end_at']]);
            $this->amount($b, Money::cents($price));
            $b->update(['appointment_end_at' => $w['item_end_at']]);
            $this->audit($r, $b, 'ADD', [], $i->getAttributes(), $i->id, $data['reason']);

            return $b->refresh();
        }, 3);
    }

    public function item(Request $r, Booking $booking, int $itemId, array $data): Booking
    {
        return DB::transaction(function () use ($r, $booking, $itemId, $data) {
            [$b,$c] = $this->lock($r, $booking);
            $i = $b->services()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            abort_unless($i->revision === $data['expectedRevision'], 409);
            $before = $i->getAttributes();
            $action = $data['action'];
            $delta = 0;
            if (in_array($action, ['REPRICE', 'RESIZE', 'START', 'COMPLETE'], true)) {
                abort_unless($c['kind'] === 'OWNER', 403);
            }
            if ($action === 'REPRICE') {
                abort_unless(in_array($i->status, ['SCHEDULED', 'IN_PROGRESS'], true), 409);
                $next = Money::cents((string) $data['price']);
                $delta = $next - Money::cents($i->price_at_booking);
                $i->price_at_booking = Money::decimal($next);
            } elseif (in_array($action, ['REMOVE', 'SKIP'], true)) {
                abort_unless($action === 'SKIP' ? in_array($i->status, ['SCHEDULED', 'IN_PROGRESS'], true) : $i->status === 'SCHEDULED', 409);
                if ($action === 'REMOVE') {
                    abort_unless($b->services()->where('id', '<>', $i->id)->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])->exists(), 409, 'Đây là dịch vụ cuối cùng; hãy hủy toàn bộ lịch hẹn.');
                }$delta = $i->status === 'SCHEDULED' ? -Money::cents($i->price_at_booking) : 0;
                $i->status = $action === 'SKIP' ? 'SKIPPED' : 'CANCELLED';
            } elseif ($action === 'REASSIGN' || $action === 'RESIZE') {
                abort_unless(in_array($i->status, ['SCHEDULED', 'IN_PROGRESS'], true), 409);
                if ($action === 'RESIZE') {
                    abort_unless((int) $b->services()->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])->orderByDesc('sort_order')->value('id') === (int) $i->id, 409, 'Chỉ dịch vụ cuối cùng được chỉnh thời lượng.');
                }$staffId = (int) ($data['staffId'] ?? $i->staff_profile_id);
                $staff = $this->availability->resolveStaff($b->branch, $i->offering, $staffId, false, true);
                $minutes = $data['durationMinutes'] ?? $i->duration_minutes;
                $w = $this->availability->check($b->branch, $staff, ['duration_minutes' => $minutes, 'buffer_before_minutes' => $i->buffer_before_minutes, 'buffer_after_minutes' => $i->buffer_after_minutes], $i->item_start_at->setTimezone($b->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), $b->customer_profile_id, true, $b->id);
                abort_if($b->services()->where('id', '<>', $i->id)->where('staff_profile_id', $staffId)->whereIn('status', ['SCHEDULED', 'IN_PROGRESS'])->where('item_start_at', '<', $w['item_end_at'])->where('item_end_at', '>', $w['item_start_at'])->exists(), 409, 'Trùng dịch vụ trong cùng lịch hẹn.');
                $i->fill(['staff_profile_id' => $staffId, 'duration_minutes' => $minutes, 'item_end_at' => $w['item_end_at'], 'occupied_end_at' => $w['occupied_end_at']]);
            } elseif ($action === 'START') {
                abort_unless($i->status === 'SCHEDULED' && in_array($b->status, ['CHECKED_IN', 'IN_PROGRESS'], true) && now()->greaterThanOrEqualTo($i->item_start_at), 409);
                if ($b->status === 'CHECKED_IN') {
                    app(BookingLifecycleService::class)->transition($r, $b, 'IN_PROGRESS');
                }$i->status = 'IN_PROGRESS';
                DB::table('booking_services')->where('id', $i->id)->update(['actual_started_at' => now(), 'actual_timing_status' => 'KNOWN']);
            } elseif ($action === 'COMPLETE') {
                abort_unless($i->status === 'IN_PROGRESS', 409);
                $i->status = 'COMPLETED';
                DB::table('booking_services')->where('id', $i->id)->update(['actual_completed_at' => now()]);
            } else {
                abort(422);
            }$i->revision++;
            $i->save();
            if ($delta) {
                $this->amount($b, $delta);
            }$b->update(['appointment_end_at' => $b->services()->where('status', '<>', 'CANCELLED')->max('item_end_at') ?: $b->appointment_end_at]);
            $this->audit($r, $b, $action, $before, $i->refresh()->getAttributes(), $i->id, $data['reason']);

            return $b->refresh();
        }, 3);
    }
}
