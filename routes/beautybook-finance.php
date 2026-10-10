<?php

use App\Http\Controllers\Beautybook\FinanceController as F;
use Illuminate\Support\Facades\Route;

Route::get('payments/providers', [F::class, 'providers']);
Route::get('payments/checkout/{id}', [F::class, 'checkout']);
Route::get('payments/policies', [F::class, 'policies']);
Route::post('payments/policies', [F::class, 'createPolicy']);
Route::get('payments/ledger', [F::class, 'ledger']);
Route::get('payments/platform-statements', [F::class, 'statements']);
Route::post('payments/platform-statements/generate', [F::class, 'generate']);
Route::patch('payments/platform-statements/{id}/status', [F::class, 'transition']);
Route::get('payments/packages', [F::class, 'packageIndex']);
Route::post('payments/packages', [F::class, 'createPackage']);
Route::post('payments/packages/{id}/purchases', [F::class, 'purchase']);
Route::get('payments/package-purchases', [F::class, 'purchases']);
Route::post('payments/package-installments/{id}/pay', [F::class, 'installment']);
Route::post('payments/package-purchases/{id}/sessions/reserve', [F::class, 'reserve']);
Route::post('payments/transactions/{id}/verify', [F::class, 'verify']);
Route::post('payments/transactions/{id}/reverse', [F::class, 'reverse']);
Route::post('payments/{id}/refund-requests', [F::class, 'refund']);
Route::patch('payments/refunds/{id}/review', [F::class, 'review']);
Route::post('payments/refunds/{id}/process', [F::class, 'process']);
Route::get('payments', [F::class, 'index']);
Route::post('payments/collect',[F::class, 'collect']);
