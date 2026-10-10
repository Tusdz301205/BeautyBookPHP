<?php

namespace App\Services\Privacy;

use App\Enums\AccountKind;
use App\Models\Booking;
use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PrivacyService
{
    public function customer(Request $request): CustomerProfile
    {
        abort_unless($request->user()?->account_kind === AccountKind::Customer, 403);
        return CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function requestData(Request $request, array $data): object
    {
        $customer = $this->customer($request);
        return DB::transaction(function () use ($customer, $data) {
            CustomerProfile::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $duplicate = DB::table('data_subject_requests')->where('customer_profile_id', $customer->id)
                ->where('type', $data['type'])->whereIn('status', ['RECEIVED', 'IDENTITY_VERIFICATION', 'IN_PROGRESS'])->first();
            if ($duplicate) {
                $duplicate->duplicate = true;
                return $duplicate;
            }
            $id = DB::table('data_subject_requests')->insertGetId([
                'customer_profile_id' => $customer->id, 'type' => $data['type'], 'status' => 'RECEIVED',
                'reason' => $data['reason'] ?? null, 'deadline_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('data_subject_requests')->where('id', $id)->first();
        }, 3);
    }

    public function export(Request $request, string $password): array
    {
        $customer = $this->customer($request);
        abort_unless(Hash::check($password, $request->user()->password), 403, 'Mật khẩu hiện tại không đúng.');
        $token = bin2hex(random_bytes(32));
        return DB::transaction(function () use ($customer, $request, $token) {
            CustomerProfile::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $bookings = Booking::where('customer_profile_id', $customer->id)
                ->with([
                    'branch:id,business_id,public_name,address_line,timezone', 'branch.business:id,name', 'pricingSnapshot',
                    'services' => fn ($query) => $query->select(['id','booking_id','branch_service_offering_id','staff_profile_id','service_name_snapshot','variant_name_snapshot','price_at_booking','duration_minutes','item_start_at','item_end_at','status'])->with('staffProfile:id,full_name'),
                    'statusHistory' => fn ($query) => $query->select(['id', 'booking_id', 'from_status', 'to_status', 'reason', 'created_at']),
                ])->latest()->get(['id', 'branch_id', 'booking_code', 'appointment_start_at', 'appointment_end_at', 'status', 'source', 'currency', 'total_amount', 'created_at']);
            $payments = DB::table('payment_transactions')->whereIn('booking_id', $bookings->modelKeys())
                ->get(['id', 'booking_id', 'amount_cents', 'currency', 'method', 'status', 'settlement_reference', 'verified_at', 'created_at']);
            $payload = [
                'account' => $request->user()->only(['id', 'name', 'email', 'email_verified_at', 'created_at']),
                'customer_profile' => $customer->only(['id', 'phone', 'date_of_birth']),
                'bookings' => $bookings->map(fn ($booking) => array_intersect_key($booking->toArray(), array_flip(['id','branch_id','booking_code','appointment_start_at','appointment_end_at','status','currency','total_amount','created_at','branch','services','status_history']))),
                'payments' => $payments,
                'refunds' => DB::table('refund_requests')->whereIn('payment_transaction_id', $payments->pluck('id'))->get(['id', 'payment_transaction_id', 'amount_cents', 'reason', 'status', 'settlement_reference', 'failure_reason', 'processed_at', 'created_at']),
                'reviews' => DB::table('reviews')->where('customer_profile_id', $customer->id)->get(['id', 'booking_id', 'overall_rating', 'comment', 'is_anonymous', 'service_ratings', 'business_reply', 'status', 'created_at']),
                'notifications' => DB::table('notifications')->where('user_id', $request->user()->id)->get(['type', 'title', 'body', 'is_read', 'created_at']),
                'data_requests' => DB::table('data_subject_requests')->where('customer_profile_id', $customer->id)->get(['type', 'status', 'reason', 'deadline_at', 'completed_at', 'resolution', 'created_at']),
                'marketing_preferences' => DB::table('marketing_preferences')->where('customer_profile_id', $customer->id)->first(),
            ];
            $payload = $this->sourcePayload($payload, $bookings, $request, $customer);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            abort_if(strlen($json) > 2 * 1024 * 1024, 422, 'Gói dữ liệu quá lớn để xuất tức thời.');
            $requestId = DB::table('data_subject_requests')->insertGetId([
                'customer_profile_id' => $customer->id, 'type' => 'EXPORT', 'status' => 'COMPLETED',
                'identity_verified_at' => now(), 'deadline_at' => now()->addDays(30), 'completed_at' => now(),
                'resolution' => 'Đã tạo gói dữ liệu mã hóa.', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $expires = now()->addMinutes(15);
            $id = DB::table('privacy_export_packages')->insertGetId([
                'customer_profile_id' => $customer->id, 'request_id' => $requestId,
                'download_token_hash' => hash('sha256', $token), 'payload_ciphertext' => Crypt::encryptString($json),
                'expires_at' => $expires, 'created_at' => now(),
            ]);
            $this->audit($request->user()->id, 'CREATE', $id);
            return ['id' => $id, 'download_token' => $token, 'expires_at' => $expires->toIso8601String()];
        }, 3);
    }

    /** Export only actual self-owned records; keep legacy aliases for older course consumers. */
    private function sourcePayload(array $payload, $bookings, Request $request, CustomerProfile $customer): array
    {
        $user = $request->user();
        $profile = DB::table('beautybook_user_profiles')->where('user_id', $user->id)->first();
        $payload['generatedAt'] = now()->toIso8601String();
        $payload['formatVersion'] = 'beautybook-privacy-export-v1';
        $payload['profile'] = [
            'id' => (string) $user->id, 'email' => $user->email, 'fullName' => $user->name,
            'phone' => $profile?->phone ?? $customer->phone, 'address' => $profile?->address ?? $customer->address,
            'gender' => $profile?->gender, 'dateOfBirth' => $profile?->date_of_birth ?? $customer->date_of_birth,
            'isEmailVerified' => (bool) $user->email_verified_at, 'isPhoneVerified' => (bool) $user->phone_verified_at,
            'createdAt' => $user->created_at?->toIso8601String(), 'updatedAt' => $user->updated_at?->toIso8601String(),
        ];
        $legacyBookings = $payload['bookings'];
        $payload['bookings'] = $bookings->values()->map(function ($booking, $index) use ($payload, $legacyBookings) {
            $local = $booking->appointment_start_at->copy()->timezone($booking->branch->timezone);
            $end = $booking->appointment_end_at->copy()->timezone($booking->branch->timezone);
            $cancel = $booking->statusHistory->last(fn ($event) => $event->to_status === 'CANCELLED');
            return [...$legacyBookings[$index],
                'id' => (string) $booking->id, 'bookingCode' => $booking->booking_code,
                'appointmentDate' => $local->toDateString(), 'appointmentStartTime' => $local->format('1970-01-01\TH:i:s.000\Z'), 'appointmentEndTime' => $end->format('1970-01-01\TH:i:s.000\Z'),
                'source' => $booking->source, 'totalAmount' => (float) ($booking->pricingSnapshot?->subtotal_amount ?? $booking->total_amount),
                'finalAmount' => (float) ($booking->pricingSnapshot?->final_amount ?? $booking->total_amount),
                'voucherDiscountAmount' => (float) DB::table('promotion_redemptions')->where('booking_id', $booking->id)->where('status', 'APPLIED')->sum('amount'),
                'cancelReason' => $cancel?->reason, 'createdAt' => $booking->created_at?->toIso8601String(),
                'branch' => [...($legacyBookings[$index]['branch'] ?? []), 'id' => (string) $booking->branch_id, 'name' => $booking->branch->name],
                'bookingServices' => $booking->services->map(fn ($item) => ['serviceId' => (string) $item->branch_service_offering_id, 'priceAtBooking' => (float) $item->price_at_booking, 'durationMinutes' => $item->duration_minutes, 'service' => ['name' => $item->service_name_snapshot], 'staff' => $item->staffProfile ? ['fullName' => $item->staffProfile->full_name] : null])->all(),
                'statusHistory' => $booking->statusHistory->map(fn ($event) => ['status' => $event->to_status, 'note' => $event->reason, 'createdAt' => $event->created_at?->toIso8601String()])->all(),
                'payments' => $payload['payments']->where('booking_id', $booking->id)->map(function ($payment) use ($payload) {
                    return ['id' => (string) $payment->id, 'amount' => $payment->amount_cents / 100, 'method' => $payment->method, 'status' => $payment->status, 'transactionRef' => $payment->settlement_reference, 'paidAt' => $payment->verified_at, 'createdAt' => $payment->created_at,
                        'refundRequests' => $payload['refunds']->where('payment_transaction_id', $payment->id)->map(fn ($refund) => ['id' => (string) $refund->id, 'amount' => $refund->amount_cents / 100, 'reason' => $refund->reason, 'status' => $refund->status, 'settlementReference' => $refund->settlement_reference, 'failureReason' => $refund->failure_reason, 'processedAt' => $refund->processed_at, 'createdAt' => $refund->created_at])->values()->all()];
                })->values()->all(),
            ];
        })->all();
        $payload['reviews'] = $payload['reviews']->map(function ($review) {
            $ratings = json_decode($review->service_ratings ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            return [...(array) $review, 'id' => (string) $review->id, 'bookingId' => (string) $review->booking_id, 'overallRating' => $review->overall_rating, 'isAnonymous' => (bool) $review->is_anonymous, 'createdAt' => $review->created_at,
                'serviceRatings' => array_map(fn ($rating) => ['rating' => $rating['rating'], 'comment' => $rating['comment'] ?? null, 'bookingServiceId' => (string) ($rating['booking_service_id'] ?? '')], $ratings)];
        })->all();
        $dto = app(\App\Services\Beautybook\GovernancePresenter::class);
        $payload['dataSubjectRequests'] = DB::table('data_subject_requests')->where('customer_profile_id', $customer->id)->latest()->get(['id','type','status','reason','identity_verified_at','resolution','deadline_at','completed_at','created_at'])->map(fn ($row) => [...$dto->row($row), 'legalHoldReason' => null])->all();
        $preferences = $payload['marketing_preferences'];
        $payload['marketingPreferences'] = ['emailMarketing' => (bool) ($preferences?->email_marketing ?? false), 'smsMarketing' => (bool) ($preferences?->sms_marketing ?? false), 'pushMarketing' => (bool) ($preferences?->push_marketing ?? false), 'personalizedPromotions' => (bool) ($preferences?->personalized_promotions ?? false)];
        if ($preferences) $payload['marketingPreferences']['updatedAt'] = $dto->row($preferences)['updatedAt'];
        $payload['notifications'] = DB::table('notifications')->where('user_id', $user->id)->latest()->get(['id','type','severity','title','body','is_read','read_at','target_type','target_id','related_booking_id','created_at'])->map(function ($row) use ($dto) { $result = $dto->row($row); $result['isRead'] = (bool) $row->is_read; return $result; })->all();
        return $payload;
    }

    public function download(Request $request, int $id, string $token): string
    {
        $customer = $this->customer($request);
        return DB::transaction(function () use ($request, $customer, $id, $token) {
            $row = DB::table('privacy_export_packages')->where('id', $id)->where('customer_profile_id', $customer->id)
                ->where('download_token_hash', hash('sha256', $token))->where('expires_at', '>', now())
                ->whereNull('downloaded_at')->lockForUpdate()->first();
            abort_unless($row, 404, 'Gói dữ liệu không còn khả dụng.');
            $json = Crypt::decryptString($row->payload_ciphertext);
            DB::table('privacy_export_packages')->where('id', $id)->update(['downloaded_at' => now()]);
            $this->audit($request->user()->id, 'READ', $id);
            return $json;
        }, 3);
    }

    private function audit(int $userId, string $action, int $id): void
    {
        DB::table('audit_logs')->insert(['user_id' => $userId, 'action' => $action, 'entity_type' => 'PrivacyExportPackage', 'entity_id' => (string)$id, 'created_at' => now()]);
    }

    public function cleanup(): int
    {
        return DB::table('privacy_export_packages')->where('expires_at', '<=', now())
            ->orWhere('downloaded_at', '<', now()->subDay())->delete();
    }
}
