<?php

declare(strict_types=1);

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

        // Radarr movies
        Route::get('movies', [MovieController::class, 'index'])->name('movies.index');
        Route::get('movies/create', [MovieController::class, 'create'])->name('movies.create');
        Route::post('movies', [MovieController::class, 'store'])->name('movies.store');
        Route::get('movies/{id}', [MovieController::class, 'show'])->whereNumber('id')->name('movies.show');
        Route::delete('movies/{id}', [MovieController::class, 'destroy'])->whereNumber('id')->name('movies.destroy');

        // Jellyseerr requests
        Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
        Route::delete('requests/{id}', [RequestController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('role:admin')
            ->name('requests.destroy');

        // Unified search
        Route::get('search', [SearchController::class, 'index'])->name('search.index');
    });
