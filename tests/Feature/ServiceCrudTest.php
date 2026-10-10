<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\CanonicalService;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ServiceCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_catalog_ui_creates_updates_and_archives_service_without_losing_history(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $category = ServiceCategory::where('slug', 'hair')->firstOrFail();
        $canonical = CanonicalService::where('code', 'HAIR_CUT')->firstOrFail();
        $branches = Branch::where('business_id', $business->id)->whereIn('slug', ['tan-tao', 'binh-tri-dong'])->get();

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.catalog.store', $business), [
                'service_category_id' => $category->id,
                'canonical_service_id' => $canonical->id,
                'name' => 'Uốn tạo kiểu',
                'slug' => 'uon-tao-kieu',
                'description' => 'Dịch vụ tạo từ màn hình owner.',
                'keywords' => ['uốn tóc'],
                'base_price' => 450000,
                'base_duration_minutes' => 120,
                'branch_ids' => $branches->pluck('id')->all(),
            ])
            ->assertRedirect();

        $service = BusinessService::where('business_id', $business->id)->where('slug', 'uon-tao-kieu')->firstOrFail();
        $this->assertSame(2, $service->offerings()->count());
        $this->assertSame($canonical->id, $service->canonical_service_id);

        $offering = $service->offerings()->where('branch_id', $branches->first()->id)->firstOrFail();
        $this->actingAs($owner)
            ->putWithCsrf(route('owner.offerings.update', [$business, $offering]), [
                'price' => 490000,
                'duration_minutes' => 135,
                'bookable' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branch_service_offerings', [
            'id' => $offering->id,
            'price' => '490000.00',
            'duration_minutes' => 135,
            'bookable' => 1,
        ]);

        $this->actingAs($owner)
            ->deleteWithCsrf(route('owner.catalog.archive', [$business, $service]))
            ->assertRedirect();

        $archived = BusinessService::withTrashed()->findOrFail($service->id);
        $this->assertNotNull($archived->deleted_at);
        $this->assertSame('INACTIVE', $archived->status);
        $this->assertSame(2, BranchServiceOffering::withTrashed()->where('business_service_id', $service->id)->whereNotNull('deleted_at')->count());
    }

    public function test_owner_cannot_create_catalog_offering_for_another_business_branch(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $foreignBranch = Branch::whereHas('business', fn ($query) => $query->where('slug', 'glow-studio'))->firstOrFail();
        $category = ServiceCategory::where('slug', 'spa')->firstOrFail();

        $this->actingAs($owner)
            ->from(route('owner.catalog.index', $business))
            ->postWithCsrf(route('owner.catalog.store', $business), [
                'service_category_id' => $category->id,
                'name' => 'Cross tenant service',
                'slug' => 'cross-tenant-service',
                'base_price' => 100000,
                'base_duration_minutes' => 30,
                'branch_ids' => [$foreignBranch->id],
            ])
            ->assertRedirect(route('owner.catalog.index', $business))
            ->assertSessionHasErrors('domain');

        $this->assertDatabaseMissing('business_services', [
            'business_id' => $business->id,
            'slug' => 'cross-tenant-service',
        ]);
    }

    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])->post($uri, ['_token' => $token, ...$data]);
    }

    private function putWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])->put($uri, ['_token' => $token, ...$data]);
    }

    private function deleteWithCsrf(string $uri): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])->delete($uri, ['_token' => $token]);
    }
}
