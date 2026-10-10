<?php

use App\Http\Controllers\OwnershipController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account:OPERATIONAL'])->group(function () {
    Route::get('/ownership', [OwnershipController::class, 'index'])->name('ownership.index');
    Route::post('/ownership/businesses/{business}', [OwnershipController::class, 'store'])->name('ownership.store');
    Route::post('/ownership/transfers/{transfer}/{action}', [OwnershipController::class, 'action'])->name('ownership.action');
    Route::post('/ownership/businesses/{business}/identities', [OwnershipController::class, 'identity'])->name('ownership.identity');
    Route::post('/ownership/identities/{version}/verify', [OwnershipController::class, 'verify'])->name('ownership.verify');
});
