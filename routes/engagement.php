<?php

use App\Http\Controllers\EngagementController;
use Illuminate\Support\Facades\Route;

Route::get('/businesses/{business}/reviews', [EngagementController::class, 'publicReviews'])->name('engagement.public');
Route::get('/staff/{staff}/reviews', [EngagementController::class, 'staffSummary'])->name('engagement.staff');
Route::get('/services/{service}/reviews', [EngagementController::class, 'serviceSummary'])->name('engagement.service');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/reviews', [EngagementController::class, 'index'])->name('engagement.reviews');
    Route::post('/reviews/bookings/{booking}', [EngagementController::class, 'store'])->name('engagement.store');
    Route::post('/reviews/{review}/{action}', [EngagementController::class, 'action'])->name('engagement.action');
    Route::get('/favorites', [EngagementController::class, 'favorites'])->name('engagement.favorites');
    Route::post('/favorites/{offering}', [EngagementController::class, 'save'])->name('engagement.save');
    Route::delete('/favorites/{offering}', [EngagementController::class, 'remove'])->name('engagement.remove');
});
