<?php
use App\Http\Controllers\Beautybook\GovernanceController;
use Illuminate\Support\Facades\Route;
Route::get('/platform-settings/public',[GovernanceController::class,'publicSettings']);
