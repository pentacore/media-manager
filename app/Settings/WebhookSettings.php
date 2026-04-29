<?php

declare(strict_types=1);

namespace App\Settings;

class WebhookSettings
{
    public const CAPTURE_ENABLED_KEY = 'webhooks.capture_enabled';

    public function __construct(private readonly AppSettings $appSettings) {}

    /**
     * When false, incoming webhooks are still received and processed
     * (so handlers can update local state) but their payloads are
     * removed from the webhook_events table once processing finishes,
     * preventing the log from filling up the database.
     */
    public function captureEnabled(): bool
    {
        $value = $this->appSettings->get(
            self::CAPTURE_ENABLED_KEY,
            config('mediamanager.webhooks.capture_enabled', true),
        );

        return (bool) $value;
    }

    public function setCaptureEnabled(bool $enabled): void
    {
        $this->appSettings->set(self::CAPTURE_ENABLED_KEY, $enabled);
    }
}
