<?php

use App\Http\Controllers\Customer;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\Owner;
use App\Http\Controllers\PublicBranchController;
use App\Http\Controllers\PublicServiceController;
use App\Http\Controllers\SalonController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (Guest) Routes
|--------------------------------------------------------------------------
*/
Route::get('/', [ExploreController::class, 'index'])->name('home');
Route::get('/search/ajax', [ExploreController::class, 'search'])->name('search.ajax');
Route::get('/salons/{slug}', [SalonController::class, 'show'])->name('salons.show');
Route::get('/branches/{branch}', [PublicBranchController::class, 'show'])->name('branches.show');
Route::get('/services/{service}', [PublicServiceController::class, 'show'])->name('services.show');

/*
|--------------------------------------------------------------------------
| Generic Authenticated Redirector
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', function () {
    if (Auth::user()?->isBusinessOwner()) {
        return redirect()->route('owner.dashboard');
    }
    return redirect()->route('customer.dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [\App\Http\Controllers\ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Customer Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'customer'])->prefix('customer')->name('customer.')->group(function () {
    Route::get('/dashboard', [Customer\DashboardController::class, 'index'])->name('dashboard');

    // Bookings
    Route::get('/bookings', [Customer\BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/create', [Customer\BookingController::class, 'create'])->name('bookings.create');
    Route::post('/bookings', [Customer\BookingController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/{booking}', [Customer\BookingController::class, 'show'])->name('bookings.show');
    Route::patch('/bookings/{booking}/cancel', [Customer\BookingController::class, 'cancel'])->name('bookings.cancel');

    // Reviews
    Route::get('/reviews', [Customer\ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/bookings/{booking}/review', [Customer\ReviewController::class, 'create'])->name('reviews.create');
    Route::post('/reviews', [Customer\ReviewController::class, 'store'])->name('reviews.store');

    // Favorites
    Route::get('/favorites', [Customer\FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{service}/toggle', [Customer\FavoriteController::class, 'toggle'])->name('favorites.toggle');
    Route::delete('/favorites/{service}', [Customer\FavoriteController::class, 'destroy'])->name('favorites.destroy');

    // Profile
    Route::get('/profile', [Customer\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [Customer\ProfileController::class, 'update'])->name('profile.update');
});

/*
|--------------------------------------------------------------------------
| Business Owner Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'owner'])->prefix('owner')->name('owner.')->group(function () {
    Route::get('/dashboard', [Owner\DashboardController::class, 'index'])->name('dashboard');

    // Business info
    Route::get('/business', [Owner\BusinessController::class, 'edit'])->name('business.edit');
    Route::put('/business', [Owner\BusinessController::class, 'update'])->name('business.update');

    // Branches CRUD
    Route::resource('branches', Owner\BranchController::class);

    // Categories CRUD
    Route::resource('categories', Owner\ServiceCategoryController::class)->except(['create', 'show', 'edit']);

    // Services CRUD
    Route::resource('services', Owner\ServiceController::class);

    // Staff CRUD
    Route::resource('staff', Owner\StaffController::class);
    Route::patch('/staff/{staff}/toggle-status', [Owner\StaffController::class, 'toggleStatus'])->name('staff.toggle-status');

    // Bookings management
    Route::get('/bookings', [Owner\BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [Owner\BookingController::class, 'show'])->name('bookings.show');
    Route::patch('/bookings/{booking}/status', [Owner\BookingController::class, 'updateStatus'])->name('bookings.status');

    // Reviews management & reply
    Route::get('/reviews', [Owner\ReviewController::class, 'index'])->name('reviews.index');
    Route::post('/reviews/{review}/reply', [Owner\ReviewController::class, 'reply'])->name('reviews.reply');
});

require __DIR__.'/auth.php';
