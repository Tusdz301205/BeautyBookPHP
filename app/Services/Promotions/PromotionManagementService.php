<?php

namespace App\Services\Promotions;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\CustomerProfile;
use App\Models\Promotion;
use App\Models\Voucher;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PromotionManagementService
{
    public function save(?Business $business, string $type, array $data, ?int $id = null): Promotion|Voucher
    {
        $voucher = $type === 'voucher';
        $class = $voucher ? Voucher::class : Promotion::class;

        return DB::transaction(function () use ($business, $voucher, $class, $data, $id) {
            $rule = $id ? $class::whereKey($id)->lockForUpdate()->firstOrFail() : new $class;
            if ($id && (($business && ((int) $rule->business_id !== (int) $business->id || $rule->created_by_platform)) || (! $business && ($rule->business_id || ! $rule->created_by_platform)))) {
                abort(403);
            }
            foreach (['branch_ids' => Branch::class, 'service_ids' => BranchServiceOffering::class] as $key => $model) {
                $ids = $data[$key] ?? ($rule->$key ?? []);
                if (count(array_unique($ids)) !== count($ids) || (! $business && count($ids)) || ($business && $model::where('business_id', $business->id)->whereIn('id', $ids)->count() !== count($ids))) {
                    throw new DomainException('Phạm vi chứa dữ liệu ngoài doanh nghiệp hoặc bị trùng.');
                }
            }
            if (! empty($data['combo_ids'])) {
                if (! $business || ! Schema::hasTable('combos') || DB::table('combos')->where('business_id', $business->id)->whereIn('id', $data['combo_ids'])->count() !== count($data['combo_ids'])) {
                    throw new DomainException('Combo không thuộc doanh nghiệp hoặc chưa khả dụng.');
                }
            }
            $table = $voucher ? 'voucher_redemptions' : 'promotion_redemptions';
            $column = $voucher ? 'voucher_id' : 'promotion_id';
            $usage = DB::table($table)->where($column, $rule->id)->whereIn('status', ['RESERVED', 'APPLIED']);
            $totalUsed = $rule->exists ? (clone $usage)->count() : 0;
            $highest = $rule->exists ? (clone $usage)->selectRaw('customer_profile_id, COUNT(*) as uses')->groupBy('customer_profile_id')->get()->max('uses') : 0;
            if (isset($data['total_quantity']) && $data['total_quantity'] < max($totalUsed, (int) $rule->used_quantity)) {
                throw new DomainException('Không thể giảm quota dưới số lượt đã reserve/sử dụng.');
            }
            if (isset($data['max_usage_per_customer']) && $data['max_usage_per_customer'] < $highest) {
                throw new DomainException('Giới hạn mỗi khách thấp hơn số lượt đã sử dụng.');
            }
            $merged = array_merge($rule->getAttributes(), $data);
            if (strtotime($merged['end_date']) <= strtotime($merged['start_date']) || PromotionPricingService::cents((string) $merged['discount_value']) <= 0 || ($merged['discount_type'] === 'PERCENTAGE' && PromotionPricingService::cents((string) $merged['discount_value']) > 10000)) {
                throw new DomainException('Thời gian hoặc giá trị giảm không hợp lệ.');
            }
            if ($voucher && isset($data['code'])) {
                $data['code'] = strtoupper(trim($data['code']));
            }
            $rule->fill($data);
            $rule->business_id = $business?->id;
            $rule->created_by_platform = $business === null;
            if ($voucher) {
                $rule->scope = $business ? 'TENANT' : 'PLATFORM';
            }
            $rule->version = $rule->exists ? $rule->version + 1 : 1;
            $rule->save();

            return $rule;
        }, 3);
    }

    public function grant(Voucher $voucher, int $customerId): void
    {
        DB::transaction(function () use ($voucher, $customerId) {
            $voucher = Voucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($voucher->status !== 'ACTIVE' || $voucher->end_date->isPast() || $voucher->used_quantity >= $voucher->total_quantity) {
                throw new DomainException('Voucher không còn khả dụng.');
            }
            DB::table('customer_vouchers')->insertOrIgnore(['voucher_id' => $voucher->id, 'customer_profile_id' => $customerId, 'status' => 'ACTIVE', 'expires_at' => $voucher->end_date, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function issueAutomatic(CustomerProfile $customer): void
    {
        $segments = DB::table('customer_business_segments')->where('customer_profile_id', $customer->id)->get();
        $bookings = Booking::where('customer_profile_id', $customer->id)->get(['business_id', 'status']);
        $related = $bookings->pluck('business_id')->merge($segments->pluck('business_id'))->unique()->all();
        $completed = $bookings->where('status', 'COMPLETED')->pluck('business_id')->all();
        $vouchers = Voucher::where('auto_issue', true)->where('status', 'ACTIVE')->where('start_date', '<=', now())->where('end_date', '>=', now())->whereColumn('used_quantity', '<', 'total_quantity')
            ->where(fn ($q) => $q->whereIn('business_id', $related)->orWhere(fn ($q) => $q->whereNull('business_id')->where('created_by_platform', true)))->get();
        foreach ($vouchers as $voucher) {
            $eligible = match ($voucher->audience) {
                'ALL' => true,
                'NEW_CUSTOMER' => ! $voucher->business_id || ! in_array($voucher->business_id, $completed),
                'RETURNING_CUSTOMER' => $voucher->business_id && in_array($voucher->business_id, $completed),
                'VIP', 'SELECTED' => $voucher->business_id && $segments->where('business_id', $voucher->business_id)->where('segment', $voucher->audience)->isNotEmpty(),
                'BIRTHDAY' => $customer->date_of_birth && substr($customer->date_of_birth, 5) === now('UTC')->format('m-d'),
                default => false,
            };
            if ($eligible) {
                $this->grant($voucher, $customer->id);
            }
        }
    }
}
