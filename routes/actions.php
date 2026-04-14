<?php

declare(strict_types=1);

use App\Http\Controllers\Actions\ActionRequestController;
use App\Http\Controllers\Actions\ActionTypeConfigController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set'])->group(function (): void {
    // Action requests: members can view + approve/reject; admins can also retry
    Route::prefix('actions')->name('actions.')->middleware('role:member')->group(function (): void {
        Route::get('requests', [ActionRequestController::class, 'index'])->name('requests.index');
        Route::post('requests/{actionRequest}/approve', [ActionRequestController::class, 'approve'])
            ->name('requests.approve');
        Route::post('requests/{actionRequest}/reject', [ActionRequestController::class, 'reject'])
            ->name('requests.reject');
        Route::post('requests/{actionRequest}/retry', [ActionRequestController::class, 'retry'])
            ->middleware('role:admin')
            ->name('requests.retry');
    });

    // Action rules: admin only
    Route::prefix('actions')->name('actions.')->middleware('role:admin')->group(function (): void {
        Route::get('rules', [ActionTypeConfigController::class, 'index'])->name('rules.index');
        Route::patch('rules/{actionTypeConfig}', [ActionTypeConfigController::class, 'update'])
            ->name('rules.update');
    });
});
