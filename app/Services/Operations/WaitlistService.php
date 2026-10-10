<?php

namespace App\Services\Operations;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\CustomerProfile;
use App\Models\WaitlistEntry;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingAccessService;
use App\Services\Booking\BookingNotificationService;
use App\Services\Booking\CreateBookingService;
use App\Services\Pricing\QuoteService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    public function __construct(private QuoteService $quotes, private AvailabilityService $availability, private CreateBookingService $bookings) {}

    public function join(Branch $branch, array $context, array $data): WaitlistEntry
    {
        abort_unless($context['kind'] === 'CUSTOMER', 403);
        $start = CarbonImmutable::parse($data['window_start'], $branch->timezone)->utc();
        $end = CarbonImmutable::parse($data['window_end'], $branch->timezone)->utc();
        if ($start >= $end || $end <= now('UTC')) {
            throw new DomainException('Khoảng thời gian chờ không hợp lệ.');
        }

        return DB::transaction(function () use ($branch, $context, $data, $start, $end) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if (! Branch::publicReady()->whereKey($branch->id)->exists()) {
                throw new DomainException('Chi nhánh không còn khả dụng.');
            }
            $customer = CustomerProfile::whereKey($context['customer_profile_id'])->where('user_id', $context['actor_user_id'])->lockForUpdate()->firstOrFail();
            $quote = $this->quotes->quote($branch, $data['branch_service_offering_id']);
            if (! empty($data['staff_profile_id'])) {
                $this->availability->resolveStaff($branch, $quote['offering'], $data['staff_profile_id'], true);
            }
            $key = $customer->id.':'.$branch->id.':'.$quote['offering']->id;
            if (WaitlistEntry::where('active_join_key', $key)->exists()) {
                throw new BookingConflictException('Bạn đã ở trong danh sách chờ cho dịch vụ này.');
            }

            return WaitlistEntry::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->id, 'branch_service_offering_id' => $quote['offering']->id, 'staff_profile_id' => $data['staff_profile_id'] ?? null, 'window_start' => $start, 'window_end' => $end, 'active_join_key' => $key]);
        }, 3);
    }

    public function offer(WaitlistEntry $entry, array $context, array $data, bool $automatic = false): array
    {
        return DB::transaction(function () use ($entry, $context, $data, $automatic) {
            $branch = Branch::whereKey($entry->branch_id)->lockForUpdate()->firstOrFail();
            if (! $automatic) {
                $this->assertCounter($branch, $context);
            } elseif (! Branch::publicReady()->whereKey($branch->id)->exists()) {
                throw new DomainException('Chi nhánh không còn khả dụng.');
            }
            $entry = WaitlistEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'WAITING') {
                throw new BookingConflictException('Khách không còn ở trạng thái chờ.');
            }
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['start_at'], $branch->timezone)->utc();
            if ($start < $entry->window_start || $start > $entry->window_end) {
                throw new DomainException('Slot nằm ngoài khoảng thời gian khách mong muốn.');
            }
            if ($entry->staff_profile_id && (int) $entry->staff_profile_id !== (int) $data['staff_profile_id']) {
                throw new DomainException('Slot không thuộc nhân viên khách đã chọn.');
            }
            $quote = $this->quotes->quote($branch, $entry->branch_service_offering_id, null, true);
            $staff = $this->availability->resolveStaff($branch, $quote['offering'], $data['staff_profile_id'], true, true);
            $this->availability->check($branch, $staff, $quote, $data['start_at'], $entry->customer_profile_id, true);
            $slotKey = $branch->id.':'.$staff->id.':'.$start->format('YmdHi');
            if (WaitlistEntry::where('offer_slot_key', $slotKey)->where('status', 'OFFERED')->where('offer_expires_at', '>', now('UTC'))->exists()) {
                throw new BookingConflictException('Slot đã được đề nghị cho khách khác.');
            }
            // Clear stale keys so the unique index remains an atomic reservation guard.
            WaitlistEntry::where('offer_slot_key', $slotKey)->where('offer_expires_at', '<=', now('UTC'))->update(['status' => 'EXPIRED', 'active_join_key' => null, 'offer_slot_key' => null, 'offer_token_hash' => null]);
            $ttl = min(60, max(3, (int) ($data['ttl_minutes'] ?? 10)));
            $token = bin2hex(random_bytes(32));
            $entry->update(['status' => 'OFFERED', 'staff_profile_id' => $staff->id, 'offered_start_at' => $start, 'offer_expires_at' => now('UTC')->addMinutes($ttl), 'offer_token_hash' => hash('sha256', $token), 'offer_slot_key' => $slotKey]);
            $customer = CustomerProfile::findOrFail($entry->customer_profile_id);
            $url = '/waitlist?offer='.$entry->id.'&token='.rawurlencode($token);
            $dedupe = 'WAITLIST:OFFER:'.$entry->id.':'.$entry->offer_token_hash;
            app(BookingNotificationService::class)->record($customer->user_id, 'SYSTEM', 'Có lịch trống dành cho bạn', 'Đề nghị giữ trong '.$ttl.' phút.', null, $dedupe, 'INFO', ['waitlist_id' => $entry->id, 'action_url' => $url]);
            DB::table('notifications')->where('dedupe_key', $dedupe)->update(['target_type' => 'WAITLIST', 'target_id' => (string) $entry->id, 'action_url' => $url]);

            return ['entry' => $entry->refresh(), 'claim_token' => $token];
        }, 3);
    }

    public function accept(WaitlistEntry $entry, array $context, string $token): Booking
    {
        abort_unless($context['kind'] === 'CUSTOMER' && (int) $entry->customer_profile_id === (int) $context['customer_profile_id'], 403);
        $validated = false;
        try {
            return DB::transaction(function () use ($entry, $context, $token, &$validated) {
                $branch = Branch::whereKey($entry->branch_id)->lockForUpdate()->firstOrFail();
                $entry = WaitlistEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if ($entry->status === 'ACCEPTED' && $entry->booking_id) {
                    return Booking::findOrFail($entry->booking_id);
                }
                if ($entry->status !== 'OFFERED' || ! $entry->offer_expires_at || $entry->offer_expires_at <= now() || ! hash_equals($entry->offer_token_hash ?? '', hash('sha256', $token))) {
                    throw new BookingConflictException('Đề nghị đã hết hạn hoặc token không đúng.');
                }
                $validated = true;
                $result = $this->bookings->create($branch, $context, ['branch_service_offering_id' => $entry->branch_service_offering_id, 'staff_profile_id' => $entry->staff_profile_id, 'start_at' => $entry->offered_start_at->copy()->timezone($branch->timezone)->format('Y-m-d\TH:i'), 'idempotency_key' => 'WAITLIST:ACCEPT:'.$entry->id]);
                $entry->update(['status' => 'ACCEPTED', 'accepted_at' => now(), 'booking_id' => $result['booking']->id, 'active_join_key' => null, 'offer_slot_key' => null, 'offer_token_hash' => null]);

                return $result['booking'];
            }, 3);
        } catch (DomainException $e) {
            if ($validated && WaitlistEntry::whereKey($entry->id)->where('status', 'OFFERED')->where('offer_token_hash', hash('sha256', $token))->update(['status' => 'EXPIRED', 'active_join_key' => null, 'offer_slot_key' => null, 'offer_token_hash' => null]) === 1) {
                $this->offerNext($entry);
            }
            throw $e;
        }
    }

    public function cancel(WaitlistEntry $entry, int $customerId): void
    {
        abort_unless((int) $entry->customer_profile_id === $customerId, 403);
        $released = DB::transaction(function () use ($entry, $customerId) {
            $entry = WaitlistEntry::whereKey($entry->id)->where('customer_profile_id', $customerId)->lockForUpdate()->firstOrFail();
            if (! in_array($entry->status, ['WAITING', 'OFFERED'], true)) {
                throw new BookingConflictException('Yêu cầu chờ không còn có thể hủy.');
            }
            $entry->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'active_join_key' => null, 'offer_slot_key' => null, 'offer_token_hash' => null]);

            return $entry;
        }, 3);
        $this->offerNext($released);
    }

    public function expireOffers(): int
    {
        $entries = WaitlistEntry::where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'OFFERED')->where('offer_expires_at', '<=', now('UTC')))->orWhere(fn ($q) => $q->where('status', 'WAITING')->where('window_end', '<=', now('UTC'))))->orderBy('created_at')->limit(100)->get();
        $count = 0;
        foreach ($entries as $entry) {
            $changed = WaitlistEntry::whereKey($entry->id)->where('status', $entry->status)->where(fn ($q) => $q->where('offer_expires_at', '<=', now('UTC'))->orWhere('window_end', '<=', now('UTC')))->update(['status' => 'EXPIRED', 'active_join_key' => null, 'offer_slot_key' => null, 'offer_token_hash' => null]);
            if ($changed !== 1) {
                continue;
            }
            $count++;
            $this->offerNext($entry);
        }

        return $count;
    }

    private function offerNext(WaitlistEntry $released): void
    {
        if (! $released->staff_profile_id || ! $released->offered_start_at || $released->offered_start_at <= now()) {
            return;
        }
        $branch = Branch::find($released->branch_id);
        if (! $branch) {
            return;
        }
        $candidates = WaitlistEntry::where('branch_id', $released->branch_id)->where('branch_service_offering_id', $released->branch_service_offering_id)->where('status', 'WAITING')->where('window_start', '<=', $released->offered_start_at)->where('window_end', '>=', $released->offered_start_at)->where(fn ($q) => $q->whereNull('staff_profile_id')->orWhere('staff_profile_id', $released->staff_profile_id))->orderBy('created_at')->orderBy('id')->limit(20)->get();
        foreach ($candidates as $candidate) {
            try {
                $this->offer($candidate, [], ['start_at' => $released->offered_start_at->copy()->timezone($branch->timezone)->format('Y-m-d\TH:i'), 'staff_profile_id' => $released->staff_profile_id, 'ttl_minutes' => 10], true);

                return;
            } catch (DomainException) {
                continue;
            }
        }
    }

    private function assertCounter(Branch $branch, array $context): void
    {
        abort_unless(in_array($context['kind'], ['OWNER', 'RECEPTIONIST'], true), 403);
        abort_unless((int) $context['business_id'] === (int) $branch->business_id && ($context['kind'] === 'OWNER' || (int) $context['branch_id'] === (int) $branch->id), 403);
        app(BookingAccessService::class)->assertCurrentBranch($branch, $branch->business, $context);
    }
}
