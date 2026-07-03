<?php

declare(strict_types=1);

use App\Http\Controllers\AI\ChatController;
use App\Http\Controllers\AI\ConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:admin', 'ai.enabled'])
    ->prefix('ai')
    ->name('ai.')
    ->group(function (): void {
        Route::get('chat', [ChatController::class, 'index'])->name('chat');
        Route::post('chat', [ChatController::class, 'send'])->name('chat.send');
        Route::post('chat/stream', [ChatController::class, 'stream'])->name('chat.stream');
        Route::get('chat/pending-workflow', [ChatController::class, 'pendingWorkflow'])->name('chat.pending-workflow');

        Route::get('conversations', [ConversationController::class, 'index'])
            ->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
            ->whereUuid('conversation')
            ->name('conversations.show');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'rename'])
            ->whereUuid('conversation')
            ->name('conversations.rename');
    });
