<?php

use App\Http\Controllers\Beautybook\AuthController;
use App\Http\Middleware\BeautybookSession;
use Illuminate\Support\Facades\Route;

Route::get('/beautybook/{path?}', fn () => view('beautybook'))->where('path', '.*')->name('beautybook');
Route::prefix('api/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->block(10, 10);
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->block(10, 10);
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:60,1')->block(10, 10);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:10,1');
    // Image elements authenticate through the HttpOnly session cookie; this route serves only its owner.
    Route::get('users/me/avatar', [\App\Http\Controllers\Beautybook\UsersController::class, 'avatar'])->middleware('auth');
    if (file_exists(__DIR__.'/beautybook-public.php')) require __DIR__.'/beautybook-public.php';
    foreach (['management', 'governance', 'finance'] as $domain) {
        if (file_exists(__DIR__.'/beautybook-'.$domain.'-public.php')) require __DIR__.'/beautybook-'.$domain.'-public.php';
    }
    Route::middleware(BeautybookSession::class)->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession']);
        Route::delete('auth/sessions', [AuthController::class, 'revokeOthers']);
        Route::get('auth/security-history', [AuthController::class, 'history']);
        foreach (['booking', 'finance', 'management', 'governance', 'engagement', 'vouchers', 'users', 'realtime'] as $domain) {
            if (file_exists(__DIR__.'/beautybook-'.$domain.'.php')) require __DIR__.'/beautybook-'.$domain.'.php';
        }
    });
});

// Session refresh and websocket leases must not overwrite each other's session payload.
foreach (Route::getRoutes() as $beautybookRoute) {
    if (str_starts_with($beautybookRoute->uri(), 'api/v1/') && in_array(BeautybookSession::class, $beautybookRoute->gatherMiddleware(), true)) $beautybookRoute->block(10, 10);
}
