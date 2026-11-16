<?php

use App\Http\Controllers\Beautybook\EngagementController as C;
use Illuminate\Support\Facades\Route;

Route::get('notifications', [C::class, 'notifications']);
Route::get('notifications/unread-count', [C::class, 'unread']);
Route::patch('notifications/read-all', [C::class, 'readAll']);
Route::patch('notifications/{id}/read', [C::class, 'read']);
Route::post('notifications/device-token', [C::class, 'device']);
Route::delete('notifications/device-token', [C::class, 'removeDevice']);
Route::get('notifications/push-config', [\App\Http\Controllers\Beautybook\PushTrialController::class, 'config']);
Route::get('notifications/push-trial/messages', [\App\Http\Controllers\Beautybook\PushTrialController::class, 'messages'])->middleware('throttle:120,1');
Route::get('customer/saved-services', [C::class, 'saved']);
Route::post('customer/saved-services/{id}', [C::class, 'save']);
Route::delete('customer/saved-services/{id}', [C::class, 'unsave']);
Route::get('reviews/manage', [C::class, 'reviews']);
Route::get('reviews/manage/absa/summary', [\App\Http\Controllers\Beautybook\AbsaController::class, 'summary']);
Route::get('reviews/manage/absa/reviews', [\App\Http\Controllers\Beautybook\AbsaController::class, 'reviews']);
Route::get('reviews/staff/{id}', [C::class, 'staffReviews']);
Route::post('reviews', [C::class, 'createReview']);
Route::post('reviews/booking/{booking}/photos', [C::class, 'uploadReviewPhoto'])->whereNumber('booking')->middleware('throttle:30,1');
foreach (['reply' => 'post', 'report' => 'post', 'moderate' => 'patch', 'appeal' => 'post'] as $action => $method) Route::$method('reviews/{id}/'.($action === 'appeal' ? 'appeals' : $action), fn (\Illuminate\Http\Request $r, string $id) => app(C::class)->reviewAction($r, $id, $action));
Route::patch('reviews/appeals/{id}', [C::class, 'resolveAppeal']);
