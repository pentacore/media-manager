<?php

declare(strict_types=1);

use App\Http\Controllers\Emby\NowPlayingController;
use App\Http\Controllers\Emby\UserLinkController;
use App\Http\Controllers\Emby\WatchHistoryController;
use App\Http\Controllers\Monitoring\ServiceHealthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set'])->group(function (): void {
    Route::prefix('monitoring')->name('monitoring.')->group(function (): void {
        Route::get('now-playing', NowPlayingController::class)->name('now-playing');
        Route::get('watch-history', [WatchHistoryController::class, 'index'])->name('watch-history');
        Route::get('watch-history/export', [WatchHistoryController::class, 'export'])->name('watch-history.export');
        Route::get('service-health', [ServiceHealthController::class, 'index'])->name('service-health');
        Route::post('service-health/run-checks', [ServiceHealthController::class, 'runChecks'])->name('service-health.run-checks');
    });

    Route::prefix('emby')->name('emby.')->group(function (): void {
        Route::get('links', [UserLinkController::class, 'index'])->middleware('role:admin')->name('links.index');
        Route::post('links', [UserLinkController::class, 'store'])->name('links.store');
        Route::delete('links/{embyUserLink}', [UserLinkController::class, 'destroy'])->name('links.destroy');
    });
});
