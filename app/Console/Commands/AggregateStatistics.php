<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Statistics\StatisticsAggregator;
use Illuminate\Console\Command;

class AggregateStatistics extends Command
{
    protected $signature = 'statistics:aggregate';

    protected $description = 'Roll up durable event tables into stat_rollups since the last watermark';

    public function handle(StatisticsAggregator $statisticsAggregator): int
    {
        $statisticsAggregator->aggregate();
        $this->info('Statistics aggregated.');

        return self::SUCCESS;
    }
}
