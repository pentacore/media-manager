<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Support\Str;

class MonitoringSettings
{
    public const string UPTIME_KUMA_PUSH_URL_KEY = 'monitoring.uptime_kuma_push_url';

    /** The push URL in use was saved through the admin form. */
    public const string SOURCE_SAVED = 'saved';

    /** The push URL in use comes from MEDIAMANAGER_UPTIME_KUMA_PUSH_URL; the form cannot remove it. */
    public const string SOURCE_ENVIRONMENT = 'environment';

    public function __construct(private readonly AppSettings $appSettings) {}

    /**
     * The outside watcher's push URL: the saved one, else the env default.
     * The token is part of the URL, and the settings layer stores it as plain
     * JSON (it has no secret support), so callers never expose it in full.
     */
    public function uptimeKumaPushUrl(): ?string
    {
        return $this->savedPushUrl() ?? $this->environmentPushUrl();
    }

    /**
     * Where the URL in use comes from (a SOURCE_* constant), or null when there is none.
     */
    public function pushUrlSource(): ?string
    {
        return match (true) {
            $this->savedPushUrl() !== null => self::SOURCE_SAVED,
            $this->environmentPushUrl() !== null => self::SOURCE_ENVIRONMENT,
            default => null,
        };
    }

    public function hasEnvironmentPushUrl(): bool
    {
        return $this->environmentPushUrl() !== null;
    }

    public function setUptimeKumaPushUrl(?string $url): void
    {
        $this->appSettings->set(self::UPTIME_KUMA_PUSH_URL_KEY, $url);
    }

    /**
     * What the admin form shows in place of the URL: its last four characters.
     */
    public function pushUrlHint(): ?string
    {
        $url = $this->uptimeKumaPushUrl();

        return $url === null ? null : '…'.Str::substr($url, -4);
    }

    /**
     * Read without a default, so the settings cache never holds the env value as if it had been saved.
     */
    private function savedPushUrl(): ?string
    {
        $url = $this->appSettings->get(self::UPTIME_KUMA_PUSH_URL_KEY);

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function environmentPushUrl(): ?string
    {
        $url = config('mediamanager.monitoring.uptime_kuma_push_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
