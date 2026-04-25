<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\ActivityLog;
use App\Models\WebhookEvent;

abstract class AbstractWebhookHandler implements WebhookHandler
{
    /**
     * The wire-name segment used in ActivityLog action strings (e.g. "sonarr",
     * "radarr", "emby", "seerr"). Concrete handlers return their own slug.
     */
    abstract protected function serviceSlug(): string;

    /**
     * Write a typed ActivityLog entry. The action is auto-prefixed with
     * "webhook.{serviceSlug}." so handlers only pass the suffix.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function logActivity(
        WebhookEvent $webhookEvent,
        string $action,
        string $description,
        array $metadata = [],
        int|string|null $subjectId = null,
        ?string $subjectType = null,
    ): void {
        ActivityLog::create([
            'user_id' => null,
            'service_connection_id' => $webhookEvent->service_connection_id,
            'action' => sprintf('webhook.%s.%s', $this->serviceSlug(), $action),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
