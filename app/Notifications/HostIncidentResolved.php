<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;

/**
 * A server incident cleared on its own. Sent at warning severity, not info, so a destination filtered at
 * warning and above still gets the all-clear for the problem it was told about. The push is held until the
 * incident stayed resolved for a while (IncidentTracker), so the duration runs to the resolve, not to the send.
 */
class HostIncidentResolved extends HostIncidentNotification
{
    protected function severityKey(): string
    {
        return 'warning';
    }

    protected function pushTitle(): string
    {
        return sprintf('[%s] Resolved: %s', $this->serverName, $this->title);
    }

    protected function body(): string
    {
        return sprintf('Resolved after %s.', $this->openedAt->diffForHumans($this->resolvedAt ?? now(), CarbonInterface::DIFF_ABSOLUTE, short: false, parts: 2));
    }
}
