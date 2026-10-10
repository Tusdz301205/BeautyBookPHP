<?php

use App\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account:CUSTOMER'])->group(function () {
    Route::get('/privacy', [PrivacyController::class, 'index'])->name('privacy.index');
    Route::post('/privacy/data-requests', [PrivacyController::class, 'store'])->name('privacy.requests');
    Route::put('/privacy/preferences', [PrivacyController::class, 'preferences'])->name('privacy.preferences');
    Route::post('/privacy/exports', [PrivacyController::class, 'export'])->middleware('throttle:6,1')->name('privacy.export');
    Route::post('/privacy/exports/{package}/download', [PrivacyController::class, 'download'])->name('privacy.download');
});
