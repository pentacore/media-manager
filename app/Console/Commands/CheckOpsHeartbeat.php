<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\QueueLane;
use App\Support\OpsHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Exit non-zero when the scheduler or a queue lane has not reported a heartbeat recently (container healthcheck).')]
#[Signature('ops:check-heartbeat
                            {--scheduler : Check the scheduler heartbeat}
                            {--queue= : Comma-separated queue lanes to check, e.g. actions,webhooks,default}
                            {--max-age=180 : Oldest acceptable heartbeat, in seconds}')]
class CheckOpsHeartbeat extends Command
{
    public function handle(): int
    {
        $lanes = array_values(array_filter(array_map(trim(...), explode(',', (string) $this->option('queue')))));
        $unknownLanes = array_values(array_diff($lanes, QueueLane::values()));

        if ($unknownLanes !== []) {
            $this->error(sprintf('Unknown queue lane(s): %s', implode(', ', $unknownLanes)));

            return self::FAILURE;
        }

        $components = [
            ...($this->option('scheduler') ? [OpsHeartbeat::SCHEDULER] : []),
            ...array_map(static fn (string $lane): string => OpsHeartbeat::forLane(QueueLane::from($lane)), $lanes),
        ];

        if ($components === []) {
            $this->error('Pass --scheduler and/or --queue=<lanes>.');

            return self::FAILURE;
        }

        $stale = OpsHeartbeat::stale($components, max(1, (int) $this->option('max-age')));

        foreach ($stale as $component => $age) {
            $this->error($age === null
                ? sprintf('%s: no heartbeat recorded', $component)
                : sprintf('%s: last heartbeat %ds ago', $component, $age));
        }

        return $stale === [] ? self::SUCCESS : self::FAILURE;
    }
}
