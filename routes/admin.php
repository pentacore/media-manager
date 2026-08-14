<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AiConversationController;
use App\Http\Controllers\Admin\AiFreeUsagePoolController;
use App\Http\Controllers\Admin\AiModelPriceController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\AiUsageController;
use App\Http\Controllers\Admin\DecisionAgentSettingsController;
use App\Http\Controllers\Admin\EmbyLinkController;
use App\Http\Controllers\Admin\JobsController;
use App\Http\Controllers\Admin\MediaReplacementSettingsController;
use App\Http\Controllers\Admin\ProwlarrTestIndexerController;
use App\Http\Controllers\Admin\ServiceConnectionController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('connections', ServiceConnectionController::class)
        ->parameters(['connections' => 'serviceConnection'])
        ->except(['show']);
    Route::patch('connections/{serviceConnection}/toggle', [ServiceConnectionController::class, 'toggle'])
        ->name('connections.toggle');
    Route::post('connections/test', [ServiceConnectionController::class, 'test'])
        ->name('connections.test');
    Route::post('connections/{serviceConnection}/check-health', [ServiceConnectionController::class, 'checkHealth'])
        ->name('connections.check-health');
    Route::post('connections/{serviceConnection}/check-version', [ServiceConnectionController::class, 'checkVersion'])
        ->name('connections.check-version');
    Route::post('connections/{serviceConnection}/configure-webhook', [ServiceConnectionController::class, 'configureWebhook'])
        ->name('connections.configure-webhook');
    Route::post('connections/{serviceConnection}/prowlarr/test-indexer/{indexerId}', ProwlarrTestIndexerController::class)
        ->name('connections.prowlarr.test-indexer');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/link-emby', [EmbyLinkController::class, 'link'])->name('users.link-emby');
    Route::post('users/import-from-emby', [EmbyLinkController::class, 'import'])->name('users.import-from-emby');

    Route::get('media-replacement', [MediaReplacementSettingsController::class, 'index'])->name('media-replacement.index');
    Route::put('media-replacement', [MediaReplacementSettingsController::class, 'update'])->name('media-replacement.update');

    Route::middleware('ai.enabled')->group(function (): void {
        Route::get('ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
        Route::put('ai-settings', [AiSettingsController::class, 'update'])->name('ai-settings.update');

        Route::get('decision-agent', [DecisionAgentSettingsController::class, 'index'])->name('decision-agent.index');
        Route::put('decision-agent', [DecisionAgentSettingsController::class, 'update'])->name('decision-agent.update');

        Route::get('ai-usage', [AiUsageController::class, 'index'])->name('ai-usage.index');
        Route::get('ai-usage/export', [AiUsageController::class, 'export'])->name('ai-usage.export');
        Route::get('ai-usage/{aiUsageRecord}', [AiUsageController::class, 'show'])->name('ai-usage.show');
        Route::post('ai-usage/{aiUsageRecord}/assign-price', [AiUsageController::class, 'assignPrice'])->name('ai-usage.assign-price');

        Route::prefix('ai/conversations')->name('ai-conversations.')->group(function (): void {
            Route::get('/', [AiConversationController::class, 'index'])->name('index');
            Route::get('{conversation}', [AiConversationController::class, 'show'])
                ->whereUuid('conversation')
                ->name('show');
            Route::post('{conversation}/archive', [AiConversationController::class, 'archive'])
                ->whereUuid('conversation')
                ->name('archive');
            Route::post('{conversation}/unarchive', [AiConversationController::class, 'unarchive'])
                ->whereUuid('conversation')
                ->name('unarchive');
            Route::delete('{conversation}', [AiConversationController::class, 'destroy'])
                ->whereUuid('conversation')
                ->name('destroy');
        });

        Route::get('ai-prices', [AiModelPriceController::class, 'index'])->name('ai-prices.index');
        Route::post('ai-prices', [AiModelPriceController::class, 'store'])->name('ai-prices.store');
        Route::post('ai-prices/refresh', [AiModelPriceController::class, 'refresh'])->name('ai-prices.refresh');
        Route::put('ai-prices/{aiModelPrice}', [AiModelPriceController::class, 'update'])->name('ai-prices.update');
        Route::delete('ai-prices/{aiModelPrice}', [AiModelPriceController::class, 'destroy'])->name('ai-prices.destroy');

        Route::post('ai-free-usage-pools', [AiFreeUsagePoolController::class, 'store'])->name('ai-free-usage-pools.store');
        Route::put('ai-free-usage-pools/{aiFreeUsagePool}', [AiFreeUsagePoolController::class, 'update'])->name('ai-free-usage-pools.update');
        Route::delete('ai-free-usage-pools/{aiFreeUsagePool}', [AiFreeUsagePoolController::class, 'destroy'])->name('ai-free-usage-pools.destroy');
    });

    Route::get('webhook-log', [WebhookLogController::class, 'index'])->name('webhook-log.index');
    Route::put('webhook-log/settings', [WebhookLogController::class, 'updateSettings'])->name('webhook-log.update-settings');
    Route::get('webhook-log/{webhookEvent}', [WebhookLogController::class, 'show'])->name('webhook-log.show');

    Route::get('jobs', [JobsController::class, 'index'])->name('jobs.index');

    Route::get('statistics', StatisticsController::class)->name('statistics.index');
});
