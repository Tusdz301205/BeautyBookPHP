<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\BookingContact;
use App\Models\BookingPricingSnapshot;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\BranchWorkingHour;
use App\Models\CustomerProfile;
use App\Models\IdempotencyRecord;
use App\Models\StaffProfile;
use App\Services\Finance\FinancialPolicyService;
use App\Services\Operations\LoyaltyService;
use App\Services\Platform\PlatformSettingsService;
use App\Services\Pricing\QuoteService;
use App\Services\Promotions\PromotionPricingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** An ordered cart is one booking with real service lines, never a synthetic combo. */
class CartBookingService
{
    public function __construct(private BookingAccessService $access, private BookingPolicyService $policy,
        private QuoteService $quotes, private AvailabilityService $availability,
        private PromotionPricingService $promotions, private LoyaltyService $loyalty) {}

    private function lines(Branch $branch, array $data, bool $lock = false): array
    {
        $ids = array_column($data['items'], 'branch_service_offering_id');
        if (! count($ids) || count($ids) > 20 || count(array_unique($ids)) !== count($ids)) {
            throw new DomainException('Chọn từ 1 đến 20 dịch vụ khác nhau.');
        }

        return array_map(fn ($item) => $this->quotes->quote($branch, (int) $item['branch_service_offering_id'],
            isset($item['service_variant_id']) ? (int) $item['service_variant_id'] : null, $lock, null, null, 0,
            isset($data['start_at']) ? CarbonImmutable::parse($data['start_at'], $branch->timezone ?: 'Asia/Ho_Chi_Minh') : null,
            $data['staff_profile_id'] ?? null), $data['items']);
    }

    private function pricing(Branch $branch, array $lines, array $data, ?int $customerId, bool $lock): array
    {
        $subtotal = PromotionPricingService::money(array_sum(array_map(fn ($line) => PromotionPricingService::cents($line['unit_price']), $lines)));
        $items = array_map(fn ($line) => ['branch_service_offering_id' => $line['offering']->id, 'allocated_amount' => $line['unit_price']], $lines);
        $pricing = $this->promotions->quote($branch, $lines[0]['offering']->id, $subtotal, $customerId, $data['voucher_code'] ?? null, $lock, null, $items);
        if ($customerId !== null) {
            $pricing['loyalty'] = $this->loyalty->preview($customerId, $branch, (int) ($data['loyalty_points'] ?? 0), $pricing['final_amount'], $lock);
            $discount = PromotionPricingService::cents($pricing['loyalty']['discount_amount']);
            $pricing['discount_amount'] = PromotionPricingService::money(PromotionPricingService::cents($pricing['discount_amount']) + $discount);
            $pricing['final_amount'] = PromotionPricingService::money(PromotionPricingService::cents($pricing['final_amount']) - $discount);
        } elseif (($data['loyalty_points'] ?? 0) > 0) {
            throw new DomainException('Đổi điểm cần hồ sơ khách hàng.');
        }

        return $pricing + ['subtotal_amount' => $subtotal];
    }

    public function quote(Branch $branch, array $context, array $data): array
    {
        $lines = $this->lines($branch, $data);
        // Validate an explicit provider even before the date step.
        if (! empty($data['staff_profile_id'])) {
            $this->candidates($branch, $lines, $context, $data, false);
        }
        $pricing = $this->pricing($branch, $lines, $data, $context['customer_profile_id'], false);
        $duration = array_sum(array_column($lines, 'duration_minutes'));
        if (! ($data['source_timeline'] ?? false)) {
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $duration += $lines[$i - 1]['buffer_after_minutes'] + $line['buffer_before_minutes'];
                }
            }
        }

        return $pricing + ['currency' => 'VND', 'unit_price' => $pricing['subtotal_amount'], 'duration_minutes' => $duration,
            'buffer_before_minutes' => $lines[0]['buffer_before_minutes'], 'buffer_after_minutes' => $lines[array_key_last($lines)]['buffer_after_minutes'],
            'service_name' => implode(' + ', array_map(fn ($line) => $line['offering']->service->name, $lines)),
            'items' => array_map(fn ($line) => ['branch_service_offering_id' => $line['offering']->id, 'service_variant_id' => $line['variant']?->id,
                'service_name' => $line['offering']->service->name, 'variant_name' => $line['variant']?->name, 'unit_price' => $line['unit_price'],
                'duration_minutes' => $line['duration_minutes'], 'buffer_before_minutes' => $line['buffer_before_minutes'], 'buffer_after_minutes' => $line['buffer_after_minutes']], $lines)];
    }

    private function candidates(Branch $branch, array $lines, array $context, array $data, bool $lock): array
    {
        $query = StaffProfile::query()->where('business_id', $branch->business_id)->where('branch_id', $branch->id)
            ->where('status', 'ACTIVE')->where('is_bookable', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereHas('user', fn ($u) => $u->where('is_active', true)))
            ->when($context['kind'] === 'CUSTOMER', fn ($q) => $q->where('public_visible', true));
        if (! empty($data['staff_profile_id'])) {
            $query->whereKey($data['staff_profile_id']);
        }
        // Generic carts use one provider for the entire itinerary, like the source.
        // Per-service providers belong to actual combos with that assignment mode.
        foreach ($lines as $line) {
            $query->whereHas('offerings', fn ($q) => $q->where('branch_service_offerings.id', $line['offering']->id));
        }
        $ids = $query->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            throw new DomainException('Không có nhân viên phù hợp nhận toàn bộ dịch vụ đã chọn.');
        }
        $result = [];
        foreach ($ids as $id) {
            foreach ($lines as $line) {
                $staff = $this->availability->resolveStaff($branch, $line['offering'], $id, $context['kind'] === 'CUSTOMER', $lock);
            }
            $result[] = $staff;
        }

        return $result;
    }

    private function plan(Branch $branch, array $lines, array $candidates, string $start, ?int $customerId, bool $lock, bool $overbooking = false, bool $contiguous = false, string $source = 'ONLINE_WEB'): array
    {
        $failure = null;
        foreach ($overbooking ? [null] : $candidates as $staff) {
            $cursor = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $start, $branch->timezone ?: 'Asia/Ho_Chi_Minh');
            $items = [];
            try {
                foreach ($lines as $index => $line) {
                    if ($index > 0) {
                        $cursor = $contiguous ? $items[$index - 1]['window']['item_end_at']->setTimezone($cursor->timezone) : $items[$index - 1]['window']['occupied_end_at']->setTimezone($cursor->timezone)->addMinutes($line['buffer_before_minutes']);
                    }
                    $window = $this->availability->check($branch, $staff, $line, $cursor->format('Y-m-d\TH:i'), $customerId, $lock, null, false, $source);
                    $items[] = ['quote' => $line, 'staff_id' => $staff?->id, 'window' => $window];
                }

                return $items;
            } catch (DomainException $exception) {
                $failure = $exception;
            }
        }
        throw $failure ?? new DomainException('Không có nhân viên trống cho toàn bộ dịch vụ.');
    }

    public function slots(Branch $branch, array $context, array $data): array
    {
        $lines = $this->lines($branch, $data);
        $candidates = $this->candidates($branch, $lines, $context, $data, false);
        $timezone = $branch->timezone ?: 'Asia/Ho_Chi_Minh';
        $day = CarbonImmutable::parse($data['date'], $timezone)->startOfDay();
        $settings = app(PlatformSettingsService::class)->get();
        $now = CarbonImmutable::now($timezone);
        if ($day->endOfDay()->lessThan($now) || $day->greaterThan($now->addDays($settings['maxAdvanceBookingDays']))) {
            throw new DomainException('Ngày đã chọn nằm ngoài khoảng đặt lịch.');
        }
        $hours = BranchWorkingHour::where('branch_id', $branch->id)->where('day_of_week', $day->dayOfWeek)->first();
        if (Schema::hasTable('beautybook_branch_calendar')) {
            $hours = DB::table('beautybook_branch_calendar')->where('branch_id', $branch->id)->where('date', $data['date'])->first() ?? $hours;
        }
        $slots = [];
        if ($hours && ! $hours->is_closed && $hours->open_time && $hours->close_time) {
            $cursor = CarbonImmutable::parse($data['date'].' '.$hours->open_time, $timezone)->addMinutes($lines[0]['buffer_before_minutes']);
            $close = CarbonImmutable::parse($data['date'].' '.$hours->close_time, $timezone);
            for ($attempt = 0; $attempt < 96 && $cursor->lessThan($close); $attempt++, $cursor = $cursor->addMinutes(15)) {
                try {
                    $plan = $this->plan($branch, $lines, $candidates, $cursor->format('Y-m-d\TH:i'), $context['customer_profile_id'], false, false, $data['source_timeline'] ?? false, $context['kind'] === 'CUSTOMER' ? 'ONLINE_WEB' : ($data['source'] ?? 'STAFF_CREATED'));
                    $last = $plan[array_key_last($plan)]['window'];
                    $slots[] = ['value' => $cursor->format('Y-m-d\TH:i'), 'label' => $cursor->format('H:i'), 'end' => $last['local_end_at']->format('H:i'),
                        'staff_assignments' => array_map(fn ($item) => ['branch_service_offering_id' => $item['quote']['offering']->id, 'staff_profile_id' => $item['staff_id']], $plan)];
                } catch (DomainException) { /* Every advertised slot must fit every service and buffer. */
                }
            }
        }

        return ['slots' => $slots, 'timezone' => $timezone];
    }

    public function previewPlan(Branch $branch, array $context, array $data, bool $lock = false): array
    {
        $lines = $this->lines($branch, $data, $lock);

        return $this->plan($branch, $lines, $this->candidates($branch, $lines, $context, $data, $lock), $data['start_at'], $context['customer_profile_id'], $lock, false, $data['source_timeline'] ?? false);
    }

    public function create(Branch $branch, array $context, array $data): array
    {
        $payload = ['cart_version' => 1, 'branch_id' => $branch->id, 'workspace_key' => $context['workspace_key'], 'data' => collect($data)->except('idempotency_key')->all()];
        ksort($payload['data']);
        foreach ($payload['data']['items'] as &$item) {
            ksort($item);
        }
        unset($item);
        if (isset($payload['data']['contact'])) {
            ksort($payload['data']['contact']);
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($branch, $context, $data, $hash) {
            DB::table('idempotency_records')->insertOrIgnore(['actor_user_id' => $context['actor_user_id'], 'workspace_key' => $context['workspace_key'], 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'status' => 'PROCESSING', 'created_at' => now(), 'updated_at' => now()]);
            $record = IdempotencyRecord::where('actor_user_id', $context['actor_user_id'])->where('workspace_key', $context['workspace_key'])->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->firstOrFail();
            if (! hash_equals($record->payload_hash, $hash)) {
                throw new BookingConflictException('Idempotency-Key đã được dùng cho nội dung đặt lịch khác.');
            }
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $business = $branch->business()->lockForUpdate()->firstOrFail();
            $this->access->assertCurrentBranch($branch, $business, $context);
            if ($record->status === 'COMPLETED' && $record->booking_id) {
                return ['booking' => Booking::whereKey($record->booking_id)->where('branch_id', $branch->id)->with(['services', 'contact', 'pricingSnapshot'])->firstOrFail(), 'replayed' => true];
            }
            $customerId = $context['customer_profile_id'];
            if ($customerId !== null) {
                CustomerProfile::whereKey($customerId)->where('user_id', $context['actor_user_id'])->lockForUpdate()->firstOrFail();
                $this->policy->assertSelfBookingAllowed($customerId, $branch->business_id, ($data['violation_acknowledged'] ?? false) === true);
            }
            $lines = $this->lines($branch, $data, true);
            $overbooking = ($data['controlled_overbooking'] ?? false) === true;
            if ($overbooking) {
                abort_unless(in_array($context['kind'], ['OWNER', 'RECEPTIONIST'], true), 403);
                if (! empty($data['staff_profile_id'])) {
                    throw new DomainException('Overbooking phải để chưa phân công; không bỏ qua xung đột nhân viên.');
                }if (mb_strlen(trim($data['overbooking_reason'] ?? '')) < 10) {
                    throw new DomainException('Lý do overbooking phải có ít nhất 10 ký tự.');
                }$settings = DB::table('beautybook_branch_settings')->where('branch_id', $branch->id)->lockForUpdate()->first();
                $policy = $settings ? json_decode($settings->booking_policy ?? '{}', true) : [];
                if (! ($policy['overbookingEnabled'] ?? false) || ($policy['maxOverbookedSlots'] ?? 0) < 1) {
                    throw new BookingConflictException('Chi nhánh chưa bật chính sách overbooking có kiểm soát.');
                }
            }
            $candidates = $overbooking ? [] : $this->candidates($branch, $lines, $context, $data, true);
            $items = $this->plan($branch, $lines, $candidates, $data['start_at'], $customerId, true, $overbooking, $data['source_timeline'] ?? false, $context['kind'] === 'CUSTOMER' ? 'ONLINE_WEB' : ($data['source'] ?? 'STAFF_CREATED'));
            if ($overbooking) {
                $count = Booking::where('branch_id', $branch->id)->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'])->where('appointment_start_at', '<', $items[array_key_last($items)]['window']['item_end_at'])->where('appointment_end_at', '>', $items[0]['window']['item_start_at'])->whereHas('pricingSnapshot', fn ($q) => $q->where('source_snapshot->controlled_overbooking', true))->count();
                if ($count >= $policy['maxOverbookedSlots']) {
                    throw new BookingConflictException('Đã đạt giới hạn lịch overbooking trong khung giờ này.');
                }
            }
            // AUTO assignment is selected under the same locks as availability; staff-specific rules use that provider.
            $pricedData = $data;
            $pricedData['staff_profile_id'] = $items[0]['staff_id'];
            $lines = $this->lines($branch, $pricedData, true);
            foreach ($items as $index => &$item) {
                $item['quote'] = $lines[$index];
            } unset($item);
            $pricing = $this->pricing($branch, $lines, $data, $customerId, true);
            $source = match ($context['kind']) {
                'CUSTOMER' => 'ONLINE_WEB', 'OWNER' => 'STAFF_CREATED', 'RECEPTIONIST' => in_array($data['source'] ?? null, ['WALK_IN', 'PHONE'], true) ? $data['source'] : throw new DomainException('Lễ tân phải chọn kênh WALK_IN hoặc PHONE.'), default => throw new DomainException('Vai trò không được phép tạo lịch.')
            };
            $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customerId, 'created_by_user_id' => $context['actor_user_id'],
                'booking_code' => 'BB-'.now()->format('Ymd').'-'.Str::upper(Str::random(12)), 'appointment_start_at' => $items[0]['window']['item_start_at'], 'appointment_end_at' => $items[array_key_last($items)]['window']['item_end_at'],
                'status' => 'PENDING', 'source' => $source, 'pending_expires_at' => now()->addMinutes((int) app(PlatformSettingsService::class)->value('pendingHoldMinutes')), 'currency' => 'VND', 'total_amount' => $pricing['final_amount'], 'note' => $data['note'] ?? null]);
            $this->promotions->reserve($booking, $pricing);
            $this->loyalty->redeem($booking, $pricing);
            $snapshots = [];
            foreach ($items as $index => $item) {
                $quote = $item['quote'];
                $offering = $quote['offering'];
                $variant = $quote['variant'];
                $window = $item['window'];
                BookingService::create(['booking_id' => $booking->id, 'business_id' => $branch->business_id, 'branch_id' => $branch->id, 'branch_service_offering_id' => $offering->id,
                    'business_service_id' => $offering->business_service_id, 'canonical_service_id' => $offering->service->canonical_service_id, 'staff_profile_id' => $item['staff_id'], 'service_variant_id' => $variant?->id,
                    'price_at_booking' => $quote['unit_price'], 'currency' => 'VND', 'duration_minutes' => $quote['duration_minutes'], 'buffer_before_minutes' => $quote['buffer_before_minutes'], 'buffer_after_minutes' => $quote['buffer_after_minutes'],
                    'service_name_snapshot' => $offering->service->name, 'variant_name_snapshot' => $variant?->name, 'sort_order' => $index, 'status' => 'SCHEDULED', 'revision' => 1,
                    'item_start_at' => $window['item_start_at'], 'item_end_at' => $window['item_end_at'], 'occupied_start_at' => $window['occupied_start_at'], 'occupied_end_at' => $window['occupied_end_at']]);
                $snapshots[] = ['branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id, 'service_name' => $offering->service->name,
                    'service_variant_id' => $variant?->id, 'variant_name' => $variant?->name, 'staff_profile_id' => $item['staff_id'], 'unit_price' => $quote['unit_price'], 'allocated_amount' => $quote['unit_price'],
                    'duration_minutes' => $quote['duration_minutes'], 'buffer_before_minutes' => $quote['buffer_before_minutes'], 'buffer_after_minutes' => $quote['buffer_after_minutes'], 'sort_order' => $index,
                    'item_start_at' => $window['item_start_at']->toISOString(), 'item_end_at' => $window['item_end_at']->toISOString()];
            }
            app(FinancialPolicyService::class)->capturePolicy($booking);
            BookingPricingSnapshot::create(['booking_id' => $booking->id, 'currency' => 'VND', 'subtotal_amount' => $pricing['subtotal_amount'], 'discount_amount' => $pricing['discount_amount'], 'final_amount' => $pricing['final_amount'], 'quote_version' => 'cart-v1',
                'source_snapshot' => ['staff_assignment_mode' => empty($data['staff_profile_id']) ? 'AUTO' : 'EXPLICIT', 'contact_consent' => ($data['contact_consent'] ?? false) === true, 'contact_consent_at' => ($data['contact_consent'] ?? false) ? now()->toIso8601String() : null, 'controlled_overbooking' => $overbooking, 'overbooking_reason' => $data['overbooking_reason'] ?? null, 'items' => $snapshots, 'discount_rules' => $pricing['discount_rules'], 'service_price_rules' => array_column($lines, 'service_price_rules'), 'loyalty' => $pricing['loyalty'] ?? null]]);
            BookingStatusHistory::create(['booking_id' => $booking->id, 'actor_user_id' => $context['actor_user_id'], 'from_status' => null, 'to_status' => 'PENDING', 'reason' => 'CART_BOOKING_CREATED']);
            if ($customerId === null || ($data['contact_consent'] ?? false) === true) {
                $contact = $data['contact'] ?? [];
                if (trim($contact['full_name'] ?? '') === '' || (trim($contact['phone'] ?? '') === '' && trim($contact['email'] ?? '') === '')) {
                    throw new DomainException('Đặt lịch vận hành cần tên khách và số điện thoại hoặc email.');
                }
                BookingContact::create(['booking_id' => $booking->id, 'full_name' => trim($contact['full_name']), 'phone' => $contact['phone'] ?? null, 'email' => $contact['email'] ?? null]);
            }
            app(BookingNotificationService::class)->record($context['actor_user_id'], 'BOOKING_CREATED', 'Đặt lịch thành công', 'Lịch hẹn '.$booking->booking_code.' đang chờ xác nhận.', $booking->id, 'cart-created:'.$booking->id);
            $record->update(['status' => 'COMPLETED', 'booking_id' => $booking->id, 'http_status' => 201]);

            return ['booking' => $booking->load(['services', 'contact', 'pricingSnapshot']), 'replayed' => false];
        }, 3);
    }
}
