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
        Route::get('watch-history', WatchHistoryController::class)->name('watch-history');
        Route::get('service-health', ServiceHealthController::class)->name('service-health');
    });

    Route::prefix('emby')->name('emby.')->group(function (): void {
        Route::get('links', [UserLinkController::class, 'index'])->middleware('role:admin')->name('links.index');
        Route::post('links', [UserLinkController::class, 'store'])->name('links.store');
        Route::delete('links/{embyUserLink}', [UserLinkController::class, 'destroy'])->name('links.destroy');
    });
});
