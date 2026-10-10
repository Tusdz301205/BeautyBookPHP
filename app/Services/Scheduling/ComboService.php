<?php

namespace App\Services\Scheduling;

use App\Enums\BranchReviewStatus;
use App\Enums\BusinessStatus;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Combo;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComboService
{
    public function save(Branch $branch, array $data, ?Combo $combo = null): Combo
    {
        return DB::transaction(function () use ($branch, $data, $combo) {
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $business = $branch->business()->lockForUpdate()->firstOrFail();
            if (! in_array($business->status, [BusinessStatus::Approved, BusinessStatus::Active], true)) {
                throw new DomainException('Doanh nghiệp cần được duyệt trước khi tạo combo.');
            }
            $status = $data['status'] ?? $combo?->status ?? 'ACTIVE';
            if ($status === 'ACTIVE' && $branch->review_status !== BranchReviewStatus::Approved) {
                throw new DomainException('Combo chỉ được kích hoạt sau khi chi nhánh được duyệt.');
            }
            $ids = array_column($data['items'], 'branch_service_offering_id');
            if (count(array_unique($ids)) !== count($ids) || count($ids) < 2) {
                throw ValidationException::withMessages(['items' => 'Combo cần ít nhất hai dịch vụ khác nhau.']);
            }
            $offerings = BranchServiceOffering::query()->whereIn('id', $ids)
                ->where('branch_id', $branch->id)->where('business_id', $branch->business_id)
                ->where('is_active', true)->where('bookable', true)
                ->whereHas('service', fn ($q) => $q->where('status', 'ACTIVE')->where('is_active', true))
                ->with('service')->lockForUpdate()->get()->keyBy('id');
            if ($offerings->count() !== count($ids)) {
                throw new DomainException('Combo có dịch vụ không hợp lệ hoặc khác chi nhánh.');
            }
            $orders = array_map(fn ($item, $index) => $item['sort_order'] ?? $index, $data['items'], array_keys($data['items']));
            if (count(array_unique($orders)) !== count($orders)) {
                throw new DomainException('Thứ tự dịch vụ không được trùng.');
            }
            $items = [];
            foreach ($data['items'] as $index => $item) {
                $offering = $offerings[$item['branch_service_offering_id']];
                if ($status === 'ACTIVE' && ! $offering->staff()->where('staff_profiles.status', 'ACTIVE')
                    ->where('staff_profiles.business_id', $branch->business_id)->where('staff_profiles.branch_id', $branch->id)
                    ->where('is_bookable', true)->exists()) {
                    throw new DomainException('Dịch vụ '.$offering->service->name.' chưa có nhân viên phù hợp.');
                }
                $items[] = [
                    'branch_service_offering_id' => $offering->id,
                    'quantity' => $item['quantity'],
                    'sort_order' => $item['sort_order'] ?? $index,
                    'transition_minutes' => $item['transition_minutes'] ?? 0,
                    'price_snapshot' => $offering->price,
                    'duration_snapshot' => $offering->duration_minutes,
                ];
            }
            usort($items, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
            foreach ($items as $index => &$item) {
                $item['sort_order'] = $index;
            }
            unset($item);
            if ($combo) {
                $combo = Combo::query()->whereKey($combo->id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            } else {
                $combo = new Combo(['business_id' => $branch->business_id, 'branch_id' => $branch->id]);
            }
            $combo->fill(collect($data)->except('items')->all());
            $combo->name = trim($combo->name);
            $combo->status = $status;
            $combo->version = $combo->exists ? $combo->version + 1 : 1;
            $combo->save();
            $combo->items()->delete();
            $combo->items()->createMany($items);

            return $combo->load('items.offering.service');
        }, 3);
    }

    public function publicQuery(?Branch $branch = null): \Illuminate\Database\Eloquent\Builder
    {
        return Combo::query()->where('status', 'ACTIVE')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->where(fn ($q) => $q->whereNull('max_usage')->orWhereColumn('used_count', '<', 'max_usage'))
            ->whereHas('branch', fn ($q) => $q->publicReady())
            ->has('items', '>=', 2)
            ->whereDoesntHave('items', fn ($q) => $q->whereDoesntHave('offering', fn ($o) => $o
                ->where('is_active', true)->where('bookable', true)
                ->whereColumn('branch_service_offerings.branch_id', 'combos.branch_id')
                ->whereColumn('branch_service_offerings.business_id', 'combos.business_id')
                ->whereHas('service', fn ($s) => $s->where('status', 'ACTIVE')->where('is_active', true))))
            ->with(['branch', 'items.offering.service']);
    }

    public function resolveForBooking(Branch $branch, int $comboId, bool $lock = false): Combo
    {
        $query = $this->publicQuery($branch)->whereKey($comboId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $combo = $query->first();
        if (! $combo) {
            throw new DomainException('Combo không khả dụng, hết hạn hoặc đã hết lượt sử dụng.');
        }

        return $combo;
    }

    public function releaseUsage(\App\Models\Booking $booking): void
    {
        $usage = DB::table('combo_booking_usages')->where('booking_id', $booking->id)->lockForUpdate()->first();
        if (! $usage || $usage->status !== 'RESERVED') {
            return;
        }
        $combo = Combo::withTrashed()->whereKey($usage->combo_id)->lockForUpdate()->firstOrFail();
        if ($combo->used_count > 0) {
            $combo->decrement('used_count');
        }
        DB::table('combo_booking_usages')->where('id', $usage->id)->update(['status' => 'RELEASED', 'updated_at' => now()]);
    }
}
