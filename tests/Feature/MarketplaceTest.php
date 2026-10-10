<?php

namespace Tests\Feature;

use App\Queries\MarketplaceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_guest_search_returns_only_public_ready_branches_with_pagination(): void
    {
        $page = app(MarketplaceQuery::class)->search([]);

        $this->assertSame(8, $page->total());
        $this->assertSame(6, $page->perPage());

        $this->get(route('marketplace.index'))
            ->assertOk()
            ->assertSee('BeautyBook Tân Tạo')
            ->assertDontSee('BeautyBook Coming Soon')
            ->assertDontSee('Glow Suspended');
    }

    public function test_guest_filters_marketplace_by_location_category_and_price(): void
    {
        $this->get(route('marketplace.index', ['location' => 'Bình Tân']))
            ->assertOk()
            ->assertSee('BeautyBook Tân Tạo')
            ->assertSee('BeautyBook Bình Trị Đông')
            ->assertDontSee('Glow Võ Văn Tần');

        $this->get(route('marketplace.index', ['category' => 'spa', 'max_price' => 400000]))
            ->assertOk()
            ->assertSee('BeautyBook Tân Tạo')
            ->assertDontSee('Glow Võ Văn Tần');

        $this->get(route('marketplace.index', ['min_price' => 900000]))
            ->assertOk()
            ->assertSee('Không có chi nhánh phù hợp');
    }

    public function test_public_detail_hides_draft_and_suspended_branches(): void
    {
        $this->get('/explore/beautybook-demo/tan-tao')
            ->assertOk()
            ->assertSee('BeautyBook Tân Tạo');

        $this->get('/explore/beautybook-demo/coming-soon')->assertNotFound();
        $this->get('/explore/glow-studio/suspended-demo')->assertNotFound();
    }
}
