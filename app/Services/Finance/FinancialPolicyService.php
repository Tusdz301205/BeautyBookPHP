<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialPolicyService
{
    public function __construct(private FinanceScope $scope) {}

    public function create(Request $request, int $businessId, array $data): int
    {
        if (! empty($data['branch_id'])) abort_unless(Branch::whereKey($data['branch_id'])->where('business_id', $businessId)->exists(), 422);
        if (! empty($data['business_service_id'])) abort_unless(BusinessService::whereKey($data['business_service_id'])->where('business_id', $businessId)->exists(), 422);
        $value = Money::cents($data['deposit_value']);
        abort_if($data['deposit_type'] === 'PERCENTAGE' && $value > 10000, 422);
        return DB::transaction(function () use ($request, $businessId, $data, $value) {
            Business::whereKey($businessId)->lockForUpdate()->firstOrFail();
            $this->scope->business($request,$businessId,['BUSINESS_OWNER']);
            $query = DB::table('payment_policies')->where('business_id', $businessId)->where('name', trim($data['name']))->where('branch_id', $data['branch_id'] ?? null)->where('business_service_id', $data['business_service_id'] ?? null);
            $latest = $query->orderByDesc('version')->first();
            if ($latest && $latest->status === 'ACTIVE') DB::table('payment_policies')->where('id', $latest->id)->update(['status' => 'ARCHIVED', 'effective_to' => \Carbon\Carbon::parse($data['effective_from'])->subSecond(), 'updated_at' => now()]);
            return DB::table('payment_policies')->insertGetId([
                'business_id' => $businessId, 'branch_id' => $data['branch_id'] ?? null, 'business_service_id' => $data['business_service_id'] ?? null,
                'name' => trim($data['name']), 'version' => ($latest?->version ?? 0) + 1, 'status' => 'ACTIVE',
                'deposit_type' => $data['deposit_type'], 'deposit_value_cents' => $value, 'allow_split_payment' => $data['allow_split_payment'],
                'allow_installments' => $data['allow_installments'], 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function effective(int $businessId, int $branchId, array $serviceIds = [], $at = null): ?object
    {
        $at ??= now();
        return DB::table('payment_policies')->where('business_id', $businessId)->where('status', 'ACTIVE')->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where(fn ($q) => $q->whereIn('business_service_id', $serviceIds)->orWhere(fn ($b) => $b->whereNull('business_service_id')->where('branch_id', $branchId))->orWhere(fn ($b) => $b->whereNull('business_service_id')->whereNull('branch_id')))
            ->orderByRaw('CASE WHEN business_service_id IS NOT NULL THEN 3 WHEN branch_id IS NOT NULL THEN 2 ELSE 1 END DESC')->orderByDesc('version')->orderByDesc('effective_from')->first();
    }

    public function capturePolicy(Booking $booking): object
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('payment_policy_snapshots')->where('booking_id', $booking->id)->first();
            if ($existing) return $existing;
            $policy = $this->effective($booking->business_id, $booking->branch_id, $booking->services()->pluck('business_service_id')->all(), $booking->created_at);
            $total = Money::cents($booking->total_amount);
            $type = $policy?->deposit_type ?? 'NONE'; $value = (int) ($policy?->deposit_value_cents ?? 0);
            $required = match ($type) { 'NONE' => 0, 'FULL_PREPAYMENT' => $total, 'FIXED' => min($total, $value), default => min($total, Money::multiplyDivideRound($total,$value,10000)) };
            $snapshot = ['payment_policy_id' => $policy?->id, 'version' => $policy?->version, 'deposit_type' => $type, 'deposit_value_cents' => $value,
                'allow_split_payment' => (bool) ($policy?->allow_split_payment ?? true), 'allow_installments' => (bool) ($policy?->allow_installments ?? false)];
            DB::table('payment_policy_snapshots')->insert(['booking_id' => $booking->id, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'required_amount_cents' => $required, 'created_at' => now(), 'updated_at' => now()]);
            return DB::table('payment_policy_snapshots')->where('booking_id', $booking->id)->first();
        }, 3);
    }
}
