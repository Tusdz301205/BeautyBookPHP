<?php

use App\Http\Controllers\Beautybook\SepayController;
use Illuminate\Support\Facades\Route;

Route::post('payments/sepay/webhook', [SepayController::class, 'webhook'])->middleware(['sepay.ip', 'throttle:120,1']);
Route::post('payments/sepay/wallet-live-lab/webhook', [SepayController::class, 'liveLabWebhook'])->middleware(['sepay.ip', 'throttle:120,1']);
Route::post('payments/sepay/connections/{id}/webhook', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'webhook'])->whereNumber('id')->middleware(['sepay.ip', 'throttle:120,1']);
