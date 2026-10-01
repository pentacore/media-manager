<?php

declare(strict_types=1);

use App\Http\Controllers\Prowlarr\GrabReleaseController;
use App\Http\Controllers\Prowlarr\SearchIndexersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:member'])
    ->prefix('prowlarr')
    ->name('prowlarr.')
    ->group(function (): void {
        Route::get('search', SearchIndexersController::class)->name('search');

        // Admin-only: send a searched release to Prowlarr's download client.
        Route::middleware('can:admin')->group(function (): void {
            Route::post('grab', GrabReleaseController::class)->name('grab');
        });
    });
