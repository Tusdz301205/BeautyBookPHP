<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\Beautybook\SourceDistrictCatalog::class);
        $this->app->scoped(\App\Services\Beautybook\SourceReferenceMedia::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer(['layouts.app','marketplace.show'], function ($view) {
            $view->with('ui', app(\App\Services\Ui\Navigation::class)->forRequest(request()));
        });
    }
}
