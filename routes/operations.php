<?php

use App\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/waitlist', [OperationsController::class, 'mine'])->name('waitlist.mine');
    Route::post('/waitlist/branches/{branch}', [OperationsController::class, 'join'])->name('waitlist.join');
    Route::post('/waitlist/{entry}/cancel', [OperationsController::class, 'cancel'])->name('waitlist.cancel');
    Route::post('/waitlist/{entry}/accept', [OperationsController::class, 'accept'])->name('waitlist.accept');
    Route::get('/loyalty', [OperationsController::class, 'loyalty'])->name('loyalty.mine');
    Route::middleware('account:OPERATIONAL')->group(function () {
        Route::get('/operations/branches/{branch}/waitlist', [OperationsController::class, 'branch'])->name('operations.waitlist');
        Route::post('/waitlist/{entry}/offer', [OperationsController::class, 'offer'])->name('waitlist.offer');
        Route::get('/owner/businesses/{business}/loyalty', [OperationsController::class, 'loyaltyBusiness'])->name('owner.loyalty.index');
        Route::post('/owner/businesses/{business}/loyalty', [OperationsController::class, 'configureLoyalty'])->name('owner.loyalty.configure');
    });
});
