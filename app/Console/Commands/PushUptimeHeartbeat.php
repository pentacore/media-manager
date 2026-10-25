<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HostMonitoring\UptimeKumaPusher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Push a heartbeat to the outside Uptime Kuma monitor, when one is configured.')]
#[Signature('monitoring:push-heartbeat')]
class PushUptimeHeartbeat extends Command
{
    public function handle(UptimeKumaPusher $uptimeKumaPusher): int
    {
        $uptimeKumaPusher->push();

        return self::SUCCESS;
    }
}
