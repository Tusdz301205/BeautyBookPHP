<?php

namespace App\Services\Pricing;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\BusinessService;
use App\Models\ServiceVariant;
use App\Services\Operations\LoyaltyService;
use App\Services\Promotions\PromotionPricingService;
use DomainException;

class QuoteService
{
    public function quote(Branch $branch, int $offeringId, ?int $variantId = null, bool $lock = false, ?int $customerId = null, ?string $voucherCode = null, int $loyaltyPoints = 0, ?\Carbon\CarbonImmutable $at = null, ?int $staffId = null): array
    {
        $offeringQuery = BranchServiceOffering::query()
            ->whereKey($offeringId)
            ->where('business_id', $branch->business_id)
            ->where('branch_id', $branch->id)
            ->where('bookable', true)
            ->where('is_active', true);

        if ($lock) {
            $offeringQuery->lockForUpdate();
        }

        $offering = $offeringQuery->first();

        if (! $offering) {
            throw new DomainException('Dịch vụ không còn khả dụng để đặt lịch.');
        }

        $serviceQuery = BusinessService::query()
            ->whereKey($offering->business_service_id)
            ->where('business_id', $branch->business_id)
            ->where('status', 'ACTIVE')
            ->where('is_active', true);

        if ($lock) {
            $serviceQuery->lockForUpdate();
        }

        $service = $serviceQuery->first();

        if (! $service) {
            throw new DomainException('Dịch vụ không còn khả dụng để đặt lịch.');
        }

        $offering->setRelation('service', $service);

        $variant = null;
        $price = $this->normalizeMoney((string) $offering->price);
        $duration = (int) $offering->duration_minutes;
        $bufferBefore = 0;
        $bufferAfter = 0;

        if ($variantId !== null) {
            $variantQuery = ServiceVariant::query()
                ->whereKey($variantId)
                ->where('branch_service_offering_id', $offering->id)
                ->where('status', 'ACTIVE');

            if ($lock) {
                $variantQuery->lockForUpdate();
            }

            $variant = $variantQuery->first();

            if (! $variant) {
                throw new DomainException('Biến thể không thuộc dịch vụ hoặc đã ngừng hoạt động.');
            }

            if ($variant->price_type !== 'FIXED' || $variant->price === null) {
                throw new DomainException('Biến thể này cần báo giá riêng nên chưa thể xác nhận đặt lịch.');
            }

            $price = $this->normalizeMoney((string) $variant->price);
            $duration = (int) ($variant->duration_minutes ?: $offering->duration_minutes);
            $bufferBefore = (int) $variant->buffer_before_minutes;
            $bufferAfter = (int) $variant->buffer_after_minutes;
        }

        if ($duration < 1) {
            throw new DomainException('Thời lượng dịch vụ không hợp lệ.');
        }

        $rules = $at ? app(ServicePriceRules::class)->apply($offeringId,$variantId,$price,$at,$staffId,$lock) : ['amount'=>$price,'applied'=>[]];
        $price = $rules['amount'];
        $quote = array_replace([
            'offering' => $offering,
            'variant' => $variant,
            'currency' => 'VND',
            'unit_price' => $price,
            'subtotal_amount' => $price,
            'discount_amount' => '0.00',
            'final_amount' => $price,
            'duration_minutes' => $duration,
            'buffer_before_minutes' => $bufferBefore,
            'buffer_after_minutes' => $bufferAfter,
            'quote_version' => 'booking-v1',
            'service_price_rules' => $rules['applied'],
        ], app(PromotionPricingService::class)->quote($branch, $offeringId, $price, $customerId, $voucherCode, $lock));
        if ($loyaltyPoints > 0) {
            if (! $customerId) {
                throw new DomainException('Đổi điểm yêu cầu hồ sơ khách hàng.');
            }
            $loyalty = app(LoyaltyService::class)->preview($customerId, $branch, $loyaltyPoints, $quote['final_amount'], $lock);
            $quote['loyalty'] = $loyalty;
            $quote['discount_amount'] = PromotionPricingService::money(PromotionPricingService::cents($quote['discount_amount']) + PromotionPricingService::cents($loyalty['discount_amount']));
            $quote['final_amount'] = PromotionPricingService::money(PromotionPricingService::cents($quote['final_amount']) - PromotionPricingService::cents($loyalty['discount_amount']));
        }

        return $quote;
    }

    private function normalizeMoney(string $value): string
    {
        $value = trim($value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new DomainException('Giá dịch vụ không hợp lệ.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
