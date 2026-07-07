<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Statistics\StatisticsAggregator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Roll up durable event tables into stat_rollups since the last watermark')]
#[Signature('statistics:aggregate')]
class AggregateStatistics extends Command
{
    public function handle(StatisticsAggregator $statisticsAggregator): int
    {
        $statisticsAggregator->aggregate();
        $this->info('Statistics aggregated.');

        return self::SUCCESS;
    }
}
