<?php

namespace App\Services\Promotions;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\CustomerProfile;
use App\Models\Promotion;
use App\Models\Voucher;
use App\Services\Operations\LoyaltyService;
use App\Services\Scheduling\ComboService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PromotionPricingService
{
    public static function cents(string $value): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new DomainException('Giá trị tiền không hợp lệ.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function quoteCombo(Branch $branch, int $comboId, array $allocatedItems, ?int $customerId, ?string $voucherCode = null, bool $lock = false): array
    {
        if (! $allocatedItems) {
            throw new DomainException('Combo không có dịch vụ.');
        }
        $total = array_sum(array_map(fn ($item) => self::cents($item['allocated_amount']), $allocatedItems));

        return $this->quote($branch, $allocatedItems[0]['branch_service_offering_id'], self::money($total), $customerId, $voucherCode, $lock, $comboId, $allocatedItems);
    }

    public function quote(Branch $branch, int $offeringId, string $subtotal, ?int $customerId, ?string $code = null, bool $lock = false, ?int $comboId = null, array $lineItems = []): array
    {
        $base = self::cents($subtotal);
        $serviceIds = $lineItems ? array_values(array_unique(array_column($lineItems, 'branch_service_offering_id'))) : [$offeringId];
        $result = ['discount_amount' => '0.00', 'final_amount' => $subtotal, 'discount_rules' => [], 'explanations' => []];
        if ($customerId === null) {
            if ($code) {
                throw new DomainException('Voucher yêu cầu hồ sơ khách hàng.');
            }

            return $result;
        }
        $customer = CustomerProfile::findOrFail($customerId);
        $code = $code === null ? null : strtoupper(trim($code));
        $manual = null;
        if ($code) {
            $query = Promotion::where('code', $code)
                ->where(fn ($q) => $q->where('business_id', $branch->business_id)->orWhere(fn ($platform) => $platform->whereNull('business_id')->where('created_by_platform', true)))
                ->orderByRaw('business_id IS NULL')->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $manual = $query->first();
            if ($manual && ($manual->status !== 'ACTIVE' || $manual->start_date->isFuture() || ! $manual->end_date->isFuture()
                || ! $this->scopeEligible($manual, $branch, $serviceIds, $comboId)
                || ! $this->audienceEligible($manual->audience, $customer, $branch) || ! $this->quotaEligible($manual, $customerId, false))) {
                // An unusable promotion must not shadow a pre-existing, entitled native voucher.
                $manual = null;
            }
        }
        $eligible = [];
        $promotions = Promotion::where('status', 'ACTIVE')->where(fn ($query) => $query->where('auto_apply', true)->when($manual, fn ($q) => $q->orWhere('id', $manual->id)))
            ->where('start_date', '<=', now())->where('end_date', '>', now())
            ->where(fn ($q) => $q->where('business_id', $branch->business_id)->orWhere(fn ($q) => $q->whereNull('business_id')->where('created_by_platform', true)))
            ->orderBy('id');
        if ($lock) {
            $promotions->lockForUpdate();
        }
        foreach ($promotions->get() as $promotion) {
            if (! $this->scopeEligible($promotion, $branch, $serviceIds, $comboId) || ! $this->audienceEligible($promotion->audience, $customer, $branch)) {
                continue;
            }
            if (! $this->quotaEligible($promotion, $customerId, false)) {
                continue;
            }
            $eligibleBase = $this->eligibleSubtotal($promotion, $lineItems, $base, $comboId);
            $amount = $this->discount($promotion, $eligibleBase);
            if ($amount > 0) {
                $eligible[] = $this->rule($promotion, $amount, 'PROMOTION', $eligibleBase, $branch, $serviceIds, $comboId, $manual?->id === $promotion->id ? $code : null);
            }
        }
        usort($eligible, fn ($a, $b) => ($b['cents'] <=> $a['cents']) ?: ($a['id'] <=> $b['id']));
        $promotion = $eligible[0] ?? null;
        $voucherRule = null;
        if ($code && ! $manual) {
            $query = Voucher::where('code', strtoupper(trim($code)))->where('status', 'ACTIVE')->where('start_date', '<=', now())->where('end_date', '>=', now());
            if ($lock) {
                $query->lockForUpdate();
            }
            $voucher = $query->first();
            if (! $voucher || ! $this->scopeEligible($voucher, $branch, $serviceIds, $comboId) || ! $this->audienceEligible($voucher->audience, $customer, $branch)) {
                throw new DomainException('Voucher không áp dụng cho khách hàng, chi nhánh hoặc dịch vụ này.');
            }
            $entitlement = DB::table('customer_vouchers')->where('voucher_id', $voucher->id)->where('customer_profile_id', $customerId)->whereIn('status', ['ACTIVE', 'USED'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            if (! $entitlement || ! $this->quotaEligible($voucher, $customerId, true)) {
                throw new DomainException('Bạn chưa sở hữu voucher hoặc đã hết lượt sử dụng.');
            }
            $remaining = $base - (($promotion['stacking_allowed'] ?? false) ? $promotion['cents'] : 0);
            if ($remaining < self::cents((string) $voucher->min_order_value)) {
                throw new DomainException('Đơn hàng chưa đạt giá trị tối thiểu của voucher.');
            }
            $voucherBase = min($remaining, $this->eligibleSubtotal($voucher, $lineItems, $remaining));
            $voucherRule = $this->rule($voucher, $this->discount($voucher, $voucherBase), 'VOUCHER', $voucherBase, $branch, $serviceIds, $comboId);
        }
        if ($promotion && $voucherRule && ! $promotion['stacking_allowed']) {
            if ($voucherRule['cents'] >= $promotion['cents']) {
                $promotion = null;
            } else {
                $voucherRule = null;
            }
            $result['explanations'][] = 'Áp dụng ưu đãi tốt nhất vì khuyến mãi không cho phép cộng dồn.';
        }
        $rules = array_values(array_filter([$promotion, $voucherRule]));
        $discount = min($base, array_sum(array_column($rules, 'cents')));

        return array_replace($result, ['discount_amount' => self::money($discount), 'final_amount' => self::money($base - $discount), 'discount_rules' => $rules, 'promotion_code_valid' => $manual !== null]);
    }

    private function eligibleSubtotal($rule, array $lineItems, int $base, ?int $comboId = null): int
    {
        if ($rule instanceof Promotion && $rule->target_mode === 'ALL') {
            return $base;
        }
        if ($rule instanceof Promotion && $rule->target_mode === 'SELECTED' && $comboId !== null && in_array($comboId, $rule->combo_ids ?? [])) {
            return $base;
        }
        if (! $rule->service_ids || ! $lineItems) {
            return $base;
        }

        return array_sum(array_map(fn ($item) => in_array($item['branch_service_offering_id'], $rule->service_ids) ? self::cents($item['allocated_amount']) : 0, $lineItems));
    }

    private function rule($rule, int $amount, string $type, int $base, Branch $branch, array $serviceIds, ?int $comboId = null, ?string $requestedCode = null): array
    {
        return ['id' => $rule->id, 'type' => $type, 'version' => $rule->version, 'name' => $rule->name, 'cents' => $amount, 'amount' => self::money($amount), 'stacking_allowed' => (bool) $rule->stacking_allowed, 'snapshot' => ['discount_type' => $rule->discount_type, 'discount_value' => $rule->discount_value, 'audience' => $rule->audience, 'eligible_subtotal' => self::money($base), 'branch_id' => $branch->id, 'service_ids' => $serviceIds, 'combo_id' => $comboId, 'min_order_value' => $rule->min_order_value, 'max_discount' => $rule->max_discount, 'code' => $rule->code, 'requested_code' => $requestedCode, 'target_mode' => $rule->target_mode, 'start_date' => $rule->start_date->toISOString(), 'end_date' => $rule->end_date->toISOString()]];
    }

    private function discount($rule, int $base): int
    {
        $amount = $rule->discount_type === 'PERCENTAGE' ? intdiv($base * self::cents((string) $rule->discount_value) + 5000, 10000) : self::cents((string) $rule->discount_value);
        if ($rule->discount_type === 'PERCENTAGE' && $rule->max_discount !== null) {
            $amount = min($amount, self::cents((string) $rule->max_discount));
        }

        return min($base, $amount);
    }

    public function scopeEligible($rule, Branch $branch, array $serviceIds, ?int $comboId = null): bool
    {
        if ($rule instanceof Promotion && in_array($rule->target_mode, ['ALL', 'SELECTED'], true)) {
            $target = $rule->target_mode === 'ALL'
                || count(array_intersect($serviceIds, $rule->service_ids ?? [])) > 0
                || ($comboId !== null && in_array($comboId, $rule->combo_ids ?? []));

            return ($rule->business_id === null ? $rule->created_by_platform : (int) $rule->business_id === (int) $branch->business_id)
                && (! $rule->branch_ids || in_array($branch->id, $rule->branch_ids)) && $target;
        }

        return ($rule->business_id === null ? $rule->created_by_platform : (int) $rule->business_id === (int) $branch->business_id)
            && (! $rule->branch_ids || in_array($branch->id, $rule->branch_ids))
            && (! $rule->service_ids || count(array_intersect($serviceIds, $rule->service_ids)) > 0)
            && (! $rule->combo_ids || ($comboId !== null && in_array($comboId, $rule->combo_ids)));
    }

    public function audienceEligible(string $audience, CustomerProfile $customer, Branch $branch): bool
    {
        $returning = Booking::where('business_id', $branch->business_id)->where('customer_profile_id', $customer->id)->where('status', 'COMPLETED')->exists();

        return match ($audience) {
            'ALL' => true,
            'NEW_CUSTOMER' => ! $returning,
            'RETURNING_CUSTOMER' => $returning,
            'BIRTHDAY' => $customer->date_of_birth && substr($customer->date_of_birth, 5) === now('UTC')->format('m-d'),
            'VIP', 'SELECTED' => DB::table('customer_business_segments')->where('business_id', $branch->business_id)->where('customer_profile_id', $customer->id)->where('segment', $audience)->exists(),
            default => false,
        };
    }

    public function quotaEligible($rule, int $customerId, bool $voucher): bool
    {
        $column = $voucher ? 'voucher_id' : 'promotion_id';
        $usage = DB::table($voucher ? 'voucher_redemptions' : 'promotion_redemptions')->where($column, $rule->id)->whereIn('status', ['RESERVED', 'APPLIED']);

        return ($rule->total_quantity === null || ($voucher ? $rule->used_quantity : (clone $usage)->count()) < $rule->total_quantity)
            && (clone $usage)->where('customer_profile_id', $customerId)->count() < $rule->max_usage_per_customer;
    }

    public function reserve(Booking $booking, array $quote): void
    {
        $branch = Branch::findOrFail($booking->branch_id);
        $customer = $booking->customer_profile_id ? CustomerProfile::findOrFail($booking->customer_profile_id) : null;
        foreach ($quote['discount_rules'] ?? [] as $rule) {
            $voucher = $rule['type'] === 'VOUCHER';
            $model = ($voucher ? Voucher::query() : Promotion::query())->whereKey($rule['id'])->lockForUpdate()->first();
            if (! $customer || ! $model || $model->status !== 'ACTIVE' || $model->version !== $rule['version'] || $model->start_date->isFuture() || ($voucher ? $model->end_date->isPast() : ! $model->end_date->isFuture()) || ! $this->scopeEligible($model, $branch, $rule['snapshot']['service_ids'], $rule['snapshot']['combo_id'] ?? null) || ! $this->audienceEligible($model->audience, $customer, $branch) || ! $this->quotaEligible($model, $booking->customer_profile_id, $voucher)) {
                throw new DomainException('Ưu đãi vừa thay đổi hoặc hết lượt; vui lòng tính giá lại.');
            }
            if (! $voucher && ! $model->auto_apply && (! $model->code || ($rule['snapshot']['requested_code'] ?? null) !== $model->code)) {
                throw new DomainException('Ưu đãi yêu cầu mã hợp lệ; vui lòng tính giá lại.');
            }
            if ($voucher && ! DB::table('customer_vouchers')->where('voucher_id', $model->id)->where('customer_profile_id', $customer->id)->whereIn('status', ['ACTIVE', 'USED'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->lockForUpdate()->first()) {
                throw new DomainException('Voucher của khách đã hết hạn hoặc bị thu hồi.');
            }
            DB::table($voucher ? 'voucher_redemptions' : 'promotion_redemptions')->insert(['booking_id' => $booking->id, $voucher ? 'voucher_id' : 'promotion_id' => $rule['id'], 'branch_id' => $booking->branch_id, 'customer_profile_id' => $booking->customer_profile_id, 'amount' => $rule['amount'], 'rule_snapshot' => json_encode($rule, JSON_THROW_ON_ERROR), 'status' => 'RESERVED', 'created_at' => now(), 'updated_at' => now()]);
            if ($voucher) {
                $model->increment('used_quantity');
            }
        }
    }

    public function transition(Booking $booking, string $status): void
    {
        if (in_array($status, ['CANCELLED', 'EXPIRED', 'REJECTED'], true)) {
            if (Schema::hasTable('combo_booking_usages')) {
                app(ComboService::class)->releaseUsage($booking);
            }
            if (Schema::hasTable('loyalty_transactions')) {
                app(LoyaltyService::class)->reverseRedemption($booking);
            }
        }
        if (! in_array($status, ['CONFIRMED', 'COMPLETED', 'CANCELLED', 'EXPIRED', 'REJECTED'], true)) {
            return;
        }
        foreach (['promotion_redemptions', 'voucher_redemptions'] as $table) {
            $apply = in_array($status, ['CONFIRMED', 'COMPLETED'], true);
            $rows = DB::table($table)->where('booking_id', $booking->id)->whereIn('status', $apply ? ['RESERVED'] : ['RESERVED', 'APPLIED'])->lockForUpdate()->get();
            foreach ($rows as $row) {
                if (! $apply && $table === 'voucher_redemptions') {
                    $voucher = Voucher::withTrashed()->whereKey($row->voucher_id)->lockForUpdate()->firstOrFail();
                    if ($voucher->used_quantity > 0) {
                        $voucher->decrement('used_quantity');
                    }
                }
                DB::table($table)->where('id', $row->id)->update(['status' => $apply ? 'APPLIED' : 'RELEASED', $apply ? 'applied_at' : 'released_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
