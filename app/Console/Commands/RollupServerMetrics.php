<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HostMonitoring\ServerMetricRollup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Description('Roll server metric samples up into the five-minute (300) or hourly (3600) tier.')]
#[Signature('servers:rollup-metrics {--resolution=300 : Target resolution in seconds (300 or 3600)}')]
class RollupServerMetrics extends Command
{
    public function handle(ServerMetricRollup $serverMetricRollup): int
    {
        try {
            $rows = $serverMetricRollup->rollup((int) $this->option('resolution'));
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%d rollup row(s) written.', $rows));

        return self::SUCCESS;
    }
}
