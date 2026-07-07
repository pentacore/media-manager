<?php

declare(strict_types=1);

use App\Console\Commands\AggregateStatistics;
use App\Console\Commands\Ai\RefreshAiPrices;
use App\Console\Commands\BroadcastDashboardStats;
use App\Console\Commands\CheckServiceHealth;
use App\Console\Commands\CheckServiceVersions;
use App\Console\Commands\CollectServiceGauges;
use App\Console\Commands\PollSabnzbdHistory;
use App\Console\Commands\PruneAiProposedWorkflows;
use App\Console\Commands\PruneStatistics;
use App\Console\Commands\RefreshInterventionCount;
use App\Console\Commands\RefreshSabnzbdDownloadCounts;
use App\Console\Commands\WarmServiceCaches;
use App\Jobs\ReconcileSearchIndex;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CheckServiceHealth::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(CheckServiceVersions::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(BroadcastDashboardStats::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(PruneAiProposedWorkflows::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(RefreshAiPrices::class)
    ->weekly()
    ->withoutOverlapping();

Schedule::command(PollSabnzbdHistory::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(RefreshInterventionCount::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(RefreshSabnzbdDownloadCounts::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(WarmServiceCaches::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::job(new ReconcileSearchIndex)
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::command(AggregateStatistics::class)
    ->hourlyAt(5)
    ->withoutOverlapping();

Schedule::command(PruneStatistics::class)
    ->dailyAt('04:30')
    ->withoutOverlapping();

// The two invocations carry distinct arguments, so Laravel derives distinct
// scheduling mutex names for them — the five-minute gauge sweep and the daily
// library/indexer snapshot never share a lock.
Schedule::command(CollectServiceGauges::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(CollectServiceGauges::class, ['--library'])
    ->dailyAt('04:00')
    ->withoutOverlapping();
