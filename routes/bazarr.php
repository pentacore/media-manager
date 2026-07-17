<?php

declare(strict_types=1);

use App\Http\Controllers\Bazarr\HistoryController;
use App\Http\Controllers\Bazarr\LibraryController;
use App\Http\Controllers\Bazarr\MissingController;
use App\Http\Controllers\Bazarr\OverviewController;
use App\Http\Controllers\Bazarr\OperationController;
use App\Http\Controllers\Bazarr\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:viewer'])
    ->prefix('subtitles')
    ->name('bazarr.')
    ->group(function (): void {
        Route::get('/', OverviewController::class)->name('overview');
        Route::get('missing', MissingController::class)->name('missing');
        Route::get('library', LibraryController::class)->name('library');
        Route::get('history', HistoryController::class)->name('history');

        Route::middleware('role:member')->group(function (): void {
            Route::get('search', SearchController::class)->name('search');
            Route::post('operations', OperationController::class)->name('operations.store');
        });
    });
