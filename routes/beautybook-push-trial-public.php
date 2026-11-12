<?php

use App\Http\Controllers\Beautybook\PushTrialController as C;
use App\Services\Push\PushService;
use Illuminate\Support\Facades\Route;

// Trial stand-ins for Google OAuth and FCM HTTP v1. Registered only when PUSH_TRIAL_SINK=true in local/testing.
if (PushService::trialSink()) {
    Route::post('push-trial/oauth/token', [C::class, 'token'])->middleware('throttle:120,1');
    Route::post('push-trial/projects/{project}/messages:send', [C::class, 'send'])->middleware('throttle:600,1');
}
