<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Models\WebhookEvent;
use App\Services\Webhook\AbstractWebhookHandler;

class WhisparrWebhookHandler extends AbstractWebhookHandler
{
    protected function serviceSlug(): string
    {
        return 'whisparr';
    }

    public function handle(WebhookEvent $webhookEvent): void
    {
        $webhookEvent->markProcessed();

        if ($webhookEvent->serviceConnection !== null) {
            new WhisparrCache($webhookEvent->serviceConnection)->bustAll();
        }
    }
}
