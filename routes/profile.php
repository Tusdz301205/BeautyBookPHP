<?php

use App\Http\Controllers\CustomerProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/avatars/{media}', [CustomerProfileController::class, 'publicAvatar'])->name('profile.avatar');
Route::middleware(['auth', 'verified', 'account:CUSTOMER'])->group(function () {
    Route::get('/profile', [CustomerProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [CustomerProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [CustomerProfileController::class, 'uploadAvatar'])->name('profile.avatar.upload');
});
