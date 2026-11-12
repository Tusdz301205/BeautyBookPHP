<?php

use App\Http\Controllers\Beautybook\ChatController as C;
use Illuminate\Support\Facades\Route;

Route::prefix('chat/{scope}/conversations')->whereIn('scope', ['customer', 'branch'])->group(function () {
    Route::get('/', [C::class, 'list']);
    Route::post('/', [C::class, 'open'])->middleware('throttle:60,1');
    Route::get('{id}/messages', [C::class, 'messages'])->whereNumber('id');
    Route::post('{id}/messages', [C::class, 'send'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('{id}/read', [C::class, 'read'])->whereNumber('id');
    Route::post('{id}/images', [C::class, 'upload'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('{id}/images/{imageId}', [C::class, 'image'])->whereNumber(['id', 'imageId']);
});
