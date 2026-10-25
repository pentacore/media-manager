<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * An open server incident became critical. Always loud: it is sent at error severity.
 */
class HostIncidentEscalated extends HostIncidentNotification
{
    protected function severityKey(): string
    {
        return 'error';
    }

    protected function pushTitle(): string
    {
        return sprintf('[%s] Now critical: %s', $this->serverName, $this->title);
    }

    protected function body(): string
    {
        return $this->detail ?? $this->title;
    }
}
