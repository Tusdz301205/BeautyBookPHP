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

// Direct deposits (direct-deposit.controller.ts): the owner's receiving accounts and a booking's deposit view and actions.
Route::get('payments/businesses/{businessId}/connections', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'connections'])->whereNumber('businessId');
Route::post('payments/businesses/{businessId}/connections', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'create'])->whereNumber('businessId');
Route::patch('payments/businesses/{businessId}/connections/{id}', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'enable'])->whereNumber(['businessId', 'id']);
Route::get('payments/admin/deposits/refunds', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'adminRefunds']);
Route::get('payments/admin/deposits/receipts', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'adminReceipts']);
Route::post('payments/admin/deposits/receipts/{id}/review', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'adminReviewReceipt'])->whereNumber('id');
Route::get('payments/bookings/{id}/deposit', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'read'])->whereNumber('id');
Route::post('payments/bookings/{id}/deposit/{action}', [\App\Http\Controllers\Beautybook\DirectDepositController::class, 'action'])->whereNumber('id');

// Customer wallet, Live-lab top-ups, business bank accounts and MOCK payouts (wallet.controller.ts). Admin paths are platform-only.
Route::prefix('wallet')->group(function () {
    $c = \App\Http\Controllers\Beautybook\WalletController::class;
    Route::get('/', [$c, 'mine']);
    Route::get('deposit-terms', [$c, 'depositTerms']);
    Route::post('topups', [$c, 'topUp']);
    Route::get('topups/{id}', [$c, 'topUpStatus'])->whereNumber('id');
    Route::get('businesses/{businessId}', [$c, 'business'])->whereNumber('businessId');
    Route::post('businesses/{businessId}/accounts', [$c, 'addBank'])->whereNumber('businessId');
    Route::put('businesses/{businessId}/accounts/{id}', [$c, 'replaceBank'])->whereNumber(['businessId', 'id']);
    Route::get('businesses/{businessId}/withdrawals', [$c, 'withdrawals'])->whereNumber('businessId');
    Route::post('businesses/{businessId}/withdrawals', [$c, 'requestWithdrawal'])->whereNumber('businessId');
    Route::post('businesses/{businessId}/withdrawals/{id}/cancel', [$c, 'cancelWithdrawal'])->whereNumber(['businessId', 'id']);
    Route::get('admin', [$c, 'admin']);
    Route::get('admin/withdrawals', [$c, 'adminWithdrawals']);
    Route::post('admin/businesses/{businessId}/accounts/{id}/verify', [$c, 'verifyBank'])->whereNumber(['businessId', 'id']);
    Route::post('admin/withdrawals/{id}/{action}', [$c, 'decide'])->whereNumber('id');
    Route::post('admin/receipts/{id}/reconcile', [$c, 'reconcile'])->whereNumber('id');
    Route::post('admin/settlements', [$c, 'settle']);
    Route::post('admin/settlements/{id}/complete', [$c, 'completeSettlement'])->whereNumber('id');
});
