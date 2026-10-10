<?php

namespace App\Services\Beautybook;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BookingPresenter
{
    public const LABELS = ['PENDING' => 'Mới', 'CONFIRMED' => 'Đã xác nhận', 'CHECKED_IN' => 'Đã check-in', 'IN_PROGRESS' => 'Đang thực hiện', 'COMPLETED' => 'Hoàn thành', 'CANCELLED' => 'Đã huỷ', 'NO_SHOW' => 'No-show', 'REJECTED' => 'Đã từ chối', 'EXPIRED' => 'Đã hết hạn'];

    public function item(BookingService $item): array
    {
        return ['id' => (string) $item->id, 'bookingServiceId' => (string) $item->id, 'bookingId' => (string) $item->booking_id,
            'serviceId' => (string) $item->branch_service_offering_id, 'staffId' => $item->staff_profile_id ? (string) $item->staff_profile_id : null,
            'variantId' => $item->service_variant_id ? (string) $item->service_variant_id : null, 'revision' => (int) $item->revision,
            'status' => $item->status, 'sortOrder' => $item->sort_order, 'priceAtBooking' => $item->price_at_booking,
            'durationMinutes' => $item->duration_minutes, 'itemStartAt' => $item->item_start_at?->toIso8601String(),
            'itemEndAt' => $item->item_end_at?->toIso8601String(), 'actualStartedAt' => $item->actual_started_at ? CarbonImmutable::parse($item->actual_started_at, 'UTC')->toIso8601String() : null, 'actualCompletedAt' => $item->actual_completed_at ? CarbonImmutable::parse($item->actual_completed_at, 'UTC')->toIso8601String() : null, 'actualTimingStatus' => $item->actual_timing_status ?? 'UNKNOWN', 'actualTimingSource' => DB::table('beautybook_booking_edits')->where('booking_service_id', $item->id)->where('action', 'ACTUAL_TIME')->exists() ? 'ACTUAL_TIME_CORRECTION' : 'SERVICE_LIFECYCLE',
            'service' => ['id' => (string) $item->branch_service_offering_id, 'name' => $item->service_name_snapshot, 'duration' => $item->duration_minutes],
            'staff' => $item->staffProfile ? ['id' => (string) $item->staff_profile_id, 'fullName' => $item->staffProfile->full_name ?? $item->staffProfile->user?->name, 'userId' => $item->staffProfile->user_id ? (string) $item->staffProfile->user_id : null, 'user' => $item->staffProfile->user ? ['id' => (string) $item->staffProfile->user_id, 'fullName' => $item->staffProfile->user->name] : null] : null];
    }

    public function detail(Booking $booking): array
    {
        $booking->loadMissing(['branch.business', 'customerProfile.user', 'services.staffProfile.user', 'contact', 'statusHistory', 'changeRequests', 'pricingSnapshot']);
        $start = $booking->appointment_start_at;
        $end = $booking->appointment_end_at;
        $user = $booking->customerProfile?->user;
        $zone = $booking->branch?->timezone ?: 'Asia/Ho_Chi_Minh';
        $localStart = $start?->copy()->setTimezone($zone);
        $localEnd = $end?->copy()->setTimezone($zone);
        $request = request();
        $assignment = $request->user() ? app(ApiScope::class)->current($request) : null;
        $grants = $request->user() ? app(ApiScope::class)->assignments($request) : collect();
        $atBranch = $grants->filter(fn ($a) => (int) $a->business_id === (int) $booking->business_id && ($a->role_name === 'BUSINESS_OWNER' || (int) $a->branch_id === (int) $booking->branch_id));
        $staffOnly = $atBranch->contains('role_name', 'STAFF') && ! $atBranch->whereIn('role_name', ['BUSINESS_OWNER', 'RECEPTIONIST'])->isNotEmpty();
        $services = $booking->services->sortBy('sort_order');
        if ($staffOnly) {
            $profile = StaffProfile::where('user_id', $request->user()->id)->where('branch_id', $booking->branch_id)->value('id');
            $services = $services->where('staff_profile_id', $profile);
        }
        $canCorrect = $atBranch->contains('role_name', 'BUSINESS_OWNER');

        return ['id' => (string) $booking->id, 'bookingCode' => $booking->booking_code, 'branchId' => (string) $booking->branch_id,
            'customerId' => $booking->customer_profile_id ? (string) $booking->customer_profile_id : null, 'status' => $booking->status,
            'appointmentDate' => $localStart?->format('Y-m-d\T00:00:00.000\Z'), 'appointmentStartTime' => $localStart?->format('1970-01-01\TH:i:s.000\Z'),
            'appointmentEndTime' => $localEnd?->format('1970-01-01\TH:i:s.000\Z'), 'appointmentStartAt' => $start?->toIso8601String(), 'appointmentEndAt' => $end?->toIso8601String(), 'totalAmount' => $booking->total_amount, 'finalAmount' => $booking->total_amount, 'source' => $booking->source, 'note' => $booking->note, 'serverNow' => now()->toIso8601String(),
            'customerName' => $booking->contact?->full_name ?? $user?->name, 'customerPhone' => $staffOnly ? null : ($booking->contact?->phone ?? $booking->customerProfile?->phone), 'overbookingOverride' => ($booking->pricingSnapshot?->source_snapshot['controlled_overbooking'] ?? false) ? ['reason' => $booking->pricingSnapshot->source_snapshot['overbooking_reason'] ?? null] : null,
            'guestName' => $booking->contact?->full_name, 'guestPhone' => $staffOnly ? null : $booking->contact?->phone, 'createdAt' => $booking->created_at?->toIso8601String(),
            'branch' => ['id' => (string) $booking->branch_id, 'name' => $booking->branch?->name, 'address' => $booking->branch?->address, 'timezone' => $booking->branch?->timezone ?: 'Asia/Ho_Chi_Minh',
                'businessId' => (string) $booking->business_id, 'business' => ['id' => (string) $booking->business_id, 'name' => $booking->branch?->business?->name]],
            'customer' => $user ? ['id' => (string) $booking->customer_profile_id, 'user' => ['id' => (string) $user->id, 'fullName' => $booking->contact?->full_name ?? $user->name, 'email' => $staffOnly ? null : $user->email, 'phone' => $staffOnly ? null : ($booking->contact?->phone ?? $booking->customerProfile?->phone)]] : null,
            'bookingServices' => $services->map(fn ($i) => $this->item($i) + ['canCorrectActualTime' => $canCorrect && $i->staffProfile?->user_id !== $request->user()?->id && in_array($i->status, ['SCHEDULED', 'COMPLETED', 'IN_PROGRESS'], true) && in_array($booking->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'COMPLETED'], true)])->values(),
            'statusHistory' => $booking->statusHistory->map(fn ($h) => ['id' => (string) $h->id, 'fromStatus' => $h->from_status, 'toStatus' => $h->to_status, 'note' => $h->reason, 'createdAt' => $h->created_at?->toIso8601String()]),
            'changeRequests' => $booking->changeRequests->map(fn ($c) => ['id' => (string) $c->id, 'bookingId' => (string) $c->booking_id, 'requestType' => $c->request_type, 'status' => $c->status, 'reason' => $c->reason, 'reviewNote' => $c->review_note, 'createdAt' => $c->created_at?->toIso8601String(), 'expiresAt' => $c->expires_at]),
            'readyToComplete' => $booking->services->every(fn ($i) => in_array($i->status, ['COMPLETED', 'SKIPPED', 'CANCELLED'], true)), 'transitionAvailability' => $this->transitions($booking)];
    }

    private function transitions(Booking $booking): array
    {
        $target = ['CONFIRMED' => 'CHECKED_IN', 'CHECKED_IN' => 'IN_PROGRESS', 'IN_PROGRESS' => 'COMPLETED'][$booking->status] ?? null;
        if (! $target) {
            return [];
        }
        $allowed = CarbonImmutable::now('UTC')->greaterThanOrEqualTo($target === 'COMPLETED' ? $booking->appointment_end_at : $booking->appointment_start_at);

        return [$target => ['allowed' => $allowed, 'reason' => $allowed ? null : 'Chưa đến thời gian thực hiện thao tác.']];
    }

    public function row(Booking $booking): array
    {
        $d = $this->detail($booking);

        return ['id' => $d['id'], 'booking_code' => $d['bookingCode'], 'customer_name' => $d['customer']['user']['fullName'] ?? $d['guestName'],
            'customer_phone' => $d['customer']['user']['phone'] ?? $d['guestPhone'], 'customer_id' => $d['customerId'], 'branch_id' => $d['branchId'],
            'branch_name' => $d['branch']['name'], 'services' => collect($d['bookingServices'])->map(fn ($i) => $i + ['name' => $i['service']['name'], 'price' => $i['priceAtBooking'], 'duration' => $i['durationMinutes']]),
            'appointment_time' => $d['appointmentDate'], 'appointment_start' => $d['appointmentStartTime'], 'appointment_end' => $d['appointmentEndTime'],
            'status' => self::LABELS[$booking->status] ?? $booking->status, 'statusEnum' => $booking->status, 'source' => $booking->source, 'total_amount' => $booking->total_amount, 'note' => $booking->note, 'created_at' => $d['createdAt']] + $d;
    }
}
