<?php

namespace App\Services\Scheduling;

use App\Exceptions\BookingConflictException;
use App\Models\Branch;
use App\Models\RecurringBookingSeries;
use App\Models\StaffProfile;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingAccessService;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\BookingPolicyService;
use App\Services\Booking\CreateBookingService;
use App\Services\Pricing\QuoteService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringService
{
    public function __construct(
        private BookingAccessService $access,
        private QuoteService $quotes,
        private AvailabilityService $availability,
        private CreateBookingService $bookings,
        private BookingPolicyService $policy,
        private BookingLifecycleService $lifecycle,
        private ComboService $combos,
        private CreateComboBookingService $comboBookings,
    ) {
    }

    public function context(Request $request, Branch $branch): array
    {
        $context = $this->access->resolve($request, $branch);
        abort_unless($context['kind'] === 'CUSTOMER', 403);

        return $context;
    }

    public function dates(Branch $branch, array $data): array
    {
        $cursor = CarbonImmutable::parse($data['start_date'].' '.$data['preferred_time'], $branch->timezone ?: 'Asia/Ho_Chi_Minh');
        $end = isset($data['end_date']) ? $data['end_date'] : null;
        $dates = [];
        for ($index = 0; $index < $data['occurrence_count']; $index++) {
            if ($end !== null && $cursor->toDateString() > $end) {
                break;
            }
            $dates[] = $cursor;
            // Match the original API's JavaScript Date.setUTCMonth rollover semantics.
            $cursor = match ($data['frequency']) {
                'MONTHLY' => $cursor->addMonthWithOverflow(),
                'BIWEEKLY' => $cursor->addDays(14),
                default => $cursor->addDays(7),
            };
        }
        if (count($dates) < 2) {
            throw new DomainException('Chuỗi lịch cần ít nhất hai kỳ trong khoảng ngày đã chọn.');
        }

        return $dates;
    }

    public function preview(Branch $branch, array $context, array $data): array
    {
        abort_unless($context['kind'] === 'CUSTOMER', 403);
        $cart=!empty($data['items']);
        $quote = empty($data['combo_id'])&&!$cart ? $this->quotes->quote($branch, (int) $data['branch_service_offering_id'], $data['service_variant_id'] ?? null) : null;
        $occurrences = [];
        foreach ($this->dates($branch, $data) as $index => $date) {
            try {
                $staffId = $cart ? app(\App\Services\Booking\CartBookingService::class)->previewPlan($branch,$context,['items'=>$data['items'],'staff_profile_id'=>$data['staff_mode']==='SAME_STAFF'?$data['staff_profile_id']:null,'start_at'=>$date->format('Y-m-d\TH:i'),'source_timeline'=>true])[0]['staff_id'] : ($quote
                    ? $this->findStaff($branch, $context, $data, $quote, $date->format('Y-m-d\TH:i'))
                    : $this->findComboStaff($branch, $context, $data, $date->format('Y-m-d\TH:i')));
                $occurrences[] = ['sequence' => $index, 'start_at' => $date->format('Y-m-d\TH:i'), 'available' => true, 'staff_profile_id' => $staffId];
            } catch (DomainException $exception) {
                $occurrences[] = ['sequence' => $index, 'start_at' => $date->format('Y-m-d\TH:i'), 'available' => false, 'reason' => $exception->getMessage()];
            }
        }

        return ['occurrences' => $occurrences, 'available_count' => count(array_filter($occurrences, fn ($o) => $o['available'])), 'conflict_count' => count(array_filter($occurrences, fn ($o) => ! $o['available']))];
    }

    public function create(Branch $branch, array $context, array $data): array
    {
        abort_unless($context['kind'] === 'CUSTOMER', 403);
        $payloadData = collect($data)->except('idempotency_key')->all();
        ksort($payloadData);
        $hash = hash('sha256', json_encode(['branch_id' => $branch->id, 'workspace_key' => $context['workspace_key'], 'data' => $payloadData], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($branch, $context, $data, $hash) {
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $business = $branch->business()->lockForUpdate()->firstOrFail();
            \App\Models\CustomerProfile::query()->whereKey($context['customer_profile_id'])
                ->where('user_id', $context['actor_user_id'])->lockForUpdate()->firstOrFail();
            $existing = RecurringBookingSeries::query()->where('customer_profile_id', $context['customer_profile_id'])
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new BookingConflictException('Idempotency-Key đã được dùng cho nội dung chuỗi khác.');
                }
                return ['series' => $existing->load('occurrences.booking'), 'replayed' => true];
            }
            $this->access->assertCurrentBranch($branch, $business, $context);
            $this->policy->assertSelfBookingAllowed($context['customer_profile_id'], $branch->business_id, ($data['violation_acknowledged'] ?? false) === true);
            $series = RecurringBookingSeries::create([
                'customer_profile_id' => $context['customer_profile_id'], 'branch_id' => $branch->id,
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                'frequency' => $data['frequency'], 'staff_mode' => $data['staff_mode'], 'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null, 'preferred_time' => $data['preferred_time'],
                'occurrence_count' => $data['occurrence_count'], 'request_snapshot' => collect($data)->except('idempotency_key')->all(), 'status' => 'CREATING',
            ]);
            $created = 0;
            foreach ($this->dates($branch, $data) as $index => $date) {
                $start = $date->format('Y-m-d\TH:i');
                try {
                    if (! empty($data['combo_id'])) {
                        $combo = $this->combos->resolveForBooking($branch, (int) $data['combo_id'], true);
                        $staffId = $this->findComboStaff($branch, $context, $data, $start, true);
                        $booking = $this->comboBookings->create($branch, $combo, $context, [
                            'idempotency_key' => 'recurring:'.$series->id.':'.$index, 'start_at' => $start,
                            'staff_profile_id' => $staffId,
                            'staff_assignments' => $combo->items->mapWithKeys(fn ($item) => [$item->branch_service_offering_id => $staffId])->all(),
                            'note' => $data['note'] ?? null, 'violation_acknowledged' => $data['violation_acknowledged'] ?? false,
                            'contact'=>$data['contact']??null,'contact_consent'=>$data['contact_consent']??false,
                        ])['booking'];
                    } elseif(!empty($data['items'])) {
                        $booking=app(\App\Services\Booking\CartBookingService::class)->create($branch,$context,[
                            'items'=>$data['items'],'staff_profile_id'=>$data['staff_mode']==='SAME_STAFF'?$data['staff_profile_id']:null,
                            'start_at'=>$start,'source_timeline'=>true,'idempotency_key'=>'recurring:'.$series->id.':'.$index,
                            'note'=>$data['note']??null,'violation_acknowledged'=>$data['violation_acknowledged']??false,
                            'contact'=>$data['contact']??null,'contact_consent'=>$data['contact_consent']??false])['booking'];
                    } else {
                        $quote = $this->quotes->quote($branch, (int) $data['branch_service_offering_id'], $data['service_variant_id'] ?? null, true, $context['customer_profile_id']);
                        $staffId = $this->findStaff($branch, $context, $data, $quote, $start, true);
                        $booking = $this->bookings->create($branch, $context, [
                        'idempotency_key' => 'recurring:'.$series->id.':'.$index,
                        'branch_service_offering_id' => $data['branch_service_offering_id'],
                        'service_variant_id' => $data['service_variant_id'] ?? null,
                        'staff_profile_id' => $staffId, 'start_at' => $start,
                        'note' => $data['note'] ?? null, 'violation_acknowledged' => $data['violation_acknowledged'] ?? false,
                        ])['booking'];
                    }
                    $series->occurrences()->create(['booking_id' => $booking->id, 'sequence' => $index, 'scheduled_start_at' => $date->utc(), 'status' => 'BOOKED']);
                    $created++;
                } catch (DomainException $exception) {
                    if (! ($data['skip_conflicts'] ?? false)) {
                        throw new BookingConflictException('Không thể tạo kỳ '.($index + 1).': '.$exception->getMessage());
                    }
                    $series->occurrences()->create(['sequence' => $index, 'scheduled_start_at' => $date->utc(), 'status' => 'SKIPPED', 'reason' => $exception->getMessage()]);
                }
            }
            if ($created === 0) {
                throw new BookingConflictException('Không có kỳ nào còn chỗ.');
            }
            $series->update(['status' => 'ACTIVE']);

            return ['series' => $series->load('occurrences.booking'), 'replayed' => false];
        }, 3);
    }

    private function findStaff(Branch $branch, array $context, array $data, array $quote, string $start, bool $lock = false): int
    {
        $ids = $data['staff_mode'] === 'SAME_STAFF'
            ? [(int) $data['staff_profile_id']]
            : StaffProfile::query()->where('branch_id', $branch->id)->where('business_id', $branch->business_id)
                ->where('status', 'ACTIVE')->where('is_bookable', true)->where('public_visible', true)->orderBy('id')->pluck('id')->all();
        $reason = 'Không còn nhân viên phù hợp trong khung giờ này.';
        foreach ($ids as $id) {
            try {
                $staff = $this->availability->resolveStaff($branch, $quote['offering'], $id, true, $lock);
                $this->availability->check($branch, $staff, $quote, $start, $context['customer_profile_id'], $lock);
                return $id;
            } catch (DomainException $exception) {
                $reason = $exception->getMessage();
            }
        }
        throw new DomainException($reason);
    }

    private function findComboStaff(Branch $branch, array $context, array $data, string $start, bool $lock = false): int
    {
        $combo = $this->combos->resolveForBooking($branch, (int) $data['combo_id'], $lock);
        $ids = $data['staff_mode'] === 'SAME_STAFF' ? [(int) $data['staff_profile_id']]
            : StaffProfile::where('business_id', $branch->business_id)->where('branch_id', $branch->id)
                ->where('status', 'ACTIVE')->where('is_bookable', true)->where('public_visible', true)->orderBy('id')->pluck('id')->all();
        $reason = 'Không còn nhân viên phù hợp cho toàn bộ combo.';
        foreach ($ids as $id) {
            try {
                $this->comboBookings->plan($branch, $combo, $context, ['start_at' => $start, 'staff_profile_id' => $id,
                    'staff_assignments' => $combo->items->mapWithKeys(fn ($item) => [$item->branch_service_offering_id => $id])->all()], $context['customer_profile_id'], $lock);
                return $id;
            } catch (DomainException $exception) {
                $reason = $exception->getMessage();
            }
        }
        throw new DomainException($reason);
    }

    public function owned(Request $request, RecurringBookingSeries $series, bool $lock = false): RecurringBookingSeries
    {
        abort_unless($request->user()?->account_kind === \App\Enums\AccountKind::Customer, 403);
        $customer = \App\Models\CustomerProfile::query()->where('user_id', $request->user()->id)->firstOrFail();
        $query = RecurringBookingSeries::query()->whereKey($series->id)->where('customer_profile_id', $customer->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->firstOrFail();
    }

    public function changeStatus(Request $request, RecurringBookingSeries $series, string $status): RecurringBookingSeries
    {
        return DB::transaction(function () use ($request, $series, $status) {
            $series = $this->owned($request, $series, true);
            if (! in_array($series->status, ['ACTIVE', 'PAUSED'], true) || ! in_array($status, ['ACTIVE', 'PAUSED'], true)) {
                throw new BookingConflictException('Chuỗi lịch không thể đổi trạng thái.');
            }
            // As in BeautyBook, pausing does not cancel bookings that were already created.
            $series->update(['status' => $status]);
            return $series;
        }, 3);
    }

    public function cancel(Request $request, RecurringBookingSeries $series, ?int $occurrenceId = null): RecurringBookingSeries
    {
        return DB::transaction(function () use ($request, $series, $occurrenceId) {
            $series = $this->owned($request, $series, true);
            if ($series->status === 'CREATING') {
                throw new BookingConflictException('Chuỗi lịch đang được tạo.');
            }
            $occurrences = $series->occurrences()->when($occurrenceId, fn ($q) => $q->whereKey($occurrenceId))->lockForUpdate()->get();
            abort_if($occurrenceId !== null && $occurrences->isEmpty(), 404);
            foreach ($occurrences as $occurrence) {
                $booking = $occurrence->booking;
                if ($booking && in_array($booking->status, ['PENDING', 'CONFIRMED'], true)) {
                    $this->lifecycle->transition($request, $booking, 'CANCELLED', 'Khách hủy kỳ trong chuỗi lịch');
                    $occurrence->update(['status' => 'CANCELLED']);
                } elseif ($booking && $booking->status === 'CANCELLED') {
                    $occurrence->update(['status' => 'CANCELLED']);
                } elseif ($occurrenceId !== null && $booking && $booking->status !== 'CANCELLED') {
                    throw new BookingConflictException('Kỳ này không còn có thể hủy.');
                }
            }
            if ($occurrenceId === null) {
                $series->update(['status' => 'CANCELLED']);
            }
            return $series->load('occurrences.booking');
        }, 3);
    }
}
