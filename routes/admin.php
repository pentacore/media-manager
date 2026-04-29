<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AiModelPriceController;
use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\AiUsageController;
use App\Http\Controllers\Admin\EmbyLinkController;
use App\Http\Controllers\Admin\ProwlarrTestIndexerController;
use App\Http\Controllers\Admin\ServiceConnectionController;
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
    Route::post('connections/{serviceConnection}/prowlarr/test-indexer/{indexerId}', ProwlarrTestIndexerController::class)
        ->name('connections.prowlarr.test-indexer');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/link-emby', [EmbyLinkController::class, 'link'])->name('users.link-emby');
    Route::post('users/import-from-emby', [EmbyLinkController::class, 'import'])->name('users.import-from-emby');

    Route::get('ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
    Route::put('ai-settings', [AiSettingsController::class, 'update'])->name('ai-settings.update');

    Route::get('ai-usage', [AiUsageController::class, 'index'])->name('ai-usage.index');
    Route::get('ai-usage/export', [AiUsageController::class, 'export'])->name('ai-usage.export');

    Route::get('ai-prices', [AiModelPriceController::class, 'index'])->name('ai-prices.index');
    Route::post('ai-prices', [AiModelPriceController::class, 'store'])->name('ai-prices.store');
    Route::post('ai-prices/refresh', [AiModelPriceController::class, 'refresh'])->name('ai-prices.refresh');
    Route::put('ai-prices/{aiModelPrice}', [AiModelPriceController::class, 'update'])->name('ai-prices.update');
    Route::delete('ai-prices/{aiModelPrice}', [AiModelPriceController::class, 'destroy'])->name('ai-prices.destroy');

    Route::get('webhook-log', [WebhookLogController::class, 'index'])->name('webhook-log.index');
    Route::get('webhook-log/{webhookEvent}', [WebhookLogController::class, 'show'])->name('webhook-log.show');
});
