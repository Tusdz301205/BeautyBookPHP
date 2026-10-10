<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\BookingContact;
use App\Models\BookingPricingSnapshot;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\IdempotencyRecord;
use App\Services\Operations\LoyaltyService;
use App\Services\Platform\PlatformSettingsService;
use App\Services\Pricing\QuoteService;
use App\Services\Promotions\PromotionPricingService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateBookingService
{
    public function __construct(
        private BookingAccessService $access,
        private QuoteService $quotes,
        private AvailabilityService $availability,
        private BookingPolicyService $policy,
    ) {}

    public function create(Branch $branch, array $context, array $data): array
    {
        $payloadHash = $this->payloadHash($branch, $context, $data);

        return DB::transaction(function () use ($branch, $context, $data, $payloadHash) {
            DB::table('idempotency_records')->insertOrIgnore([
                'actor_user_id' => $context['actor_user_id'],
                'workspace_key' => $context['workspace_key'],
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash,
                'status' => 'PROCESSING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $idempotency = IdempotencyRecord::query()
                ->where('actor_user_id', $context['actor_user_id'])
                ->where('workspace_key', $context['workspace_key'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($idempotency->payload_hash, $payloadHash)) {
                throw new BookingConflictException('Idempotency-Key đã được dùng cho một nội dung đặt lịch khác.');
            }

            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $lockedBusiness = Business::query()->whereKey($lockedBranch->business_id)->lockForUpdate()->firstOrFail();
            $this->access->assertCurrentBranch($lockedBranch, $lockedBusiness, $context);

            if ($idempotency->status === 'COMPLETED' && $idempotency->booking_id !== null) {
                $booking = Booking::query()
                    ->whereKey($idempotency->booking_id)
                    ->where('branch_id', $lockedBranch->id)
                    ->where('business_id', $lockedBranch->business_id)
                    ->with(['services', 'contact', 'pricingSnapshot'])
                    ->firstOrFail();

                return ['booking' => $booking, 'replayed' => true, 'http_status' => $idempotency->http_status ?: 201];
            }

            $customerProfileId = null;
            $source = $this->resolveSource($context, $data);
            $contact = $this->resolveContact($context, $data);

            if ($context['kind'] === 'CUSTOMER') {
                $customer = CustomerProfile::query()
                    ->whereKey($context['customer_profile_id'])
                    ->where('user_id', $context['actor_user_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $customer) {
                    throw new DomainException('Hồ sơ khách hàng không còn khả dụng.');
                }

                $customerProfileId = $customer->id;
                $this->policy->assertSelfBookingAllowed(
                    $customer->id,
                    $lockedBranch->business_id,
                    ($data['violation_acknowledged'] ?? null) === true,
                );
            }

            $quote = $this->quotes->quote(
                $lockedBranch,
                (int) $data['branch_service_offering_id'],
                isset($data['service_variant_id']) ? (int) $data['service_variant_id'] : null,
                true,
                $customerProfileId,
                $data['voucher_code'] ?? null,
                (int) ($data['loyalty_points'] ?? 0),
            );

            $staff = $this->availability->resolveStaff(
                $lockedBranch,
                $quote['offering'],
                (int) $data['staff_profile_id'],
                $context['kind'] === 'CUSTOMER',
                true,
            );

            $window = $this->availability->check(
                $lockedBranch,
                $staff,
                $quote,
                $data['start_at'],
                $customerProfileId,
                true,
            );

            $booking = Booking::query()->create([
                'business_id' => $lockedBranch->business_id,
                'branch_id' => $lockedBranch->id,
                'customer_profile_id' => $customerProfileId,
                'created_by_user_id' => $context['actor_user_id'],
                'booking_code' => $this->bookingCode(),
                'appointment_start_at' => $window['item_start_at'],
                'appointment_end_at' => $window['item_end_at'],
                'status' => 'PENDING',
                'source' => $source,
                'pending_expires_at' => now()->addMinutes((int) app(PlatformSettingsService::class)->value('pendingHoldMinutes')),
                'currency' => $quote['currency'],
                'total_amount' => $quote['final_amount'],
                'note' => $data['note'] ?? null,
            ]);

            $service = $quote['offering']->service;
            app(PromotionPricingService::class)->reserve($booking, $quote);
            app(LoyaltyService::class)->redeem($booking, $quote);
            $variant = $quote['variant'];

            BookingService::query()->create([
                'booking_id' => $booking->id,
                'business_id' => $lockedBranch->business_id,
                'branch_id' => $lockedBranch->id,
                'branch_service_offering_id' => $quote['offering']->id,
                'business_service_id' => $service->id,
                'canonical_service_id' => $service->canonical_service_id,
                'staff_profile_id' => $staff->id,
                'service_variant_id' => $variant?->id,
                'price_at_booking' => $quote['unit_price'],
                'currency' => $quote['currency'],
                'duration_minutes' => $quote['duration_minutes'],
                'buffer_before_minutes' => $quote['buffer_before_minutes'],
                'buffer_after_minutes' => $quote['buffer_after_minutes'],
                'service_name_snapshot' => $service->name,
                'variant_name_snapshot' => $variant?->name,
                'sort_order' => 0,
                'status' => 'SCHEDULED',
                'item_start_at' => $window['item_start_at'],
                'item_end_at' => $window['item_end_at'],
                'occupied_start_at' => $window['occupied_start_at'],
                'occupied_end_at' => $window['occupied_end_at'],
                'revision' => 1,
            ]);

            app(\App\Services\Finance\FinancialPolicyService::class)->capturePolicy($booking);
            BookingPricingSnapshot::query()->create([
                'booking_id' => $booking->id,
                'currency' => $quote['currency'],
                'subtotal_amount' => $quote['subtotal_amount'],
                'discount_amount' => $quote['discount_amount'],
                'final_amount' => $quote['final_amount'],
                'quote_version' => $quote['quote_version'],
                'source_snapshot' => [
                    'branch_service_offering_id' => $quote['offering']->id,
                    'business_service_id' => $service->id,
                    'canonical_service_id' => $service->canonical_service_id,
                    'service_name' => $service->name,
                    'service_variant_id' => $variant?->id,
                    'variant_name' => $variant?->name,
                    'variant_version' => $variant?->version,
                    'staff_profile_id' => $staff->id,
                    'unit_price' => $quote['unit_price'],
                    'discount_rules' => $quote['discount_rules'] ?? [],
                    'loyalty' => $quote['loyalty'] ?? null,
                    'duration_minutes' => $quote['duration_minutes'],
                    'buffer_before_minutes' => $quote['buffer_before_minutes'],
                    'buffer_after_minutes' => $quote['buffer_after_minutes'],
                ],
            ]);

            BookingStatusHistory::query()->create([
                'booking_id' => $booking->id,
                'actor_user_id' => $context['actor_user_id'],
                'from_status' => null,
                'to_status' => 'PENDING',
                'reason' => 'BOOKING_CREATED',
            ]);

            if ($contact !== null) {
                BookingContact::query()->create([
                    'booking_id' => $booking->id,
                    'full_name' => $contact['full_name'],
                    'phone' => $contact['phone'],
                    'email' => $contact['email'],
                ]);
            }

            $idempotency->forceFill([
                'status' => 'COMPLETED',
                'booking_id' => $booking->id,
                'http_status' => 201,
            ])->save();

            return [
                'booking' => $booking->load(['services', 'contact', 'pricingSnapshot']),
                'replayed' => false,
                'http_status' => 201,
            ];
        }, 3);
    }

    private function resolveSource(array $context, array $data): string
    {
        return match ($context['kind']) {
            'CUSTOMER' => 'ONLINE_WEB',
            'OWNER' => 'STAFF_CREATED',
            'RECEPTIONIST' => in_array($data['source'] ?? null, ['WALK_IN', 'PHONE'], true)
                ? $data['source']
                : throw new DomainException('Lễ tân phải chọn kênh WALK_IN hoặc PHONE.'),
            default => throw new DomainException('Vai trò hiện tại không được phép tạo lịch.'),
        };
    }

    private function resolveContact(array $context, array $data): ?array
    {
        if ($context['kind'] === 'CUSTOMER') {
            return null;
        }

        $contact = $data['contact'] ?? [];
        $fullName = trim((string) ($contact['full_name'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));

        if ($fullName === '' || ($phone === '' && $email === '')) {
            throw new DomainException('Đặt lịch vận hành cần tên khách và ít nhất số điện thoại hoặc email.');
        }

        return [
            'full_name' => $fullName,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
        ];
    }

    private function payloadHash(Branch $branch, array $context, array $data): string
    {
        $payload = [
            'branch_id' => (int) $branch->id,
            'workspace_key' => $context['workspace_key'],
            'branch_service_offering_id' => (int) $data['branch_service_offering_id'],
            'service_variant_id' => isset($data['service_variant_id']) ? (int) $data['service_variant_id'] : null,
            'voucher_code' => isset($data['voucher_code']) ? strtoupper(trim($data['voucher_code'])) : null,
            'loyalty_points' => (int) ($data['loyalty_points'] ?? 0),
            'staff_profile_id' => (int) $data['staff_profile_id'],
            'start_at' => $data['start_at'],
            'source' => $data['source'] ?? null,
            'note' => $data['note'] ?? null,
            'violation_acknowledged' => $data['violation_acknowledged'] ?? null,
            'contact' => [
                'full_name' => $data['contact']['full_name'] ?? null,
                'phone' => $data['contact']['phone'] ?? null,
                'email' => $data['contact']['email'] ?? null,
            ],
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function bookingCode(): string
    {
        return 'BB-'.now()->format('Ymd').'-'.Str::upper(Str::random(12));
    }
}
