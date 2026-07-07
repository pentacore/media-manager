<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Statistics\StatisticsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Description('Replay historical events from durable tables into stat_rollups')]
#[Signature('statistics:backfill {--from=} {--to=}')]
class BackfillStatistics extends Command
{
    public function handle(StatisticsAggregator $statisticsAggregator): int
    {
        $from = $this->option('from') !== null
            ? CarbonImmutable::parse((string) $this->option('from'), 'UTC')->startOfDay()
            : $this->earliestEvent();
        $to = $this->option('to') !== null
            ? CarbonImmutable::parse((string) $this->option('to'), 'UTC')
            : CarbonImmutable::now('UTC');

        if ($from === null) {
            $this->info('No historical data found.');

            return self::SUCCESS;
        }

        // Day-at-a-time keeps each recompute window (and its memory) small
        // and makes an interrupted backfill resumable via --from.
        for ($cursor = $from; $cursor->lessThan($to); $cursor = $cursor->addDay()) {
            $statisticsAggregator->aggregate($cursor, $cursor->addDay()->min($to));
            $this->line('Backfilled '.$cursor->toDateString());
        }

        return self::SUCCESS;
    }

    private function earliestEvent(): ?CarbonImmutable
    {
        $candidates = array_filter([
            DB::table('emby_activities')->min('created_at'),
            DB::table('action_requests')->min('created_at'),
            DB::table('agent_decisions')->min('created_at'),
            DB::table('ai_usage_records')->min('created_at'),
            DB::table('service_metrics')->min('recorded_at'),
        ]);

        if ($candidates === []) {
            return null;
        }

        return CarbonImmutable::parse(min($candidates), 'UTC')->startOfDay();
    }
}
