<?php

declare(strict_types=1);

use App\Http\Controllers\Bazarr\HistoryController;
use App\Http\Controllers\Bazarr\LibraryController;
use App\Http\Controllers\Bazarr\MissingController;
use App\Http\Controllers\Bazarr\OverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:viewer'])
    ->prefix('subtitles')
    ->name('bazarr.')
    ->group(function (): void {
        Route::get('/', OverviewController::class)->name('overview');
        Route::get('missing', MissingController::class)->name('missing');
        Route::get('library', LibraryController::class)->name('library');
        Route::get('history', HistoryController::class)->name('history');
    });
