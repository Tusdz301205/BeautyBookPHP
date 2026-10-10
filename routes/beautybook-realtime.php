<?php
use App\Http\Controllers\Beautybook\RealtimeController;
use Illuminate\Support\Facades\Route;
Route::get('/realtime/session',[RealtimeController::class,'session'])->middleware('throttle:120,1');
