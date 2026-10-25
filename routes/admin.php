<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AiConversationController;
use App\Http\Controllers\Admin\AiFreeUsagePoolController;
use App\Http\Controllers\Admin\AiModelCatalogController;
use App\Http\Controllers\Admin\AiModelPriceController;
use App\Http\Controllers\Admin\AiModelsController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\AiUsageController;
use App\Http\Controllers\Admin\BenchmarkCaseController;
use App\Http\Controllers\Admin\BenchmarkComparisonController;
use App\Http\Controllers\Admin\BenchmarkDecisionPromptController;
use App\Http\Controllers\Admin\BenchmarkRunController;
use App\Http\Controllers\Admin\BenchmarkScheduleController;
use App\Http\Controllers\Admin\DecisionAgentSettingsController;
use App\Http\Controllers\Admin\EmbyLinkController;
use App\Http\Controllers\Admin\JobsController;
use App\Http\Controllers\Admin\MediaReplacementAttemptController;
use App\Http\Controllers\Admin\MediaReplacementSettingsController;
use App\Http\Controllers\Admin\NotificationDestinationController;
use App\Http\Controllers\Admin\ProwlarrTestIndexerController;
use App\Http\Controllers\Admin\ServerCheckController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerIncidentController;
use App\Http\Controllers\Admin\ServerWatcherController;
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
    Route::get('media-replacement/attempts', [MediaReplacementAttemptController::class, 'index'])->name('media-replacement.attempts.index');
    Route::get('media-replacement/attempts/{mediaReplacementAttempt}', [MediaReplacementAttemptController::class, 'show'])->name('media-replacement.attempts.show');
    Route::post('media-replacement/attempts/{mediaReplacementAttempt}/acknowledge', [MediaReplacementAttemptController::class, 'acknowledge'])->name('media-replacement.attempts.acknowledge');
    Route::post('media-replacement/attempts/{mediaReplacementAttempt}/restore-monitoring', [MediaReplacementAttemptController::class, 'restoreMonitoring'])->name('media-replacement.attempts.restore-monitoring');
    Route::post('media-replacement/attempts/{mediaReplacementAttempt}/cancel', [MediaReplacementAttemptController::class, 'cancel'])->name('media-replacement.attempts.cancel');

    Route::get('notification-destinations', [NotificationDestinationController::class, 'index'])->name('notification-destinations.index');
    Route::post('notification-destinations', [NotificationDestinationController::class, 'store'])->name('notification-destinations.store');
    Route::put('notification-destinations/{notificationDestination}', [NotificationDestinationController::class, 'update'])->name('notification-destinations.update');
    Route::delete('notification-destinations/{notificationDestination}', [NotificationDestinationController::class, 'destroy'])->name('notification-destinations.destroy');
    Route::post('notification-destinations/{notificationDestination}/test', [NotificationDestinationController::class, 'test'])
        ->middleware('throttle:6,1')
        ->name('notification-destinations.test');

    Route::middleware('ai.enabled')->group(function (): void {
        Route::get('ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
        Route::put('ai-settings', [AiSettingsController::class, 'update'])->name('ai-settings.update');
        Route::post('ai-settings/reembed', [AiSettingsController::class, 'reembed'])->name('ai-settings.reembed');

        Route::get('decision-agent', [DecisionAgentSettingsController::class, 'index'])->name('decision-agent.index');
        Route::put('decision-agent', [DecisionAgentSettingsController::class, 'update'])->name('decision-agent.update');
        Route::get('ai-models', [AiModelsController::class, 'index'])->name('ai-models.index');
        Route::put('ai-models', [AiModelsController::class, 'update'])->name('ai-models.update');

        Route::prefix('benchmarks/cases')->name('benchmarks.cases.')->group(function (): void {
            Route::get('/', [BenchmarkCaseController::class, 'index'])->name('index');
            Route::get('create', [BenchmarkCaseController::class, 'create'])->name('create');
            Route::post('/', [BenchmarkCaseController::class, 'store'])->name('store');
            Route::post('decision-prompt', BenchmarkDecisionPromptController::class)->name('decision-prompt');
            Route::get('{benchmarkCase}/edit', [BenchmarkCaseController::class, 'edit'])->whereNumber('benchmarkCase')->name('edit');
            Route::put('{benchmarkCase}', [BenchmarkCaseController::class, 'update'])->whereNumber('benchmarkCase')->name('update');
            Route::delete('{benchmarkCase}', [BenchmarkCaseController::class, 'destroy'])->whereNumber('benchmarkCase')->name('destroy');
        });

        Route::prefix('benchmarks/compare')->name('benchmarks.compare.')->group(function (): void {
            Route::get('/', [BenchmarkComparisonController::class, 'show'])->name('show');
            Route::post('neutral/estimate', [BenchmarkComparisonController::class, 'estimate'])->name('estimate');
            Route::post('neutral', [BenchmarkComparisonController::class, 'start'])->name('start');
            Route::post('neutral/{benchmarkNeutralRejudge}/cancel', [BenchmarkComparisonController::class, 'cancel'])->whereNumber('benchmarkNeutralRejudge')->name('cancel');
        });

        Route::prefix('benchmarks/schedules')->name('benchmarks.schedules.')->group(function (): void {
            Route::get('/', [BenchmarkScheduleController::class, 'index'])->name('index');
            Route::get('create', [BenchmarkScheduleController::class, 'create'])->name('create');
            Route::post('/', [BenchmarkScheduleController::class, 'store'])->name('store');
            Route::get('{benchmarkSchedule}/edit', [BenchmarkScheduleController::class, 'edit'])->whereNumber('benchmarkSchedule')->name('edit');
            Route::put('{benchmarkSchedule}', [BenchmarkScheduleController::class, 'update'])->whereNumber('benchmarkSchedule')->name('update');
            Route::patch('{benchmarkSchedule}/toggle', [BenchmarkScheduleController::class, 'toggle'])->whereNumber('benchmarkSchedule')->name('toggle');
            Route::post('{benchmarkSchedule}/run', [BenchmarkScheduleController::class, 'run'])->whereNumber('benchmarkSchedule')->name('run');
            Route::delete('{benchmarkSchedule}', [BenchmarkScheduleController::class, 'destroy'])->whereNumber('benchmarkSchedule')->name('destroy');
        });

        Route::prefix('benchmarks')->name('benchmarks.runs.')->group(function (): void {
            Route::get('/', [BenchmarkRunController::class, 'index'])->name('index');
            Route::get('runs/create', [BenchmarkRunController::class, 'create'])->name('create');
            Route::post('runs/estimate', [BenchmarkRunController::class, 'estimate'])->name('estimate');
            Route::post('runs', [BenchmarkRunController::class, 'store'])->name('store');
            Route::get('runs/{benchmarkRun}', [BenchmarkRunController::class, 'show'])->whereNumber('benchmarkRun')->name('show');
            Route::post('runs/{benchmarkRun}/cancel', [BenchmarkRunController::class, 'cancel'])->whereNumber('benchmarkRun')->name('cancel');
            Route::post('runs/{benchmarkRun}/rejudge', [BenchmarkRunController::class, 'rejudge'])->whereNumber('benchmarkRun')->name('rejudge');
            Route::delete('runs/{benchmarkRun}', [BenchmarkRunController::class, 'destroy'])->whereNumber('benchmarkRun')->name('destroy');
        });

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
        Route::put('ai-prices/bulk', [AiModelPriceController::class, 'bulkUpdate'])->name('ai-prices.bulk-update');
        Route::delete('ai-prices/bulk', [AiModelPriceController::class, 'bulkDestroy'])->name('ai-prices.bulk-destroy');
        Route::put('ai-prices/{aiModelPrice}', [AiModelPriceController::class, 'update'])->name('ai-prices.update');
        Route::delete('ai-prices/{aiModelPrice}', [AiModelPriceController::class, 'destroy'])->name('ai-prices.destroy');
        Route::get('ai-prices/catalog/{provider}', [AiModelCatalogController::class, 'index'])
            ->whereIn('provider', array_values(array_unique(config('mediamanager.ai.pricing.providers', []))))
            ->name('ai-prices.catalog.index');
        Route::post('ai-prices/catalog', [AiModelCatalogController::class, 'store'])->name('ai-prices.catalog.store');

        Route::post('ai-free-usage-pools', [AiFreeUsagePoolController::class, 'store'])->name('ai-free-usage-pools.store');
        Route::put('ai-free-usage-pools/{aiFreeUsagePool}', [AiFreeUsagePoolController::class, 'update'])->name('ai-free-usage-pools.update');
        Route::delete('ai-free-usage-pools/{aiFreeUsagePool}', [AiFreeUsagePoolController::class, 'destroy'])->name('ai-free-usage-pools.destroy');
    });

    Route::get('webhook-log', [WebhookLogController::class, 'index'])->name('webhook-log.index');
    Route::put('webhook-log/settings', [WebhookLogController::class, 'updateSettings'])->name('webhook-log.update-settings');
    Route::get('webhook-log/{webhookEvent}', [WebhookLogController::class, 'show'])->name('webhook-log.show');

    Route::get('jobs', [JobsController::class, 'index'])->name('jobs.index');

    Route::get('statistics', StatisticsController::class)->name('statistics.index');

    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('servers/incidents', ServerIncidentController::class)->name('servers.incidents.index');
    Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
    Route::put('servers/watcher', ServerWatcherController::class)->name('servers.watcher.update');
    Route::get('servers/{server}', [ServerController::class, 'show'])->whereNumber('server')->name('servers.show');
    Route::post('servers/{server}/enrollment', [ServerController::class, 'regenerateEnrollment'])->whereNumber('server')->name('servers.enrollment.regenerate');
    Route::delete('servers/{server}', [ServerController::class, 'destroy'])->whereNumber('server')->name('servers.destroy');
    Route::post('servers/{server}/checks', [ServerCheckController::class, 'store'])->whereNumber('server')->name('servers.checks.store');
    Route::put('servers/{server}/checks/{serverCheck}', [ServerCheckController::class, 'update'])->whereNumber(['server', 'serverCheck'])->name('servers.checks.update');
    Route::patch('servers/{server}/checks/{serverCheck}/toggle', [ServerCheckController::class, 'toggle'])->whereNumber(['server', 'serverCheck'])->name('servers.checks.toggle');
    Route::delete('servers/{server}/checks/{serverCheck}', [ServerCheckController::class, 'destroy'])->whereNumber(['server', 'serverCheck'])->name('servers.checks.destroy');
});
