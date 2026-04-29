<?php

declare(strict_types=1);

use App\Http\Controllers\Sabnzbd\QueueController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:member'])
    ->prefix('sabnzbd')
    ->name('sabnzbd.')
    ->group(function (): void {
        Route::get('queue', [QueueController::class, 'index'])->name('queue.index');
        Route::post('queue/pause', [QueueController::class, 'pauseQueue'])->name('queue.pause');
        Route::post('queue/resume', [QueueController::class, 'resumeQueue'])->name('queue.resume');
        Route::post('queue/{nzoId}/pause', [QueueController::class, 'pauseSlot'])->name('queue.slot.pause');
        Route::post('queue/{nzoId}/resume', [QueueController::class, 'resumeSlot'])->name('queue.slot.resume');
        Route::delete('queue/{nzoId}', [QueueController::class, 'deleteSlot'])->name('queue.slot.delete');
        Route::patch('queue/{nzoId}/priority', [QueueController::class, 'reprioritize'])->name('queue.slot.priority');
    });
