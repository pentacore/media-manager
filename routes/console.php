<?php

declare(strict_types=1);

use App\Console\Commands\AggregateStatistics;
use App\Console\Commands\Ai\RefreshAiPrices;
use App\Console\Commands\BroadcastDashboardStats;
use App\Console\Commands\CheckAppVersion;
use App\Console\Commands\CheckServiceHealth;
use App\Console\Commands\CheckServiceVersions;
use App\Console\Commands\CollectServiceGauges;
use App\Console\Commands\PollSabnzbdHistory;
use App\Console\Commands\PruneAiProposedWorkflows;
use App\Console\Commands\PruneStatistics;
use App\Console\Commands\ReconcileMediaReplacementAttempts;
use App\Console\Commands\ReconcileStuckActionRequests;
use App\Console\Commands\RefreshInterventionCount;
use App\Console\Commands\RefreshSabnzbdDownloadCounts;
use App\Console\Commands\WarmServiceCaches;
use App\Jobs\ReconcileSearchIndex;
use App\Jobs\SyncAnimeMappingJob;
use App\Models\ActivityLog;
use App\Models\AgentDecision;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\EmbyActivity;
use App\Models\MediaReplacementAttempt;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

Schedule::command(CheckServiceHealth::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(CheckServiceVersions::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(CheckAppVersion::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(BroadcastDashboardStats::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(PruneAiProposedWorkflows::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(ReconcileMediaReplacementAttempts::class)
    ->hourly()
    ->withoutOverlapping();

Schedule::command(ReconcileStuckActionRequests::class)
    ->hourly()
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

Schedule::job(new SyncAnimeMappingJob)
    ->weekly()
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

// Retention for the fastest-growing tables (config: mediamanager.retention;
// 0 disables a table). Without this, webhook payloads, activity rows, AI
// usage records, and notifications grow without bound.
Schedule::command('model:prune', [
    '--model' => [
        WebhookEvent::class,
        ActivityLog::class,
        EmbyActivity::class,
        AiUsageRecord::class,
        AiToolInvocation::class,
        AgentDecision::class,
        MediaReplacementAttempt::class,
    ],
])
    ->dailyAt('03:00')
    ->withoutOverlapping();

// laravel's DatabaseNotification isn't ours to make Prunable; trim directly.
Schedule::call(function (): void {
    $days = (int) config('mediamanager.retention.notifications_days');

    if ($days > 0) {
        DB::table('notifications')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
})
    ->name('prune-notifications')
    ->daily()
    ->withoutOverlapping();

// Telescope is a require-dev package: the class exists on dev machines (where
// telescope_entries otherwise grows unboundedly) and is absent from the
// production image, where scheduling the command would fail every night.
if (class_exists(Telescope::class)) {
    Schedule::command('telescope:prune', ['--hours' => 48])
        ->daily()
        ->withoutOverlapping();
}
