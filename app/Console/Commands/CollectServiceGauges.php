<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Statistics\ServiceGaugeCollector;
use Illuminate\Console\Command;

class CollectServiceGauges extends Command
{
    protected $signature = 'statistics:collect-gauges {--library : Also run the daily library + indexer-stats snapshot}';

    protected $description = 'Poll live service state into gauge rollups; with --library also snapshot library and indexer stats';

    public function handle(ServiceGaugeCollector $serviceGaugeCollector): int
    {
        if ($this->option('library')) {
            $serviceGaugeCollector->snapshotLibrary();
            $this->info('Library snapshot recorded.');
        } else {
            $serviceGaugeCollector->collect();
            $this->info('Service gauges collected.');
        }

        return self::SUCCESS;
    }
}
