<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceMetric;
use App\Models\StatRollup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Prune hour-granularity rollups and raw service metrics past retention')]
#[Signature('statistics:prune')]
class PruneStatistics extends Command
{
    public function handle(): int
    {
        $hourCutoff = now()->subDays((int) config('mediamanager.statistics.hour_retention_days', 90));

        $pruned = StatRollup::query()
            ->where('period', 'hour')
            ->where('bucket', '<', $hourCutoff)
            ->delete();

        $this->info(sprintf('Pruned %s hour rollups.', $pruned));

        if (config('mediamanager.statistics.prune_service_metrics', true)) {
            $metricCutoff = now()->subDays((int) config('mediamanager.statistics.service_metrics_retention_days', 90));

            $prunedMetrics = ServiceMetric::query()->where('recorded_at', '<', $metricCutoff)->delete();
            $this->info(sprintf('Pruned %s raw service metrics.', $prunedMetrics));
        }

        return self::SUCCESS;
    }
}
