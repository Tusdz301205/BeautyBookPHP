<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BeautybookPrivacyExportRateLimitTest extends TestCase
{
    public function test_export_creation_route_is_throttled(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/privacy/exports', 'POST'));

        $this->assertContains('throttle:6,1', $route->gatherMiddleware());
    }
}
