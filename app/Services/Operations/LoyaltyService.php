<?php

namespace App\Services\Operations;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Business;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Services\Promotions\PromotionPricingService as Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoyaltyService
{
    public function configure(Business $business, int $actorId, array $data): object
    {
        if (! is_int($data['earn_points_per_amount']) || $data['earn_points_per_amount'] < 1 || Money::cents((string) $data['earn_amount_unit']) < 1 || Money::cents((string) $data['redemption_value_per_point']) < 1) {
            throw new DomainException('Cấu hình tích điểm không hợp lệ.');
        }
        // HTML integer fields arrive as strings; the controller normalizes them before this service.
        if (isset($data['valid_from'])) {
            $data['valid_from'] = CarbonImmutable::parse($data['valid_from'])->format('Y-m-d H:i:s');
        }

        return DB::transaction(function () use ($business, $actorId, $data) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $branchId = $data['branch_id'] ?? null;
            if ($branchId && ! Branch::whereKey($branchId)->where('business_id', $business->id)->exists()) {
                throw new DomainException('Chi nhánh không thuộc doanh nghiệp.');
            }
            $query = DB::table('loyalty_rules')->where('business_id', $business->id)->where('branch_id', $branchId);
            $version = (int) (clone $query)->max('version') + 1;
            (clone $query)->where('active', true)->update(['active' => false, 'valid_to' => now(), 'updated_at' => now()]);
            $id = DB::table('loyalty_rules')->insertGetId(array_merge($data, ['business_id' => $business->id, 'branch_id' => $branchId, 'version' => $version, 'active' => true, 'valid_from' => $data['valid_from'] ?? now(), 'created_by_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]));

            return DB::table('loyalty_rules')->find($id);
        }, 3);
    }

    private function activeRule(Branch $branch, bool $lock = false): ?object
    {
        $query = DB::table('loyalty_rules')->where('business_id', $branch->business_id)->where('active', true)->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->where(fn ($q) => $q->where('branch_id', $branch->id)->orWhereNull('branch_id'))->orderByRaw('branch_id IS NULL ASC')->orderByDesc('version');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function account(int $businessId, int $customerId): LoyaltyAccount
    {
        DB::table('loyalty_accounts')->insertOrIgnore(['business_id' => $businessId, 'customer_profile_id' => $customerId, 'balance' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return LoyaltyAccount::where('business_id', $businessId)->where('customer_profile_id', $customerId)->lockForUpdate()->firstOrFail();
    }

    public function preview(int $customerId, Branch $branch, int $requestedPoints, string $amountAfterDiscounts, bool $lock = false): array
    {
        if ($requestedPoints < 0) {
            throw new DomainException('Số điểm không hợp lệ.');
        }
        $empty = ['points' => 0, 'discount_amount' => '0.00', 'rule_snapshot' => null];
        if ($requestedPoints === 0) {
            return $empty;
        }

        return DB::transaction(function () use ($customerId, $branch, $requestedPoints, $amountAfterDiscounts, $lock, $empty) {
            $account = $this->account($branch->business_id, $customerId);
            $this->expireAccount($account);
            $rule = $this->activeRule($branch, $lock);
            if (! $rule) {
                return $empty;
            }
            if ($account->balance < $requestedPoints) {
                throw new BookingConflictException('Số dư điểm không đủ.');
            }
            $unit = Money::cents((string) $rule->redemption_value_per_point);
            $amount = Money::cents($amountAfterDiscounts);
            $points = min($requestedPoints, intdiv($amount, $unit));

            return ['points' => $points, 'discount_amount' => Money::money($points * $unit), 'rule_snapshot' => ['rule_id' => $rule->id, 'version' => $rule->version, 'redemption_value_per_point' => $rule->redemption_value_per_point]];
        }, 3);
    }

    public function redeem(Booking $booking, array $quote): void
    {
        $loyalty = $quote['loyalty'] ?? null;
        if (! $loyalty || $loyalty['points'] <= 0) {
            return;
        }
        $account = $this->account($booking->business_id, $booking->customer_profile_id);
        $key = 'LOYALTY:REDEEM:'.$booking->id;
        if (LoyaltyTransaction::where('idempotency_key', $key)->exists()) {
            return;
        }
        $this->expireAccount($account);
        $rule = $this->activeRule(Branch::findOrFail($booking->branch_id), true);
        if (! $rule || $rule->id !== $loyalty['rule_snapshot']['rule_id'] || $rule->version !== $loyalty['rule_snapshot']['version'] || $account->balance < $loyalty['points']) {
            throw new BookingConflictException('Quy tắc hoặc số dư điểm vừa thay đổi.');
        }
        $account->balance -= $loyalty['points'];
        $account->version++;
        $account->save();
        $this->ledger($account, $booking->id, 'REDEEM', -$loyalty['points'], $key, $loyalty['rule_snapshot'], $booking->created_by_user_id);
    }

    public function reverseRedemption(Booking $booking, ?int $actorId = null): void
    {
        DB::transaction(function () use ($booking, $actorId) {
            $redemption = LoyaltyTransaction::where('booking_id', $booking->id)->where('type', 'REDEEM')->first();
            if (! $redemption) {
                return;
            }
            $account = $this->account($redemption->business_id, $redemption->customer_profile_id);
            $key = 'LOYALTY:REVERSE:REDEEM:'.$booking->id;
            if (LoyaltyTransaction::where('idempotency_key', $key)->exists()) {
                return;
            }
            $account->balance += abs($redemption->points);
            $account->version++;
            $account->save();
            $this->ledger($account, $booking->id, 'REVERSE', abs($redemption->points), $key, $redemption->rule_snapshot, $actorId, ['reversal_of_id' => $redemption->id, 'reason' => 'Hoàn điểm do lịch bị hủy']);
        }, 3);
    }

    public function earnForBooking(int $bookingId, ?int $actorId = null): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($bookingId, $actorId) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->firstOrFail();
            if ($booking->status !== 'COMPLETED' || ! $booking->customer_profile_id) {
                return null;
            }
            $existing = LoyaltyTransaction::where('idempotency_key', 'LOYALTY:EARN:'.$bookingId)->first();
            if ($existing) {
                return $existing;
            }
            $rule = $this->activeRule(Branch::findOrFail($booking->branch_id));
            if (! $rule) {
                return null;
            }
            $paid = $this->netPaidCents($bookingId);
            $valid = min(Money::cents((string) $booking->total_amount), max(0, $paid));
            $points = intdiv($valid, Money::cents((string) $rule->earn_amount_unit)) * $rule->earn_points_per_amount;
            if ($points <= 0) {
                return null;
            }
            $account = $this->account($booking->business_id, $booking->customer_profile_id);
            if ($existing = LoyaltyTransaction::where('idempotency_key', 'LOYALTY:EARN:'.$bookingId)->first()) {
                return $existing;
            }
            $account->balance += $points;
            $account->version++;
            $account->save();

            return $this->ledger($account, $bookingId, 'EARN', $points, 'LOYALTY:EARN:'.$bookingId, ['rule_id' => $rule->id, 'version' => $rule->version, 'valid_amount' => Money::money($valid), 'earn_amount_unit' => $rule->earn_amount_unit, 'earn_points_per_amount' => $rule->earn_points_per_amount], $actorId, ['expires_at' => $rule->expires_after_days ? now()->addDays($rule->expires_after_days) : null]);
        }, 3);
    }

    private function netPaidCents(int $bookingId): int
    {
        if (! Schema::hasTable('payment_transactions')) {
            return 0;
        }
        $payments = DB::table('payment_transactions')->where('booking_id', $bookingId);
        $verified = (int) (clone $payments)->where('status', 'VERIFIED')->sum('amount_cents');
        $reversed = (int) (clone $payments)->where('status', 'REVERSED')->sum('amount_cents');
        $refunds = Schema::hasTable('refund_requests') ? (int) DB::table('refund_requests')->join('payment_transactions', 'payment_transactions.id', '=', 'refund_requests.payment_transaction_id')->where('payment_transactions.booking_id', $bookingId)->where('refund_requests.status', 'REFUNDED')->sum('refund_requests.amount_cents') : 0;

        return max(0, $verified - $reversed - $refunds);
    }

    public function reconcileEarn(int $bookingId, string $eventKey, ?int $actorId = null): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($bookingId, $eventKey, $actorId) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $earn = LoyaltyTransaction::where('booking_id', $bookingId)->where('type', 'EARN')->first();
            if (! $earn) {
                return null;
            }
            $account = $this->account($earn->business_id, $earn->customer_profile_id);
            $key = 'LOYALTY:REFUND:'.$eventKey;
            if ($existing = LoyaltyTransaction::where('idempotency_key', $key)->first()) {
                return $existing;
            }
            $snapshot = $earn->rule_snapshot;
            $valid = min(Money::cents((string) $booking->total_amount), $this->netPaidCents($bookingId));
            $earnedNow = intdiv($valid, Money::cents((string) $snapshot['earn_amount_unit'])) * $snapshot['earn_points_per_amount'];
            $alreadyDeducted = abs((int) LoyaltyTransaction::where('reversal_of_id', $earn->id)->where('type', 'REFUND_ADJUSTMENT')->sum('points'));
            $deduct = min($account->balance, max(0, $earn->points - $earnedNow - $alreadyDeducted));
            if ($deduct <= 0) {
                return null;
            }
            $account->balance -= $deduct;
            $account->version++;
            $account->save();

            return $this->ledger($account, $bookingId, 'REFUND_ADJUSTMENT', -$deduct, $key, $snapshot, $actorId, ['reversal_of_id' => $earn->id, 'reason' => 'Điều chỉnh điểm theo thanh toán thực tế sau hoàn/đảo giao dịch']);
        }, 3);
    }

    private function ledger(LoyaltyAccount $account, ?int $bookingId, string $type, int $points, string $key, ?array $snapshot, ?int $actorId, array $extra = []): LoyaltyTransaction
    {
        return LoyaltyTransaction::create(array_merge(['loyalty_account_id' => $account->id, 'business_id' => $account->business_id, 'customer_profile_id' => $account->customer_profile_id, 'booking_id' => $bookingId, 'type' => $type, 'points' => $points, 'balance_after' => $account->balance, 'idempotency_key' => $key, 'rule_snapshot' => $snapshot, 'created_by_user_id' => $actorId], $extra));
    }

    private function expireAccount(LoyaltyAccount $account): void
    {
        $consumed = abs((int) $account->transactions()->where('points', '<', 0)->sum('points'));
        foreach ($account->transactions()->where('type', 'EARN')->orderBy('created_at')->orderBy('id')->get() as $earn) {
            $used = min($consumed, $earn->points);
            $consumed -= $used;
            $remaining = $earn->points - $used;
            $key = 'LOYALTY:EXPIRE:'.$earn->id;
            if ($remaining <= 0 || ! $earn->expires_at || $earn->expires_at > now() || LoyaltyTransaction::where('idempotency_key', $key)->exists()) {
                continue;
            }
            $points = min($account->balance, $remaining);
            if ($points <= 0) {
                continue;
            }
            $account->balance -= $points;
            $account->version++;
            $account->save();
            $this->ledger($account, null, 'EXPIRE', -$points, $key, null, null, ['reversal_of_id' => $earn->id, 'reason' => 'Điểm hết hạn']);
        }
    }

    public function listCustomer(int $customerId)
    {
        foreach (LoyaltyAccount::where('customer_profile_id', $customerId)->get() as $account) {
            DB::transaction(fn () => $this->expireAccount(LoyaltyAccount::whereKey($account->id)->lockForUpdate()->firstOrFail()), 3);
        }

        return LoyaltyAccount::where('customer_profile_id', $customerId)->with(['business', 'transactions' => fn ($q) => $q->latest()->limit(100)])->get();
    }
}
