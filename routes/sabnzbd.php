<?php

declare(strict_types=1);

use App\Http\Controllers\Sabnzbd\QueueController;
use App\Services\Sabnzbd\SabnzbdClient;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:member'])
    ->prefix('sabnzbd')
    ->name('sabnzbd.')
    ->group(function (): void {
        Route::get('queue', [QueueController::class, 'index'])->name('queue.index');
        Route::post('queue/pause', [QueueController::class, 'pauseQueue'])->name('queue.pause');
        Route::post('queue/resume', [QueueController::class, 'resumeQueue'])->name('queue.resume');

        // Every nzo_id must look like one, so nothing else rides into
        // SABnzbd's API query string; anything else is a 404.
        Route::post('queue/{nzoId}/pause', [QueueController::class, 'pauseSlot'])
            ->where('nzoId', SabnzbdClient::NZO_ID_PATTERN)
            ->name('queue.slot.pause');
        Route::post('queue/{nzoId}/resume', [QueueController::class, 'resumeSlot'])
            ->where('nzoId', SabnzbdClient::NZO_ID_PATTERN)
            ->name('queue.slot.resume');
        Route::delete('queue/{nzoId}', [QueueController::class, 'deleteSlot'])
            ->where('nzoId', SabnzbdClient::NZO_ID_PATTERN)
            ->name('queue.slot.delete');
        Route::patch('queue/{nzoId}/priority', [QueueController::class, 'reprioritize'])
            ->where('nzoId', SabnzbdClient::NZO_ID_PATTERN)
            ->name('queue.slot.priority');

        // Admin-only download management: speed limit, history, and bulk slot actions.
        Route::middleware('can:admin')->group(function (): void {
            Route::post('speed-limit', [QueueController::class, 'setSpeedLimit'])->name('speed-limit.update');
            Route::post('history/{nzoId}/retry', [QueueController::class, 'retryHistory'])
                ->where('nzoId', SabnzbdClient::NZO_ID_PATTERN)
                ->name('history.retry');
            Route::delete('history/{nzoId}', [QueueController::class, 'deleteHistory'])
                ->where('nzoId', SabnzbdClient::NZO_ID_PATTERN)
                ->name('history.destroy');
            Route::post('queue/bulk', [QueueController::class, 'bulk'])->name('queue.bulk');
        });
    });
