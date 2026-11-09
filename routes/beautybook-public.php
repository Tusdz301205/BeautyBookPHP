<?php

use App\Http\Controllers\Beautybook\PublicController;
use App\Http\Controllers\Beautybook\StaffCalendarController;
use Illuminate\Support\Facades\Route;

Route::get('branches', [PublicController::class, 'branches']);
Route::get('branches/locations/districts', [PublicController::class, 'districts']);
Route::get('branches/{id}', [PublicController::class, 'branch'])->whereNumber('id');
Route::get('services/categories', [PublicController::class, 'categories']);
Route::get('services/search', [PublicController::class, 'services']);
Route::get('services', [PublicController::class, 'services']);
Route::get('services/{id}/variants', [PublicController::class, 'variants'])->whereNumber('id');
Route::get('services/{id}', [PublicController::class, 'service'])->whereNumber('id');
Route::get('staff/public', [PublicController::class, 'staff']);
Route::get('staff/public/{id}', [PublicController::class, 'staffDetail']);
Route::get('staff/{id}/working-hours', [StaffCalendarController::class, 'publicHours'])->whereNumber('id');
Route::get('staff/{id}/time-off', [StaffCalendarController::class, 'publicTimeOff'])->whereNumber('id');
Route::get('reviews/business/{id}', [PublicController::class, 'businessReviews']);
Route::get('reviews/public/staff/{id}', [PublicController::class, 'staffReviews']);
Route::get('reviews/service/{id}', [PublicController::class, 'serviceReviews']);
