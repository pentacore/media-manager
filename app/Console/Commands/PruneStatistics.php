<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceMetric;
use App\Models\StatRollup;
use Illuminate\Console\Command;

class PruneStatistics extends Command
{
    protected $signature = 'statistics:prune';

    protected $description = 'Prune hour-granularity rollups and raw service metrics past retention';

    public function handle(): int
    {
        $hourCutoff = now()->subDays((int) config('mediamanager.statistics.hour_retention_days', 90));

        $pruned = StatRollup::query()
            ->where('period', 'hour')
            ->where('bucket', '<', $hourCutoff)
            ->delete();

        $this->info("Pruned {$pruned} hour rollups.");

        if (config('mediamanager.statistics.prune_service_metrics', true)) {
            $metricCutoff = now()->subDays((int) config('mediamanager.statistics.service_metrics_retention_days', 90));

            $prunedMetrics = ServiceMetric::query()->where('recorded_at', '<', $metricCutoff)->delete();
            $this->info("Pruned {$prunedMetrics} raw service metrics.");
        }

        return self::SUCCESS;
    }
}
