<?php

use App\Http\Controllers\CartBookingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/booking/{branch}/cart/quote', [CartBookingController::class, 'quote'])->name('booking.cart.quote');
    Route::get('/booking/{branch}/cart/slots', [CartBookingController::class, 'slots'])->name('booking.cart.slots');
    Route::post('/booking/{branch}/cart', [CartBookingController::class, 'store'])->name('booking.cart.store');
});
