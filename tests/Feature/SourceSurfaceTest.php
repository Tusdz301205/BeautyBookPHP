<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SourceSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $_ENV['BEAUTYBOOK_LEGACY_UI'] = $_SERVER['BEAUTYBOOK_LEGACY_UI'] = 'false';
        putenv('BEAUTYBOOK_LEGACY_UI=false');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $_ENV['BEAUTYBOOK_LEGACY_UI'] = $_SERVER['BEAUTYBOOK_LEGACY_UI'] = 'true';
            putenv('BEAUTYBOOK_LEGACY_UI=true');
        }
    }

    public function test_default_ui_exposes_source_pages_and_shared_media_without_legacy_workflows(): void
    {
        $this->assertFalse(config('beautybook.legacy_ui'));
        $this->get('/login')->assertRedirect('/beautybook/login');
        $this->get('/explore')->assertRedirect('/beautybook/explore');
        $this->assertTrue(Route::has('media.show'));
        $this->assertTrue(Route::has('verification.verify'));
        foreach (['owner.businesses.index', 'booking.cart.store', 'waitlist.store', 'loyalty.index', 'finance.index'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
        foreach (['/owner/businesses', '/bookings', '/finance', '/treatment-packages', '/privacy'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_default_routes_remove_extra_writes_but_keep_original_read_and_preference_contracts(): void
    {
        $routes = collect(Route::getRoutes());
        foreach (['api/v1/privacy/data-requests', 'api/v1/privacy/exports'] as $uri) {
            $this->assertFalse($routes->contains(fn ($route) => $route->uri() === $uri && in_array('POST', $route->methods(), true)));
        }
        foreach (['api/v1/privacy/data-requests', 'api/v1/privacy/marketing-preferences', 'api/v1/bookings/available-slots', 'api/v1/realtime/session'] as $uri) {
            $this->assertTrue($routes->contains(fn ($route) => $route->uri() === $uri && in_array('GET', $route->methods(), true)), $uri);
        }
    }
}
