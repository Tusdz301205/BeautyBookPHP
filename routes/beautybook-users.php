<?php

use App\Http\Controllers\Beautybook\UsersController as C;
use Illuminate\Support\Facades\Route;

Route::get('users/me/profile', [C::class, 'me']);
Route::patch('users/me/profile', [C::class, 'updateMe']);
Route::get('users', [C::class, 'index']);
Route::get('users/{id}', [C::class, 'show']);
Route::get('users/{id}/suspension-impact', [C::class, 'suspensionImpact']);
Route::post('users/{id}/suspend', [C::class, 'suspend']);
Route::post('users/{id}/roles', [C::class, 'grant']);
Route::delete('users/{id}/roles/{role}', [C::class, 'revoke']);
