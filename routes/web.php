<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\MediaController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

if (config('beautybook.legacy_ui')) {
    require __DIR__.'/legacy.php';
    return;
}

require __DIR__.'/beautybook.php';

Route::redirect('/', '/beautybook')->name('home');
Route::redirect('/explore', '/beautybook/explore')->name('marketplace.index');
Route::redirect('/login', '/beautybook/login')->name('login');
Route::redirect('/register', '/beautybook/register')->name('register');
Route::redirect('/forgot-password', '/beautybook/forgot-password')->name('password.request');
Route::get('/reset-password/{token}', fn (Request $request, string $token) => redirect('/beautybook/reset-password?'.http_build_query(['token' => $token, 'email' => $request->query('email')])))->name('password.reset');
Route::redirect('/app', '/beautybook')->name('app');
Route::redirect('/verify-email', '/beautybook/verify-email')->name('verification.notice');
Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth', 'signed', 'throttle:6,1'])->name('verification.verify');

// Uploaded media and signed verification links are still used by the React adapters.
Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');

Route::get('/ready', function () {
    try {
        $database = DB::selectOne('select 1 as connected');
        $seeded = User::where('email', 'foundation@beautybook.local')->exists();
        $ready = $database?->connected === 1 && $seeded;

        return response()->json(['status' => $ready ? 'ready' : 'not_ready', 'database' => $database?->connected === 1, 'seeded' => $seeded], $ready ? 200 : 503);
    } catch (Throwable) {
        return response()->json(['status' => 'not_ready', 'database' => false, 'seeded' => false], 503);
    }
});
