<?php

namespace App\Services\Catalog;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\CanonicalService;
use App\Models\ServiceDependency;
use App\Models\ServicePriceRule;
use App\Models\ServiceVariant;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CatalogManagementService
{
    public function create(Business $business, array $data): BusinessService
    {
        return DB::transaction(function () use ($business, $data) {
            $canonicalId = $data['canonical_service_id'] ?? null;
            $this->assertCanonicalActive($canonicalId);

            $branchIds = collect($data['branch_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            $branches = Branch::query()->where('business_id', $business->id)->whereIn('id', $branchIds)->get();

            if ($branches->count() !== $branchIds->count()) {
                throw new DomainException('Có chi nhánh không thuộc doanh nghiệp hiện tại.');
            }

            if ($branches->isEmpty()) {
                throw new DomainException('Cần chọn ít nhất một chi nhánh cho dịch vụ.');
            }

            $service = BusinessService::query()->create([
                'business_id' => $business->id,
                'service_category_id' => $data['service_category_id'],
                'canonical_service_id' => $canonicalId,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'keywords' => $data['keywords'] ?? null,
                'mapping_status' => $canonicalId ? 'MAPPED' : 'UNMAPPED',
                'base_price' => $data['base_price'],
                'base_duration_minutes' => $data['base_duration_minutes'],
                'status' => 'ACTIVE',
                'is_active' => true,
            ]);

            foreach ($branches as $branch) {
                BranchServiceOffering::query()->create([
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'business_service_id' => $service->id,
                    'price' => $data['base_price'],
                    'duration_minutes' => $data['base_duration_minutes'],
                    'bookable' => true,
                    'is_active' => true,
                ]);
            }

            return $service->load(['canonicalService', 'category', 'offerings.branch']);
        });
    }

    public function update(BusinessService $service, array $data): BusinessService
    {
        return DB::transaction(function () use ($service, $data) {
            $service->refresh();
            $this->assertCanonicalActive($data['canonical_service_id'] ?? null);

            $oldPrice = $service->base_price;
            $oldDuration = $service->base_duration_minutes;
            $newStatus = $data['status'] ?? $service->status;

            $service->fill([
                'service_category_id' => $data['service_category_id'],
                'canonical_service_id' => $data['canonical_service_id'] ?? null,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'keywords' => $data['keywords'] ?? null,
                'mapping_status' => ($data['canonical_service_id'] ?? null) ? 'MAPPED' : 'UNMAPPED',
                'base_price' => $data['base_price'],
                'base_duration_minutes' => $data['base_duration_minutes'],
                'status' => $newStatus,
                'is_active' => $newStatus === 'ACTIVE',
            ])->save();

            $offerings = $service->offerings()->lockForUpdate()->get();

            foreach ($offerings as $offering) {
                $changes = [];

                if ((string) $offering->price === (string) $oldPrice) {
                    $changes['price'] = $data['base_price'];
                }

                if ((int) $offering->duration_minutes === (int) $oldDuration) {
                    $changes['duration_minutes'] = $data['base_duration_minutes'];
                }

                if ($newStatus === 'INACTIVE') {
                    $changes['bookable'] = false;
                    $changes['is_active'] = false;
                }

                if ($changes !== []) {
                    $offering->update($changes);
                }
            }

            return $service->refresh()->load(['canonicalService', 'category', 'offerings.branch']);
        });
    }

    public function updateOffering(BranchServiceOffering $offering, array $data): BranchServiceOffering
    {
        if ($offering->service?->status !== 'ACTIVE' && ($data['bookable'] ?? false)) {
            throw new DomainException('Không thể mở đặt lịch cho dịch vụ đã ngừng hoạt động.');
        }

        $offering->update([
            'price' => $data['price'],
            'duration_minutes' => $data['duration_minutes'],
            'bookable' => (bool) ($data['bookable'] ?? false),
            'is_active' => (bool) ($data['bookable'] ?? false),
        ]);

        return $offering->refresh();
    }

    public function archive(BusinessService $service): void
    {
        DB::transaction(function () use ($service) {
            $service->offerings()->update(['bookable' => false, 'is_active' => false]);
            $service->offerings()->each(fn (BranchServiceOffering $offering) => $offering->delete());
            $service->forceFill(['status' => 'INACTIVE', 'is_active' => false])->save();
            $service->delete();
        });
    }

    public function createVariant(BranchServiceOffering $offering, array $data): ServiceVariant
    {
        $this->assertVariantPricing($data);

        return DB::transaction(function () use ($offering, $data) {
            $variant = $offering->variants()->create(Arr::only($data, [
                'code', 'name', 'description', 'price_type', 'price', 'max_price', 'duration_minutes',
                'max_duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'consultation_required',
                'eligibility_rules', 'status',
            ]));

            foreach ($data['price_rules'] ?? [] as $rule) {
                $this->createPriceRule($offering, $variant, $rule);
            }

            return $variant->load('priceRules');
        });
    }

    public function archiveVariant(ServiceVariant $variant): void
    {
        $variant->forceFill(['status' => 'INACTIVE'])->save();
        $variant->delete();
    }

    public function createPriceRule(BranchServiceOffering $offering, ?ServiceVariant $variant, array $data): ServicePriceRule
    {
        if ($variant && $variant->branch_service_offering_id !== $offering->id) {
            throw new DomainException('Biến thể không thuộc offering hiện tại.');
        }

        if (! empty($data['valid_from']) && ! empty($data['valid_to']) && $data['valid_to'] <= $data['valid_from']) {
            throw new DomainException('Thời gian kết thúc price rule phải sau thời gian bắt đầu.');
        }

        return ServicePriceRule::query()->create([
            'branch_service_offering_id' => $offering->id,
            'service_variant_id' => $variant?->id,
            'name' => $data['name'],
            'priority' => $data['priority'] ?? 100,
            'adjustment_type' => $data['adjustment_type'],
            'adjustment_value' => $data['adjustment_value'],
            'conditions' => $data['conditions'] ?? [],
            'active' => $data['active'] ?? true,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
        ]);
    }

    public function replaceDependencies(BranchServiceOffering $offering, array $dependencies): void
    {
        DB::transaction(function () use ($offering, $dependencies) {
            ServiceDependency::query()->where('branch_service_offering_id', $offering->id)->delete();

            foreach ($dependencies as $dependency) {
                $required = BranchServiceOffering::query()
                    ->where('id', $dependency['required_offering_id'])
                    ->where('business_id', $offering->business_id)
                    ->where('branch_id', $offering->branch_id)
                    ->first();

                if (! $required || $required->id === $offering->id) {
                    throw new DomainException('Dịch vụ phụ thuộc phải là offering khác trong cùng chi nhánh.');
                }

                ServiceDependency::query()->create([
                    'business_id' => $offering->business_id,
                    'branch_id' => $offering->branch_id,
                    'branch_service_offering_id' => $offering->id,
                    'required_offering_id' => $required->id,
                    'dependency_type' => $dependency['dependency_type'],
                ]);
            }
        });
    }

    private function assertCanonicalActive(?int $canonicalId): void
    {
        if ($canonicalId === null) {
            return;
        }

        if (! CanonicalService::query()->whereKey($canonicalId)->where('status', 'ACTIVE')->exists()) {
            throw new DomainException('Canonical service không tồn tại hoặc không còn active.');
        }
    }

    private function assertVariantPricing(array $data): void
    {
        $type = $data['price_type'];
        $price = isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : null;
        $maxPrice = isset($data['max_price']) && $data['max_price'] !== '' ? (float) $data['max_price'] : null;

        if ($type === 'QUOTE' && ($price !== null || $maxPrice !== null)) {
            throw new DomainException('Biến thể báo giá không được lưu giá cố định.');
        }

        if (in_array($type, ['FIXED', 'FROM', 'RANGE'], true) && $price === null) {
            throw new DomainException('Loại giá đã chọn yêu cầu giá bắt đầu.');
        }

        if ($type === 'RANGE' && ($maxPrice === null || $maxPrice < $price)) {
            throw new DomainException('Giá tối đa phải lớn hơn hoặc bằng giá bắt đầu.');
        }

        if ($type !== 'RANGE' && $maxPrice !== null) {
            throw new DomainException('Chỉ loại RANGE mới được có giá tối đa.');
        }

        $duration = $data['duration_minutes'] ?? null;
        $maxDuration = $data['max_duration_minutes'] ?? null;

        if ($maxDuration !== null && ($duration === null || (int) $maxDuration < (int) $duration)) {
            throw new DomainException('Thời lượng tối đa phải lớn hơn hoặc bằng thời lượng bắt đầu.');
        }
    }
}
