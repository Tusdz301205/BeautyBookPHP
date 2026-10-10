<?php

use App\Http\Controllers\SchedulingController;
use Illuminate\Support\Facades\Route;

Route::get('/combos', [SchedulingController::class, 'publicCombos'])->name('combos.public');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/booking/{branch}/combos/{combo}', [SchedulingController::class, 'comboBookingCreate'])->name('combos.booking.create');
    Route::post('/booking/{branch}/combos/{combo}', [SchedulingController::class, 'comboBookingStore'])->name('combos.booking.store');
    Route::prefix('owner/branches/{branch}/combos')->name('owner.combos.')->group(function () {
        Route::get('/', [SchedulingController::class, 'combos'])->name('index');
        Route::post('/', [SchedulingController::class, 'storeCombo'])->name('store');
        Route::put('/{combo}', [SchedulingController::class, 'updateCombo'])->name('update');
        Route::delete('/{combo}', [SchedulingController::class, 'archiveCombo'])->name('archive');
    });
    Route::get('/recurring', [SchedulingController::class, 'recurringIndex'])->name('recurring.index');
    Route::get('/recurring/branch/{branch}', [SchedulingController::class, 'recurringCreate'])->name('recurring.create');
    Route::post('/recurring/branch/{branch}/preview', [SchedulingController::class, 'recurringPreview'])->name('recurring.preview');
    Route::post('/recurring/branch/{branch}', [SchedulingController::class, 'recurringStore'])->name('recurring.store');
    Route::patch('/recurring/{series}/status', [SchedulingController::class, 'recurringStatus'])->name('recurring.status');
    Route::post('/recurring/{series}/cancel', [SchedulingController::class, 'recurringCancel'])->name('recurring.cancel');
    Route::post('/recurring/{series}/occurrences/{occurrence}/cancel', [SchedulingController::class, 'occurrenceCancel'])->name('recurring.occurrences.cancel');
});
