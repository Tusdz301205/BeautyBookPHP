<?php

use App\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/vouchers', [PromotionController::class, 'mine'])->name('vouchers.mine');
    Route::post('/vouchers/{voucher}/claim', [PromotionController::class, 'claim'])->name('vouchers.claim');
    foreach (['owner/businesses/{business}' => 'owner.', 'admin' => 'admin.'] as $prefix => $name) {
        Route::prefix($prefix)->name($name)->middleware('account:OPERATIONAL')->group(function () {
            Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::put('/promotions/{type}/{id}', [PromotionController::class, 'update'])->name('promotions.update');
            Route::delete('/promotions/{type}/{id}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
        });
    }
});
