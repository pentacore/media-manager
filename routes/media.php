<?php

declare(strict_types=1);

use App\Http\Controllers\Library\ActivityController as LibraryActivityController;
use App\Http\Controllers\Media\AnimeController;
use App\Http\Controllers\Media\InstantSearchController;
use App\Http\Controllers\Media\MovieController;
use App\Http\Controllers\Media\RequestController;
use App\Http\Controllers\Media\SearchController;
use App\Http\Controllers\Media\SeriesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:member'])
    ->prefix('media')
    ->name('media.')
    ->group(function (): void {
        // Sonarr series
        Route::get('series', [SeriesController::class, 'index'])->name('series.index');
        Route::get('series/create', [SeriesController::class, 'create'])->name('series.create');
        Route::post('series', [SeriesController::class, 'store'])->name('series.store');
        Route::get('series/{id}', [SeriesController::class, 'show'])->whereNumber('id')->name('series.show');
        Route::delete('series/{id}', [SeriesController::class, 'destroy'])->whereNumber('id')->name('series.destroy');

        // Seasonal anime discovery + requests
        Route::get('anime', [AnimeController::class, 'index'])->name('anime.index');
        Route::post('anime/request', [AnimeController::class, 'request'])->name('anime.request');
        Route::post('anime/find-match', [AnimeController::class, 'findMatch'])->name('anime.find-match');
        Route::post('anime/confirm-match', [AnimeController::class, 'confirmMatch'])->name('anime.confirm-match');

        // Radarr movies
        Route::get('movies', [MovieController::class, 'index'])->name('movies.index');
        Route::get('movies/create', [MovieController::class, 'create'])->name('movies.create');
        Route::post('movies', [MovieController::class, 'store'])->name('movies.store');
        Route::get('movies/{id}', [MovieController::class, 'show'])->whereNumber('id')->name('movies.show');
        Route::delete('movies/{id}', [MovieController::class, 'destroy'])->whereNumber('id')->name('movies.destroy');

        // Seerr requests
        Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
        Route::post('requests/{id}/approve', [RequestController::class, 'approve'])
            ->whereNumber('id')
            ->name('requests.approve');
        Route::post('requests/{id}/decline', [RequestController::class, 'decline'])
            ->whereNumber('id')
            ->name('requests.decline');
        Route::post('requests/{id}/retry', [RequestController::class, 'retry'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('requests.retry');
        Route::delete('requests/{id}', [RequestController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('requests.destroy');
        Route::post('requests/clear', [RequestController::class, 'clear'])
            ->middleware('role:admin')
            ->name('requests.clear');
        Route::get('requests/{id}/edit-options', [RequestController::class, 'editOptions'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('requests.edit-options');
        Route::put('requests/{id}', [RequestController::class, 'update'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('requests.update');

        // Unified search
        Route::get('search', [SearchController::class, 'index'])->name('search.index');
        Route::get('search/instant', InstantSearchController::class)->name('search.instant');

        // Combined Sonarr + Radarr download queue
        Route::get('library/activity/queue', [LibraryActivityController::class, 'queue'])
            ->name('library.activity.queue');
        Route::post('library/activity/queue/{service}/{id}/remove', [LibraryActivityController::class, 'removeQueueItem'])
            ->whereIn('service', ['sonarr', 'radarr'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('library.activity.queue.remove');
        Route::post('library/activity/queue/{service}/{id}/grab', [LibraryActivityController::class, 'grabQueueItem'])
            ->whereIn('service', ['sonarr', 'radarr'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('library.activity.queue.grab');
        Route::get('library/activity/manual-import/{service}/{downloadId}', [LibraryActivityController::class, 'manualImportCandidates'])
            ->whereIn('service', ['sonarr', 'radarr'])
            ->middleware('role:admin')
            ->name('library.activity.manual-import.candidates');
        Route::post('library/activity/manual-import/{service}', [LibraryActivityController::class, 'executeManualImport'])
            ->whereIn('service', ['sonarr', 'radarr'])
            ->middleware('role:admin')
            ->name('library.activity.manual-import.execute');
    });
