<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\WebhookEvent;

interface WebhookHandler
{
    public function handle(WebhookEvent $webhookEvent): void;
}
