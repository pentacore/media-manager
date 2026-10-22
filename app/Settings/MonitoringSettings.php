<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Support\Str;

class MonitoringSettings
{
    public const string UPTIME_KUMA_PUSH_URL_KEY = 'monitoring.uptime_kuma_push_url';

    public function __construct(private readonly AppSettings $appSettings) {}

    /**
     * The outside watcher's push URL: the saved one, else the env default.
     * The token is part of the URL, and the settings layer stores it as plain
     * JSON (it has no secret support), so callers never expose it in full.
     */
    public function uptimeKumaPushUrl(): ?string
    {
        $url = $this->appSettings->get(
            self::UPTIME_KUMA_PUSH_URL_KEY,
            config('mediamanager.monitoring.uptime_kuma_push_url'),
        );

        return is_string($url) && $url !== '' ? $url : null;
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
}
