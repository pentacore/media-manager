<?php

declare(strict_types=1);

use App\Http\Controllers\Bazarr\AdminController;
use App\Http\Controllers\Bazarr\AdvisorController;
use App\Http\Controllers\Bazarr\CapabilityController;
use App\Http\Controllers\Bazarr\EscalationController;
use App\Http\Controllers\Bazarr\HistoryController;
use App\Http\Controllers\Bazarr\LibraryController;
use App\Http\Controllers\Bazarr\MissingController;
use App\Http\Controllers\Bazarr\OperationController;
use App\Http\Controllers\Bazarr\OverviewController;
use App\Http\Controllers\Bazarr\SearchController;
use App\Http\Controllers\Bazarr\UploadController;
use App\Http\Controllers\Webhooks\BazarrNotificationController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/bazarr/{serviceConnection}', BazarrNotificationController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware(['throttle:60,1', 'webhook.payload-limit'])
    ->name('webhooks.bazarr');

// Every subtitle page names library files, provider results and failure
// reasons — members and admins only, matching the "Subtitles" nav item.
Route::middleware(['auth', 'verified', 'password.set', 'can:manage-library'])
    ->prefix('subtitles')
    ->name('bazarr.')
    ->group(function (): void {
        Route::get('/', OverviewController::class)->name('overview');
        Route::get('missing', MissingController::class)->name('missing');
        Route::get('library', LibraryController::class)->name('library');
        Route::get('history', HistoryController::class)->name('history');
        Route::get('escalations', EscalationController::class)->name('escalations');

        Route::middleware('role:admin')->group(function (): void {
            Route::get('admin', [AdminController::class, 'index'])->name('admin.index');
            Route::put('admin', [AdminController::class, 'update'])->name('admin.update');
            Route::put('admin/automation', [AdminController::class, 'updateAutomation'])->name('admin.automation.update');
        });

        Route::post('escalations/{subtitleCase}/advisor', AdvisorController::class)
            ->name('advisor.store');
        Route::get('capabilities', CapabilityController::class)->name('capabilities');
        Route::get('search', SearchController::class)->name('search');
        Route::post('operations', OperationController::class)->name('operations.store');
        Route::post('uploads', UploadController::class)->name('uploads.store');
    });
