<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('beautybook:maintain', function (\App\Services\Booking\BookingMaintenanceService $maintenance) {
    $this->info('Expired holds: '.$maintenance->expire());
    $this->info('Delivered inbox records: '.$maintenance->drainOutbox());
    $this->info('Cleaned privacy packages: '.app(\App\Services\Privacy\PrivacyService::class)->cleanup());
    $this->info('Expired waitlist offers: '.app(\App\Services\Operations\WaitlistService::class)->expireOffers());
    $this->info('Executed ownership transfers: '.app(\App\Services\Ownership\OwnershipService::class)->executeDue());
    $this->info('Escalated impact cases: '.app(\App\Services\Operations\ImpactService::class)->escalateOverdue());
})->purpose('Expire booking holds/change requests and complete database inbox outbox delivery');

Schedule::command('beautybook:maintain')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
