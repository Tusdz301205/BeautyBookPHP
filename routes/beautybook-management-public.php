<?php
\Illuminate\Support\Facades\Route::get('services/canonical',[\App\Http\Controllers\Beautybook\ManagementCatalogController::class,'publicCanonicals']);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Beautybook\{ManagementStaffController as Staff,ManagementOffersController as Offers};

Route::get('combos/public',[Offers::class,'publicCombos']);
Route::get('promotions/service/{serviceId}',[Offers::class,'publicPromotions']);
Route::get('staff/invitations/context',[Staff::class,'invitationContext']);
Route::post('staff/invitations/accept',[Staff::class,'acceptInvitation']);
