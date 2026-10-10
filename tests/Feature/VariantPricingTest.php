<?php

namespace Tests\Feature;

use App\Models\BranchServiceOffering;
use App\Services\Catalog\CatalogManagementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_range_and_quote_variants_preserve_their_pricing_contract(): void
    {
        $offering = BranchServiceOffering::whereHas('service', fn ($query) => $query->where('slug', 'cat-tao-kieu'))->firstOrFail();
        $catalog = app(CatalogManagementService::class);

        $range = $catalog->createVariant($offering, [
            'code' => 'LONG-HAIR',
            'name' => 'Tóc dài',
            'price_type' => 'RANGE',
            'price' => '220000.00',
            'max_price' => '320000.00',
            'duration_minutes' => 75,
            'max_duration_minutes' => 120,
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 15,
            'consultation_required' => false,
            'status' => 'ACTIVE',
        ]);
        $quote = $catalog->createVariant($offering, [
            'code' => 'CUSTOM',
            'name' => 'Thiết kế theo yêu cầu',
            'price_type' => 'QUOTE',
            'price' => null,
            'max_price' => null,
            'duration_minutes' => null,
            'max_duration_minutes' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'consultation_required' => true,
            'status' => 'ACTIVE',
        ]);

        $this->assertSame('220000.00', $range->price);
        $this->assertSame('320000.00', $range->max_price);
        $this->assertNull($quote->price);
        $this->assertNull($quote->max_price);
        $this->assertTrue($quote->consultation_required);
    }

    public function test_invalid_range_is_rejected(): void
    {
        $offering = BranchServiceOffering::firstOrFail();

        $this->expectException(DomainException::class);

        app(CatalogManagementService::class)->createVariant($offering, [
            'code' => 'INVALID-RANGE',
            'name' => 'Invalid range',
            'price_type' => 'RANGE',
            'price' => '300000.00',
            'max_price' => '200000.00',
            'duration_minutes' => 60,
            'max_duration_minutes' => 90,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'consultation_required' => false,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_price_rule_cannot_reference_variant_from_another_offering(): void
    {
        $offerings = BranchServiceOffering::orderBy('id')->take(2)->get();
        $catalog = app(CatalogManagementService::class);
        $variant = $catalog->createVariant($offerings[0], [
            'code' => 'FIXED-ONE',
            'name' => 'Fixed one',
            'price_type' => 'FIXED',
            'price' => '100000.00',
            'max_price' => null,
            'duration_minutes' => 60,
            'max_duration_minutes' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'consultation_required' => false,
            'status' => 'ACTIVE',
        ]);

        $this->expectException(DomainException::class);

        $catalog->createPriceRule($offerings[1], $variant, [
            'name' => 'Sai offering',
            'adjustment_type' => 'FIXED_AMOUNT',
            'adjustment_value' => '10000.00',
        ]);
    }
}
