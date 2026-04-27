<?php

declare(strict_types=1);

use App\Http\Controllers\Prowlarr\SearchIndexersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:member'])
    ->prefix('prowlarr')
    ->name('prowlarr.')
    ->group(function (): void {
        Route::get('search', SearchIndexersController::class)->name('search');
    });
