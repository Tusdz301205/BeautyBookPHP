<?php

namespace App\Services\Pricing;

use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ServicePriceRules
{
    public function apply(int $offeringId, ?int $variantId, string $base, CarbonImmutable $at, ?int $staffId = null, bool $lock = false): array
    {
        $query = DB::table('service_price_rules')->where('branch_service_offering_id', $offeringId)->where('active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at->utc()))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $at->utc()))->orderBy('priority')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }$amount = Money::cents($base);
        $applied = [];
        foreach ($query->get() as $rule) {
            if ($rule->service_variant_id && (int) $rule->service_variant_id !== $variantId) {
                continue;
            }
            $conditions = json_decode($rule->conditions, true) ?: [];
            $clock = $at->format('H:i');
            if (! empty($conditions['daysOfWeek']) && ! in_array($at->dayOfWeek, array_map('intval', $conditions['daysOfWeek']), true)) {
                continue;
            }
            if (isset($conditions['startTime']) && $clock < $conditions['startTime'] || isset($conditions['endTime']) && $clock >= $conditions['endTime']) {
                continue;
            }
            if (isset($conditions['staffId']) && (string) $conditions['staffId'] !== (string) $staffId) {
                continue;
            }
            $negative = str_starts_with((string) $rule->adjustment_value, '-');
            $value = Money::cents(ltrim((string) $rule->adjustment_value, '-'));
            $delta = $rule->adjustment_type === 'PERCENTAGE' ? Money::multiplyDivideRound($amount, $value, 10000) : $value;
            if ($negative) {
                $delta = -$delta;
            }
            $amount = max(0, $amount + $delta);
            $applied[] = ['id' => (string) $rule->id, 'version' => $rule->version, 'name' => $rule->name, 'adjustmentType' => $rule->adjustment_type, 'adjustmentValue' => $rule->adjustment_value, 'delta' => Money::decimal($delta), 'conditions' => $conditions];
        }

        return ['amount' => Money::decimal($amount), 'applied' => $applied];
    }
}
