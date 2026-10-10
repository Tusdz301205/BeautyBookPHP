<?php

use App\Http\Controllers\PlatformController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account:OPERATIONAL'])->group(function () {
    Route::get('/admin/settings', [PlatformController::class, 'settings'])->name('platform.settings');
    Route::put('/admin/settings', [PlatformController::class, 'updateSettings'])->name('platform.settings.update');
    Route::get('/admin/business-directory', [PlatformController::class, 'directory'])->name('platform.directory');
    Route::get('/reports/bookings', [PlatformController::class, 'report'])->name('platform.report');
    Route::get('/audit-logs', [PlatformController::class, 'audit'])->name('platform.audit');
});
