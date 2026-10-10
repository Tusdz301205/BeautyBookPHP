<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Beautybook\VouchersController as V;
Route::get('vouchers/mine',[V::class,'mine']);
Route::get('vouchers',[V::class,'index']);
Route::post('vouchers',[V::class,'save']);
Route::patch('vouchers/{id}',[V::class,'save']);
Route::post('vouchers/{id}/grant',[V::class,'grant']);
Route::delete('vouchers/{id}',[V::class,'remove']);
