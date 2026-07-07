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
            // Day-aligned so the boundary day keeps all of its raw samples —
            // a later backfill put()-overwrites day rollups from whatever
            // survives, and a mid-day cutoff would rewrite that day's
            // uptime/latency from a partial day.
            $metricCutoff = now()->subDays((int) config('mediamanager.statistics.service_metrics_retention_days', 90))->startOfDay();

            $prunedMetrics = ServiceMetric::query()->where('recorded_at', '<', $metricCutoff)->delete();
            $this->info(sprintf('Pruned %s raw service metrics.', $prunedMetrics));
        }

        return self::SUCCESS;
    }
}
