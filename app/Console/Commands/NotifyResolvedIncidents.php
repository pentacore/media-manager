<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HostMonitoring\IncidentTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Send the held "resolved" push for every server incident that stayed resolved for the notify delay.')]
#[Signature('servers:notify-resolved')]
class NotifyResolvedIncidents extends Command
{
    public function handle(IncidentTracker $incidentTracker): int
    {
        $this->info(sprintf('%d resolved incident push(es) sent.', $incidentTracker->notifyHeldResolutions()));

        return self::SUCCESS;
    }
}
