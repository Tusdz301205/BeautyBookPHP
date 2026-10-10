<?php

use App\Http\Controllers\GovernanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account:OPERATIONAL'])->group(function () {
    Route::get('/admin/users', [GovernanceController::class, 'users'])->name('governance.users');
    Route::get('/admin/users/{user}', [GovernanceController::class, 'user'])->name('governance.user');
    Route::put('/admin/users/{user}/status', [GovernanceController::class, 'status'])->name('governance.users.status');
    Route::post('/admin/users/{user}/roles', [GovernanceController::class, 'grantRole'])->name('governance.users.roles.grant');
    Route::delete('/admin/users/{user}/roles/{assignment}', [GovernanceController::class, 'revokeRole'])->name('governance.users.roles.revoke');
    Route::get('/admin/trust', [GovernanceController::class, 'trust'])->name('governance.trust');
    Route::post('/admin/trust/rebuild', [GovernanceController::class, 'rebuildTrust'])->name('governance.trust.rebuild');
    Route::post('/admin/trust/businesses/{business}/actions', [GovernanceController::class, 'trustAction'])->name('governance.trust.actions');
    Route::get('/dashboard', [GovernanceController::class, 'dashboard'])->name('governance.dashboard');
});
