<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OwnerBranchController;
use App\Http\Controllers\OwnerBusinessController;
use App\Http\Controllers\OwnerCatalogController;
use App\Http\Controllers\OwnerMediaController;
use App\Http\Controllers\OwnerStaffController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\WorkspaceController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require __DIR__.'/beautybook.php';

Route::get('/', \App\Http\Controllers\HomeController::class)->name('home');

Route::get('/explore', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/explore/{businessSlug}/{branchSlug}', [MarketplaceController::class, 'show'])->name('marketplace.show');
Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::middleware('verified')->group(function () {
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('/booking/{branch}', [BookingController::class, 'create'])->name('booking.create');
        Route::get('/booking/{branch}/slots', [BookingController::class, 'slots'])->name('booking.slots');
        Route::post('/booking/{branch}/quote', [BookingController::class, 'quote'])->name('booking.quote');
        Route::post('/booking/{branch}/availability', [BookingController::class, 'availability'])->name('booking.availability');
        Route::post('/booking/{branch}', [BookingController::class, 'store'])->name('booking.store');
        Route::post('/bookings/{booking}/transition', [BookingController::class, 'transition'])->name('bookings.transition');
        Route::post('/bookings/{booking}/items/{item}/transition', [BookingController::class, 'transitionItem'])->name('bookings.items.transition');
        Route::post('/bookings/{booking}/change-requests', [BookingController::class, 'createChangeRequest'])->name('bookings.change-requests.store');
        Route::post('/bookings/{booking}/change-requests/{changeRequest}/approve', [BookingController::class, 'approveChangeRequest'])->name('bookings.change-requests.approve');
        Route::post('/bookings/{booking}/change-requests/{changeRequest}/reject', [BookingController::class, 'rejectChangeRequest'])->name('bookings.change-requests.reject');

        Route::get('/app', \App\Http\Controllers\WorkspaceHomeController::class)->middleware('workspace')->name('app');

        Route::middleware('account:OPERATIONAL')->group(function () {
            Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
            Route::post('/workspace', [WorkspaceController::class, 'store'])->name('workspace.store');

            Route::prefix('owner')->name('owner.')->group(function () {
                Route::get('/businesses', [OwnerBusinessController::class, 'index'])->name('businesses.index');
                Route::get('/businesses/create', [OwnerBusinessController::class, 'create'])->name('businesses.create');
                Route::post('/businesses', [OwnerBusinessController::class, 'store'])->name('businesses.store');
                Route::get('/businesses/{business}/edit', [OwnerBusinessController::class, 'edit'])->name('businesses.edit');
                Route::put('/businesses/{business}', [OwnerBusinessController::class, 'update'])->name('businesses.update');
                Route::post('/businesses/{business}/submit', [OwnerBusinessController::class, 'submit'])->name('businesses.submit');
                Route::get('/businesses/{business}/branches/create', [OwnerBranchController::class, 'create'])->name('branches.create');
                Route::post('/businesses/{business}/branches', [OwnerBranchController::class, 'store'])->name('branches.store');
                Route::get('/businesses/{business}/branches/{branch}/edit', [OwnerBranchController::class, 'edit'])->name('branches.edit');
                Route::put('/businesses/{business}/branches/{branch}', [OwnerBranchController::class, 'update'])->name('branches.update');
                Route::put('/businesses/{business}/branches/{branch}/hours', [OwnerBranchController::class, 'updateHours'])->name('branches.hours');
                Route::post('/businesses/{business}/branches/{branch}/submit', [OwnerBranchController::class, 'submit'])->name('branches.submit');
                Route::post('/businesses/{business}/branches/{branch}/publish', [OwnerBranchController::class, 'publish'])->name('branches.publish');
                Route::post('/businesses/{business}/branches/{branch}/operational', [OwnerBranchController::class, 'operational'])->name('branches.operational');
                Route::get('/businesses/{business}/catalog', [OwnerCatalogController::class, 'index'])->name('catalog.index');
                Route::post('/businesses/{business}/catalog', [OwnerCatalogController::class, 'store'])->name('catalog.store');
                Route::put('/businesses/{business}/catalog/{service}', [OwnerCatalogController::class, 'update'])->name('catalog.update');
                Route::delete('/businesses/{business}/catalog/{service}', [OwnerCatalogController::class, 'archive'])->name('catalog.archive');
                Route::put('/businesses/{business}/offerings/{offering}', [OwnerCatalogController::class, 'updateOffering'])->name('offerings.update');
                Route::post('/businesses/{business}/offerings/{offering}/variants', [OwnerCatalogController::class, 'storeVariant'])->name('variants.store');
                Route::delete('/businesses/{business}/offerings/{offering}/variants/{variant}', [OwnerCatalogController::class, 'archiveVariant'])->name('variants.archive');
                Route::get('/businesses/{business}/staff', [OwnerStaffController::class, 'index'])->name('staff.index');
                Route::post('/businesses/{business}/staff', [OwnerStaffController::class, 'store'])->name('staff.store');
                Route::put('/businesses/{business}/staff/{staff}', [OwnerStaffController::class, 'update'])->name('staff.update');
                Route::put('/businesses/{business}/staff/{staff}/capabilities', [OwnerStaffController::class, 'capabilities'])->name('staff.capabilities');
                Route::delete('/businesses/{business}/staff/{staff}', [OwnerStaffController::class, 'archive'])->name('staff.archive');
                Route::post('/businesses/{business}/staff-invitations', [OwnerStaffController::class, 'invite'])->name('staff.invite');
                Route::post('/businesses/{business}/media', [OwnerMediaController::class, 'store'])->name('media.store');
            });

            Route::prefix('admin/reviews')->name('admin.reviews.')->group(function () {
                Route::get('/', [AdminReviewController::class, 'index'])->name('index');
                Route::get('/businesses/{business}', [AdminReviewController::class, 'business'])->name('business');
                Route::post('/businesses/{business}', [AdminReviewController::class, 'reviewBusiness'])->name('business.review');
                Route::get('/branches/{branch}', [AdminReviewController::class, 'branch'])->name('branch');
                Route::post('/branches/{branch}', [AdminReviewController::class, 'reviewBranch'])->name('branch.review');
            });
        });

        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('/security/sessions/revoke', [SecurityController::class, 'revokeSessions'])
            ->name('security.sessions.revoke');
    });
});

Route::get('/ready', function () {
    try {
        $database = DB::selectOne('select 1 as connected');
        $seeded = User::where('email', 'foundation@beautybook.local')->exists();

        return response()->json([
            'status' => $database?->connected === 1 && $seeded ? 'ready' : 'not_ready',
            'database' => $database?->connected === 1,
            'seeded' => $seeded,
        ], $database?->connected === 1 && $seeded ? 200 : 503);
    } catch (Throwable) {
        return response()->json([
            'status' => 'not_ready',
            'database' => false,
            'seeded' => false,
        ], 503);
    }
});

require __DIR__.'/engagement.php';
require __DIR__.'/promotions.php';
require __DIR__.'/platform.php';
require __DIR__.'/privacy.php';
require __DIR__.'/finance.php';
require __DIR__.'/ownership.php';
require __DIR__.'/operations.php';
require __DIR__.'/scheduling.php';
require __DIR__.'/impact.php';
require __DIR__.'/governance.php';
require __DIR__.'/profile.php';
require __DIR__.'/booking-cart.php';
Route::get('/bookings/calendar', \App\Http\Controllers\BookingCalendarController::class)
    ->middleware(['auth', 'verified'])->name('bookings.calendar');
