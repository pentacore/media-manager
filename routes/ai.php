<?php

declare(strict_types=1);

use App\Http\Controllers\AI\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:admin', 'ai.enabled'])
    ->prefix('ai')
    ->name('ai.')
    ->group(function (): void {
        Route::get('chat', [ChatController::class, 'index'])->name('chat');
        Route::post('chat', [ChatController::class, 'send'])->name('chat.send');
    });
