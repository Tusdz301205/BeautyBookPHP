<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeautybookPublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_source_public_search_filters_prices_and_paginates_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/services/search?sort=price_asc&limit=2')->assertOk()->assertJsonCount(2, 'data');
        $prices = array_column($response->json('data'), 'price');
        $this->assertLessThanOrEqual($prices[1], $prices[0]);
        $this->assertGreaterThan(2, $response->json('total'));
        $this->getJson('/api/v1/services/search?minPrice=999999999')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/services/search?minRating=6')->assertUnprocessable();
        $this->getJson('/api/v1/services/search?minRating=5')->assertOk();
    }

    public function test_source_public_detail_hides_unpublished_entities_and_returns_bookable_offering_identity(): void
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $this->getJson('/api/v1/branches/'.$branch->id)->assertOk()->assertJsonPath('id', (string) $branch->id);
        $private = Branch::where('slug', 'coming-soon')->firstOrFail();
        $this->getJson('/api/v1/branches/'.$private->id)->assertNotFound();
        $offering = BranchServiceOffering::where('branch_id', $branch->id)->where('bookable', true)->firstOrFail();
        $this->getJson('/api/v1/services/'.$offering->id)->assertOk()->assertJsonPath('branchServiceOfferingId', (string) $offering->id)->assertJsonPath('branchId', (string) $branch->id);
        $offering->update(['bookable' => false]);
        $this->getJson('/api/v1/services/'.$offering->id)->assertNotFound();
    }

    public function test_source_explore_uses_canonical_taxonomy_and_query_location_parameter_names(): void
    {
        $this->getJson('/api/v1/services/categories')->assertOk()->assertJsonStructure([['id', 'code', 'slug', 'name', 'status', '_count']]);
        $this->getJson('/api/v1/services/search?query=THIS_SERVICE_DOES_NOT_EXIST')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/services/search?location=THIS_LOCATION_DOES_NOT_EXIST')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/services/search?canonicalServiceId=999999999')->assertStatus(400);
    }
}
