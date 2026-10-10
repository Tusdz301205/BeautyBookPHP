<?php

namespace App\Services\Operations;

use App\Enums\AccountKind;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\OperationalImpactCase;
use App\Models\OperationalImpactItem;
use App\Models\PaymentTransaction;
use App\Models\RoleAssignment;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\BookingNotificationService;
use App\Services\Finance\Money;
use App\Services\Finance\PaymentService;
use App\Services\Identity\SessionContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImpactService
{
    private const ACTIVE = ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'];

    public function __construct(private AvailabilityService $availability, private BookingLifecycleService $lifecycle, private PaymentService $payments, private BookingNotificationService $notifications) {}

    public function access(Request $request, ?OperationalImpactCase $case = null, bool $lock = false): RoleAssignment
    {
        abort_unless($request->user()?->account_kind === AccountKind::Operational, 403);
        $assignment = app(SessionContext::class)->current($request);
        abort_unless($assignment && in_array($assignment->role_name, ['BUSINESS_OWNER', 'PLATFORM_ADMIN'], true), 403);
        if ($lock) {
            $assignment = RoleAssignment::whereKey($assignment->id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
        }
        abort_unless(in_array($assignment->role_name, ['BUSINESS_OWNER', 'PLATFORM_ADMIN'], true), 403);
        if ($case && $assignment->role_name !== 'PLATFORM_ADMIN') {
            abort_unless((int) $assignment->business_id === (int) $case->business_id && $assignment->branch_id === null, 403);
        }
        return $assignment;
    }

    public function openForBranch(Branch $branch, int $actorId, string $trigger, string $reason): OperationalImpactCase
    {
        if (trim($reason) === '') throw new DomainException('Cần lý do thay đổi vận hành.');
        return DB::transaction(function () use ($branch, $actorId, $trigger, $reason) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Business::whereKey($branch->business_id)->lockForUpdate()->firstOrFail();
            $case = OperationalImpactCase::where('branch_id', $branch->id)->where('trigger_type', $trigger)
                ->latest('id')->lockForUpdate()->first();
            if (! $case) $case = OperationalImpactCase::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id,
                'trigger_type' => $trigger, 'reason' => trim($reason), 'created_by' => $actorId,
                'deadline_at' => now()->addHours(24), 'snapshot' => [], 'status' => 'OPEN']);
            $this->refresh($case);
            return $case->refresh()->load('items.booking.services');
        }, 3);
    }

    public function openForStaff(\App\Models\StaffProfile $staff, int $actorId, string $reason): OperationalImpactCase
    {
        if (trim($reason) === '') throw new DomainException('Cần lý do nhân viên ngừng làm việc.');
        return DB::transaction(function () use ($staff, $actorId, $reason) {
            Branch::whereKey($staff->branch_id)->lockForUpdate()->firstOrFail();
            Business::whereKey($staff->business_id)->lockForUpdate()->firstOrFail();
            $staff = \App\Models\StaffProfile::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $case = OperationalImpactCase::where('subject_staff_profile_id', $staff->id)->where('trigger_type','STAFF_OFFBOARD')->where('status','<>','COMPLETED')->latest('id')->lockForUpdate()->first();
            if (!$case) $case = OperationalImpactCase::create(['business_id'=>$staff->business_id,'branch_id'=>$staff->branch_id,'subject_staff_profile_id'=>$staff->id,'trigger_type'=>'STAFF_OFFBOARD','reason'=>trim($reason),'created_by'=>$actorId,'deadline_at'=>now()->addHours(48),'snapshot'=>[],'status'=>'OPEN']);
            $this->refresh($case);
            return $case->refresh()->load('items.booking.services');
        }, 3);
    }

    private function bookingSnapshot(Booking $booking): array
    {
        return ['booking_id' => $booking->id, 'business_id' => $booking->business_id, 'branch_id' => $booking->branch_id,
            'status' => $booking->status, 'start_at' => $booking->getRawOriginal('appointment_start_at'), 'end_at' => $booking->getRawOriginal('appointment_end_at'),
            'services' => BookingService::where('booking_id', $booking->id)->orderBy('id')->get()->map(fn ($item) => [
                'id' => $item->id, 'offering_id' => $item->branch_service_offering_id, 'staff_id' => $item->staff_profile_id,
                'revision' => $item->revision, 'status' => $item->status, 'start_at' => $item->getRawOriginal('item_start_at'),
                'end_at' => $item->getRawOriginal('item_end_at'), 'price' => $item->price_at_booking,
            ])->all()];
    }

    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function refresh(OperationalImpactCase $case): void
    {
        $bookings = Booking::where('branch_id', $case->branch_id)->where('business_id', $case->business_id)
            ->when($case->subject_staff_profile_id, fn ($query,$staffId) => $query->whereHas('services',fn ($items) => $items->where('staff_profile_id',$staffId)->whereNotIn('status',['CANCELLED','COMPLETED'])))
            ->whereIn('status', self::ACTIVE)->where('appointment_end_at', '>', now()->utc())->orderBy('id')->lockForUpdate()->get();
        $activeIds = $bookings->modelKeys();
        $snapshot = [];
        foreach ($bookings as $booking) {
            $state = $this->bookingSnapshot($booking);
            $hash = $this->hash($state);
            $snapshot[] = $state;
            $item = $case->items()->where('booking_id', $booking->id)->lockForUpdate()->first();
            if (! $item) {
                $case->items()->create(['booking_id' => $booking->id, 'booking_snapshot' => $state, 'snapshot_hash' => $hash]);
            } elseif ($item->status === 'RESOLVED' && $item->resolved_hash !== $hash) {
                $item->update(['status' => 'PENDING', 'booking_snapshot' => $state, 'snapshot_hash' => $hash, 'resolved_hash' => null,
                    'resolved_at' => null, 'resolved_by' => null, 'resolution' => null, 'reason' => 'Lịch hẹn đã thay đổi sau khi xử lý; cần kiểm tra lại.']);
            } else {
                $item->update(['booking_snapshot' => $state, 'snapshot_hash' => $hash]);
            }
        }
        foreach ($case->items()->whereNotIn('booking_id', $activeIds)->where('status', '<>', 'RESOLVED')->lockForUpdate()->get() as $item) {
            $booking = Booking::find($item->booking_id);
            $state = $booking ? $this->bookingSnapshot($booking) : [];
            $item->update(['status' => 'RESOLVED', 'resolution' => 'EXTERNALLY_CLOSED', 'resolved_hash' => $this->hash($state), 'resolved_at' => now(), 'reason' => 'Lịch hẹn không còn nằm trong phạm vi ảnh hưởng.']);
        }
        $pending = $case->items()->where('status', '<>', 'RESOLVED')->exists();
        $unchangedCompleted = $case->status === 'COMPLETED' && ! $pending;
        $case->update(['snapshot' => $snapshot, 'snapshot_version' => $case->snapshot_version + 1,
            'status' => $unchangedCompleted ? 'COMPLETED' : ($pending ? 'IN_PROGRESS' : 'READY_TO_COMPLETE'),
            'completed_at' => $unchangedCompleted ? $case->completed_at : null]);
    }

    public function refreshForClosure(Branch $branch): bool
    {
        return DB::transaction(function () use ($branch) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Business::whereKey($branch->business_id)->lockForUpdate()->firstOrFail();
            $case = OperationalImpactCase::where('branch_id', $branch->id)->latest('id')->lockForUpdate()->first();
            if (! $case) return ! Booking::where('branch_id', $branch->id)->whereIn('status', self::ACTIVE)->where('appointment_end_at', '>', now()->utc())->exists();
            $this->refresh($case);
            if (! $case->items()->exists()) {
                $case->update(['status' => 'COMPLETED', 'completed_at' => now()]);
                return true;
            }
            return $case->fresh()->status === 'COMPLETED';
        }, 3);
    }

    public function complete(Request $request, OperationalImpactCase $case): OperationalImpactCase
    {
        $result = DB::transaction(function () use ($request, $case) {
            Branch::whereKey($case->branch_id)->lockForUpdate()->firstOrFail();
            Business::whereKey($case->business_id)->lockForUpdate()->firstOrFail();
            $case = OperationalImpactCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->access($request, $case, true);
            $this->refresh($case);
            $case->refresh();
            if ($case->status !== 'READY_TO_COMPLETE' && $case->status !== 'COMPLETED') return ['ready' => false, 'case' => $case];
            if ($case->status !== 'COMPLETED') {
                $case->update(['status' => 'COMPLETED', 'completed_at' => now()]);
                $this->audit($request, $case, null, 'COMPLETE');
            }
            return ['ready' => true, 'case' => $case];
        }, 3);
        // A stale case must remain reopened after the conflict response.
        if (! $result['ready']) throw new BookingConflictException('Có lịch hẹn mới hoặc đã thay đổi. Hồ sơ ảnh hưởng đã được mở lại.');
        return $result['case']->load('items.booking.services');
    }

    public function resolve(Request $request, OperationalImpactCase $case, OperationalImpactItem $item, array $data): OperationalImpactCase
    {
        return DB::transaction(function () use ($request, $case, $item, $data) {
            $branchIds = array_unique([$case->branch_id, $data['replacement_branch_id'] ?? $case->branch_id]);
            Branch::whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get();
            Business::whereKey($case->business_id)->lockForUpdate()->firstOrFail();
            $case = OperationalImpactCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->access($request, $case, true);
            abort_if($case->status === 'COMPLETED', 409, 'Hồ sơ đã hoàn tất.');
            $item = OperationalImpactItem::whereKey($item->id)->where('case_id', $case->id)->lockForUpdate()->firstOrFail();
            abort_unless($item->status === 'PENDING', 409);
            $booking = Booking::whereKey($item->booking_id)->where('business_id', $case->business_id)->lockForUpdate()->firstOrFail();
            if (trim($data['reason']) === '') throw new DomainException('Cần lý do xử lý.');
            $resolution = $data['resolution'];
            if ($resolution === 'CANCEL_REFUND') {
                if (! in_array($booking->status, ['CANCELLED', 'REJECTED', 'EXPIRED'], true)) $booking = $this->lifecycle->transition($request, $booking, 'CANCELLED', $data['reason']);
                foreach (PaymentTransaction::where('booking_id', $booking->id)->where('status', 'VERIFIED')->lockForUpdate()->get() as $payment) {
                    if ($payment->reversal()->exists()) continue;
                    $reserved = (int) $payment->refunds()->whereIn('status', ['PENDING', 'APPROVED', 'PROCESSING', 'REFUNDED'])->sum('amount_cents');
                    $remaining = max(0, $payment->amount_cents - $reserved);
                    if ($remaining > 0) $this->payments->requestRefund($request, $payment, ['amount' => Money::decimal($remaining), 'reason' => $data['reason'].' (impact #'.$case->id.')']);
                }
            } elseif (in_array($resolution, ['REASSIGN', 'RESCHEDULE', 'TRANSFER_BRANCH'], true)) {
                $this->move($booking, $data);
                $booking->refresh();
            } elseif ($resolution !== 'APPROVED_EXCEPTION') throw new DomainException('Phương án xử lý không hợp lệ.');
            $state = $this->bookingSnapshot($booking);
            $item->update(['status' => 'RESOLVED', 'resolution' => $resolution, 'reason' => trim($data['reason']),
                'replacement_staff_id' => $data['replacement_staff_id'] ?? null, 'replacement_branch_id' => $data['replacement_branch_id'] ?? null,
                'proposed_start_at' => isset($data['proposed_start_at']) ? CarbonImmutable::parse($data['proposed_start_at'], $booking->branch->timezone)->utc() : null,
                'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolved_hash' => $this->hash($state)]);
            $this->audit($request, $case, $item, $resolution);
            $recipient = $booking->customerProfile?->user_id;
            if ($recipient) $this->notifications->record($recipient, 'OPERATIONAL_IMPACT', 'Lịch hẹn đã được xử lý', 'Phương án: '.$resolution.'. Lý do: '.trim($data['reason']), $booking->id,
                'impact:'.$case->id.':'.$item->id.':'.$item->resolved_at->getTimestamp(), 'INFO', ['impact_case_id' => $case->id]);
            $case->update(['status' => $case->items()->where('status', '<>', 'RESOLVED')->exists() ? 'IN_PROGRESS' : 'READY_TO_COMPLETE']);
            return $case->load('items.booking.services');
        }, 3);
    }

    private function move(Booking $booking, array $data): void
    {
        if (! in_array($booking->status, ['PENDING', 'CONFIRMED'], true)) throw new BookingConflictException('Chỉ lịch hẹn chưa bắt đầu mới được đổi lịch, nhân viên hoặc chi nhánh.');
        $source = Branch::findOrFail($booking->branch_id);
        $transfer = $data['resolution'] === 'TRANSFER_BRANCH';
        $target = $transfer ? Branch::whereKey($data['replacement_branch_id'])->where('business_id', $booking->business_id)->publicReady()->firstOrFail() : $source;
        if ($transfer && (int) $target->id === (int) $source->id) throw new DomainException('Chọn chi nhánh đích khác chi nhánh hiện tại.');
        $oldStart = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
        $newStart = $data['resolution'] === 'RESCHEDULE' || ($transfer && isset($data['proposed_start_at'])) ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['proposed_start_at'], $target->timezone ?: 'Asia/Ho_Chi_Minh')->utc() : $oldStart;
        $delta = $newStart->getTimestamp() - $oldStart->getTimestamp();
        $items = BookingService::where('booking_id', $booking->id)->orderBy('sort_order')->lockForUpdate()->get();
        if ($items->isEmpty() || $items->contains(fn ($item) => $item->status !== 'SCHEDULED')) throw new BookingConflictException('Dịch vụ đã bắt đầu hoặc không còn được điều chuyển.');
        if ($booking->customer_profile_id) \App\Models\CustomerProfile::whereKey($booking->customer_profile_id)->lockForUpdate()->firstOrFail();
        $plans = [];
        foreach ($items as $item) {
            $offering = BranchServiceOffering::where('branch_id', $target->id)->where('business_id', $booking->business_id)
                ->where('business_service_id', $item->business_service_id)->where('is_active', true)->where('bookable', true)
                ->whereHas('service', fn ($q) => $q->where('status', 'ACTIVE')->where('is_active', true))->lockForUpdate()->first();
            if (! $offering) throw new BookingConflictException('Chi nhánh đích thiếu dịch vụ tương ứng.');
            if ($transfer && $item->service_variant_id !== null) throw new BookingConflictException('Lịch có biến thể cần được xử lý riêng trước khi chuyển chi nhánh.');
            $staffId = $data['replacement_staff_id'] ?? $item->staff_profile_id;
            $staff = $this->availability->resolveStaff($target, $offering, (int) $staffId, false, true);
            $quote = ['duration_minutes' => $item->duration_minutes, 'buffer_before_minutes' => $item->buffer_before_minutes, 'buffer_after_minutes' => $item->buffer_after_minutes];
            $start = CarbonImmutable::parse($item->getRawOriginal('item_start_at'), 'UTC')->addSeconds($delta)->setTimezone($target->timezone ?: 'Asia/Ho_Chi_Minh');
            $window = $this->availability->check($target, $staff, $quote, $start->format('Y-m-d\TH:i'), $booking->customer_profile_id, true, $booking->id);
            foreach ($plans as $previous) if ($previous['staff_profile_id'] === $staff->id && $previous['occupied_end_at'] > $window['occupied_start_at']) throw new BookingConflictException('Nhân viên bị trùng khoảng đệm giữa các dịch vụ.');
            $plans[] = array_merge($item->getAttributes(), ['branch_id' => $target->id, 'branch_service_offering_id' => $offering->id,
                'staff_profile_id' => $staff->id, 'revision' => $item->revision + 1, 'updated_at' => now()], collect($window)->only(['item_start_at', 'item_end_at', 'occupied_start_at', 'occupied_end_at'])->all());
        }
        if ($transfer) {
            if (Schema::hasTable('package_session_entitlements') && DB::table('package_session_entitlements')->whereIn('booking_service_id', $items->modelKeys())->lockForUpdate()->exists()) {
                throw new BookingConflictException('Dịch vụ đang liên kết lượt liệu trình; cần xử lý lượt liệu trình trước khi chuyển chi nhánh.');
            }
            // MySQL composite scope keys are immediate; retain item IDs and immutable fields when moving scope.
            BookingService::where('booking_id', $booking->id)->delete();
            $booking->update(['branch_id' => $target->id]);
            DB::table('booking_services')->insert($plans);
        } else {
            foreach ($plans as $plan) BookingService::whereKey($plan['id'])->update(collect($plan)->only(['staff_profile_id', 'revision', 'updated_at', 'item_start_at', 'item_end_at', 'occupied_start_at', 'occupied_end_at'])->all());
        }
        $booking->update(['appointment_start_at' => $plans[0]['item_start_at'], 'appointment_end_at' => $plans[array_key_last($plans)]['item_end_at']]);
    }

    public function batch(Request $request, OperationalImpactCase $case, array $data): array
    {
        $this->access($request, $case);
        $results = [];
        foreach (array_unique($data['item_ids']) as $id) {
            try {
                $item = $case->items()->whereKey($id)->firstOrFail();
                $this->resolve($request, $case, $item, $data);
                $results[] = ['item_id' => $id, 'ok' => true];
            } catch (\Throwable $exception) {
                $results[] = ['item_id' => $id, 'ok' => false, 'error' => $exception->getMessage()];
            }
        }
        return ['results' => $results, 'case' => $case->fresh('items.booking.services')];
    }

    public function escalateOverdue(): int
    {
        return OperationalImpactCase::whereIn('status', ['OPEN', 'IN_PROGRESS'])->where('deadline_at', '<', now())->update(['status' => 'ESCALATED', 'updated_at' => now()]);
    }

    private function audit(Request $request, OperationalImpactCase $case, ?OperationalImpactItem $item, string $action): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'business_id' => $case->business_id, 'action' => $action,
            'entity_type' => $item ? 'OperationalImpactItem' : 'OperationalImpactCase', 'entity_id' => (string) ($item?->id ?? $case->id),
            'metadata' => json_encode(['case_id' => $case->id, 'booking_id' => $item?->booking_id], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
