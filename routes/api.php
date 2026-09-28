<?php

declare(strict_types=1);

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyWebhookToken;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/{service}/{connection}', [WebhookController::class, 'handle'])
    ->middleware(['throttle:webhooks', 'webhook.payload-limit', VerifyWebhookToken::class])
    ->name('webhooks.handle');
