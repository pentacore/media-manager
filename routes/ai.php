<?php

declare(strict_types=1);

use App\Http\Controllers\AI\ChatAttachmentController;
use App\Http\Controllers\AI\ChatController;
use App\Http\Controllers\AI\ChatTemplateController;
use App\Http\Controllers\AI\ChatTemplateLibraryController;
use App\Http\Controllers\AI\ChatTemplateOptionsController;
use App\Http\Controllers\AI\ChatTemplatePreviewController;
use App\Http\Controllers\AI\ChatTemplateRenderController;
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
        Route::get('chat/attachments/{chatAttachment}', ChatAttachmentController::class)->name('chat.attachments.show');

        Route::get('conversations', [ConversationController::class, 'index'])
            ->name('conversations.index');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])
            ->whereUuid('conversation')
            ->name('conversations.show');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'rename'])
            ->whereUuid('conversation')
            ->name('conversations.rename');

        Route::get('templates', [ChatTemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/create', [ChatTemplateController::class, 'create'])->name('templates.create');
        Route::post('templates', [ChatTemplateController::class, 'store'])->name('templates.store');
        Route::get('templates/options', ChatTemplateOptionsController::class)->name('templates.options');
        Route::get('templates/library', ChatTemplateLibraryController::class)->name('templates.library');
        Route::post('templates/preview', ChatTemplatePreviewController::class)->name('templates.preview');
        Route::get('templates/{chatTemplate}/edit', [ChatTemplateController::class, 'edit'])
            ->whereNumber('chatTemplate')
            ->name('templates.edit');
        Route::patch('templates/{chatTemplate}', [ChatTemplateController::class, 'update'])
            ->whereNumber('chatTemplate')
            ->name('templates.update');
        Route::delete('templates/{chatTemplate}', [ChatTemplateController::class, 'destroy'])
            ->whereNumber('chatTemplate')
            ->name('templates.destroy');
        Route::patch('templates/{chatTemplate}/pin', [ChatTemplateController::class, 'pin'])
            ->whereNumber('chatTemplate')
            ->name('templates.pin');
        Route::post('templates/{chatTemplate}/render', ChatTemplateRenderController::class)
            ->whereNumber('chatTemplate')
            ->name('templates.render');
    });
