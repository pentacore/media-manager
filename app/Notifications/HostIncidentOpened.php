<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A server problem was detected. Warning incidents notify at warning, critical ones at error.
 */
class HostIncidentOpened extends HostIncidentNotification
{
    protected function severityKey(): string
    {
        return $this->severity->notificationSeverity()->value;
    }

    protected function pushTitle(): string
    {
        return sprintf('[%s] %s', $this->serverName, $this->title);
    }

    protected function body(): string
    {
        return $this->detail ?? $this->title;
    }
}
