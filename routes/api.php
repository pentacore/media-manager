<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Http\Controllers\Agent\EnrollController;
use App\Http\Controllers\Agent\ReportController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyWebhookToken;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/{service}/{connection}', [WebhookController::class, 'handle'])
    ->whereIn('service', ServiceType::genericWebhookValues())
    ->whereNumber('connection')
    ->middleware(['throttle:webhooks', 'webhook.payload-limit', VerifyWebhookToken::class])
    ->name('webhooks.handle');

Route::prefix('agent/v1')->name('agent.')
    ->middleware('webhook.payload-limit:mediamanager.monitoring.max_payload_kb')
    ->group(function (): void {
        Route::post('enroll', EnrollController::class)->middleware('throttle:agent')->name('enroll');
        Route::post('report', ReportController::class)->middleware(['agent.token', 'throttle:agent'])->name('report');
    });
