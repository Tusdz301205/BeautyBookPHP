<?php

use App\Http\Controllers\ImpactController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account:OPERATIONAL'])->prefix('impacts')->name('impacts.')->group(function () {
    Route::get('/', [ImpactController::class, 'index'])->name('index');
    Route::get('/{impact}', [ImpactController::class, 'show'])->name('show');
    Route::patch('/{impact}/items/{item}', [ImpactController::class, 'resolve'])->name('resolve');
    Route::patch('/{impact}/items', [ImpactController::class, 'batch'])->name('batch');
    Route::post('/{impact}/complete', [ImpactController::class, 'complete'])->name('complete');
});
