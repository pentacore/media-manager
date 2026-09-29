<?php

declare(strict_types=1);

use App\Http\Controllers\Library\ActivityController as LibraryActivityController;
use App\Http\Controllers\Library\CalendarController;
use App\Http\Controllers\Library\MediaActionController;
use App\Http\Controllers\Library\WantedController;
use App\Http\Controllers\Media\AnimeController;
use App\Http\Controllers\Media\DiscoverController;
use App\Http\Controllers\Media\InstantSearchController;
use App\Http\Controllers\Media\MediaReplacementController;
use App\Http\Controllers\Media\MovieController;
use App\Http\Controllers\Media\MyRequestController;
use App\Http\Controllers\Media\RequestController;
use App\Http\Controllers\Media\SearchController;
use App\Http\Controllers\Media\SeriesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set'])
    ->prefix('media')
    ->name('media.')
    ->group(function (): void {
        // Read-only library, search and (Part 2/4) discover/calendar — every role.
        Route::middleware('can:view-library')->group(function (): void {
            Route::get('series', [SeriesController::class, 'index'])->name('series.index');
            Route::get('series/{id}', [SeriesController::class, 'show'])->whereNumber('id')->name('series.show');
            Route::get('movies', [MovieController::class, 'index'])->name('movies.index');
            Route::get('movies/{id}', [MovieController::class, 'show'])->whereNumber('id')->name('movies.show');

            // Unified search — viewers get the Seerr scope only (SearchController).
            Route::get('search', [SearchController::class, 'index'])->name('search.index');
            Route::get('search/instant', InstantSearchController::class)->name('search.instant');

            // Seerr discovery (read-only; requests go through the request-media group)
            Route::get('discover', [DiscoverController::class, 'index'])->name('discover.index');
            Route::get('discover/{mediaType}/{tmdbId}', [DiscoverController::class, 'title'])
                ->whereIn('mediaType', ['movie', 'tv'])
                ->whereNumber('tmdbId')
                ->name('discover.title');

            // Merged Sonarr + Radarr calendar (month grid / agenda)
            Route::get('calendar', CalendarController::class)->name('calendar.index');
        });

        // Filing Seerr requests as oneself — every role (Seerr's own approval and quotas apply).
        Route::middleware('can:request-media')->group(function (): void {
            Route::post('discover/request', [DiscoverController::class, 'request'])
                ->middleware('throttle:seerr-request')
                ->name('discover.request');

            // My requests — list and cancel your own pending Seerr requests.
            Route::get('requests/mine', [MyRequestController::class, 'index'])->name('requests.mine');
            Route::delete('requests/mine/{id}', [MyRequestController::class, 'destroy'])->whereNumber('id')->name('requests.mine.destroy');
        });

        // Library writes — members and admins.
        Route::middleware('can:manage-library')->group(function (): void {
            Route::get('series/create', [SeriesController::class, 'create'])->name('series.create');
            Route::post('series', [SeriesController::class, 'store'])->name('series.store');
            Route::delete('series/{id}', [SeriesController::class, 'destroy'])->whereNumber('id')->name('series.destroy');

            Route::get('movies/create', [MovieController::class, 'create'])->name('movies.create');
            Route::post('movies', [MovieController::class, 'store'])->name('movies.store');
            Route::delete('movies/{id}', [MovieController::class, 'destroy'])->whereNumber('id')->name('movies.destroy');

            // Missing / cutoff-unmet lists from the primary Sonarr + Radarr
            Route::get('wanted', WantedController::class)->name('wanted.index');

            // Manual media replacement
            Route::get('replacement/inspect', [MediaReplacementController::class, 'inspect'])->name('replacement.inspect');
            Route::get('replacement/candidates', [MediaReplacementController::class, 'candidates'])->name('replacement.candidates');
            Route::post('replacement/replace', [MediaReplacementController::class, 'replace'])->name('replacement.replace');

            // Combined Sonarr + Radarr download queue
            Route::get('library/activity/queue', [LibraryActivityController::class, 'queue'])
                ->name('library.activity.queue');

            // Library actions from the series/movie/calendar/Wanted pages (Action Queue)
            Route::prefix('library/actions')->name('library.actions.')->group(function (): void {
                Route::post('monitor', [MediaActionController::class, 'monitor'])->name('monitor');
                Route::post('monitor-episodes', [MediaActionController::class, 'monitorEpisodes'])->name('monitor-episodes');
                Route::post('quality-profile', [MediaActionController::class, 'qualityProfile'])->name('quality-profile');
                Route::post('search', [MediaActionController::class, 'search'])->name('search');
                Route::get('releases', [MediaActionController::class, 'releases'])->name('releases');
                Route::post('grab', [MediaActionController::class, 'grab'])->name('grab');
            });

            Route::middleware('role:admin')->group(function (): void {
                Route::post('library/activity/queue/{service}/{id}/remove', [LibraryActivityController::class, 'removeQueueItem'])
                    ->whereIn('service', ['sonarr', 'radarr'])
                    ->whereNumber('id')
                    ->name('library.activity.queue.remove');
                Route::post('library/activity/queue/{service}/{id}/grab', [LibraryActivityController::class, 'grabQueueItem'])
                    ->whereIn('service', ['sonarr', 'radarr'])
                    ->whereNumber('id')
                    ->name('library.activity.queue.grab');
                Route::get('library/activity/manual-import/{service}/{downloadId}', [LibraryActivityController::class, 'manualImportCandidates'])
                    ->whereIn('service', ['sonarr', 'radarr'])
                    ->name('library.activity.manual-import.candidates');
                Route::post('library/activity/manual-import/{service}', [LibraryActivityController::class, 'executeManualImport'])
                    ->whereIn('service', ['sonarr', 'radarr'])
                    ->name('library.activity.manual-import.execute');
            });
        });

        // Seerr request management — members and admins.
        Route::middleware('can:manage-requests')->group(function (): void {
            // Seasonal anime discovery + requests (requests on behalf of any Seerr user)
            Route::get('anime', [AnimeController::class, 'index'])->name('anime.index');
            Route::post('anime/request', [AnimeController::class, 'request'])->name('anime.request');
            Route::post('anime/find-match', [AnimeController::class, 'findMatch'])->name('anime.find-match');
            Route::post('anime/confirm-match', [AnimeController::class, 'confirmMatch'])->name('anime.confirm-match');

            // Seerr requests console
            Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
            Route::post('requests/{id}/approve', [RequestController::class, 'approve'])->whereNumber('id')->name('requests.approve');
            Route::post('requests/{id}/decline', [RequestController::class, 'decline'])->whereNumber('id')->name('requests.decline');

            Route::middleware('role:admin')->group(function (): void {
                Route::post('requests/{id}/retry', [RequestController::class, 'retry'])->whereNumber('id')->name('requests.retry');
                Route::delete('requests/{id}', [RequestController::class, 'destroy'])->whereNumber('id')->name('requests.destroy');
                Route::post('requests/clear', [RequestController::class, 'clear'])->name('requests.clear');
                Route::get('requests/{id}/edit-options', [RequestController::class, 'editOptions'])->whereNumber('id')->name('requests.edit-options');
                Route::put('requests/{id}', [RequestController::class, 'update'])->whereNumber('id')->name('requests.update');
            });
        });
    });
