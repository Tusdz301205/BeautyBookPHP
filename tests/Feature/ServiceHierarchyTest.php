<?php

namespace Tests\Feature;

use App\Models\BranchServiceOffering;
use App\Models\BusinessService;
use App\Models\CanonicalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_seed_preserves_canonical_business_service_and_branch_offering_hierarchy(): void
    {
        $canonical = CanonicalService::where('code', 'HAIR_CUT')->with('parent')->firstOrFail();
        $service = BusinessService::where('slug', 'cat-tao-kieu')->with('canonicalService')->firstOrFail();
        $offering = BranchServiceOffering::where('business_service_id', $service->id)->with(['service', 'branch'])->firstOrFail();

        $this->assertSame('BEAUTY', $canonical->parent?->code);
        $this->assertSame($canonical->id, $service->canonicalService?->id);
        $this->assertSame($service->id, $offering->service?->id);
        $this->assertSame($service->business_id, $offering->business_id);
        $this->assertSame($service->business_id, $offering->branch?->business_id);
        $this->assertGreaterThan(0, (float) $service->base_price);
        $this->assertGreaterThan(0, $service->base_duration_minutes);
    }
}
