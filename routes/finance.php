<?php

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AdvancedFinanceController;
use Illuminate\Support\Facades\Route;

Route::get('/treatment-packages',[AdvancedFinanceController::class,'publicPackages'])->name('finance.packages');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/finance',[AdvancedFinanceController::class,'index'])->name('finance.advanced');
    Route::post('/finance/customers/lookup',[AdvancedFinanceController::class,'lookupCustomer'])->middleware('throttle:15,1')->name('finance.customers.lookup');
    Route::post('/finance/businesses/{business}/policies',[AdvancedFinanceController::class,'policy'])->name('finance.policies.store');
    Route::post('/finance/businesses/{business}/packages',[AdvancedFinanceController::class,'createPackage'])->name('finance.packages.store');
    Route::post('/finance/packages/{package}/purchase',[AdvancedFinanceController::class,'purchase'])->name('finance.packages.purchase');
    Route::post('/finance/installments/{installment}',[AdvancedFinanceController::class,'payInstallment'])->name('finance.installments.pay');
    Route::post('/finance/package-transactions/{transaction}/{action}',[AdvancedFinanceController::class,'packageTransaction'])->name('finance.packages.transaction');
    Route::post('/finance/purchases/{purchase}/reserve',[AdvancedFinanceController::class,'reserve'])->name('finance.packages.reserve');
    Route::post('/finance/businesses/{business}/statements',[AdvancedFinanceController::class,'statement'])->name('finance.statements.store');
    Route::post('/finance/statements/{statement}',[AdvancedFinanceController::class,'transitionStatement'])->name('finance.statements.transition');
    Route::post('/finance/bookings/{booking}/invoice-requests',[AdvancedFinanceController::class,'invoiceRequest'])->name('finance.invoice-requests.store');
    Route::post('/finance/invoice-requests/{invoiceRequest}/{action}',[AdvancedFinanceController::class,'resolveInvoiceRequest'])->name('finance.invoice-requests.action');
    Route::post('/finance/bookings/{booking}/invoices',[AdvancedFinanceController::class,'issueInvoice'])->name('finance.invoices.store');
    Route::get('/finance/invoices/{invoice}',[AdvancedFinanceController::class,'invoice'])->name('finance.invoice');
    Route::post('/finance/invoices/{invoice}/{action}',[AdvancedFinanceController::class,'changeInvoice'])->name('finance.invoices.action');
    Route::get('/payments', [PaymentController::class, 'index'])->name('finance.index');
    Route::get('/payments/bookings/{booking}', [PaymentController::class, 'show'])->name('finance.show');
    Route::post('/payments/bookings/{booking}', [PaymentController::class, 'collect'])->name('finance.collect');
    Route::post('/payments/transactions/{transaction}/{action}', [PaymentController::class, 'transactionAction'])->name('finance.transaction');
    Route::post('/payments/refunds/{refund}/{action}', [PaymentController::class, 'refundAction'])->name('finance.refund');
});
