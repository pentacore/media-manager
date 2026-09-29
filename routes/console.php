<?php

declare(strict_types=1);

use App\Console\Commands\AggregateStatistics;
use App\Console\Commands\Ai\PruneConversations;
use App\Console\Commands\Ai\RefreshAiPrices;
use App\Console\Commands\BroadcastDashboardStats;
use App\Console\Commands\CheckAppVersion;
use App\Console\Commands\CheckServiceHealth;
use App\Console\Commands\CheckServiceVersions;
use App\Console\Commands\CollectServiceGauges;
use App\Console\Commands\PollSabnzbdHistory;
use App\Console\Commands\PruneAiProposedWorkflows;
use App\Console\Commands\PruneStatistics;
use App\Console\Commands\ReconcileBazarrSubtitles;
use App\Console\Commands\ReconcileMediaReplacementAttempts;
use App\Console\Commands\ReconcileStuckActionRequests;
use App\Console\Commands\RecordOpsHeartbeat;
use App\Console\Commands\RefreshInterventionCount;
use App\Console\Commands\RefreshSabnzbdDownloadCounts;
use App\Console\Commands\RefreshWantedCount;
use App\Console\Commands\WarmServiceCaches;
use App\Jobs\PruneSubtitleUploads;
use App\Jobs\ReconcileSearchIndex;
use App\Jobs\SyncAnimeMappingJob;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\AgentDecision;
use App\Models\AiPriceRefreshRun;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\EmbyActivity;
use App\Models\MediaReplacementAttempt;
use App\Models\SubtitleCase;
use App\Models\WebhookEvent;
use Illuminate\Queue\Console\PruneBatchesCommand;
use Illuminate\Queue\Console\PruneFailedJobsCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

// Liveness for the scheduler and every queue lane: the container
// healthchecks (ops:check-heartbeat) and /metrics read these ages.
Schedule::command(RecordOpsHeartbeat::class)
    ->everyMinute()
    ->withoutOverlapping(5);

// Every overlap lock carries an explicit expiry (minutes) sized to the task:
// the 1440-minute default would let one crashed run silently skip a
// sub-daily task for a day. The scheduler container also clears stale locks
// at boot (docker/production/entrypoint.sh). ScheduleOverlapExpiryTest
// enforces 5 <= expiry <= max(10, cadence - 5).
Schedule::command(CheckServiceHealth::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(CheckServiceVersions::class)
    ->daily()
    ->withoutOverlapping(60);

Schedule::command(CheckAppVersion::class)
    ->daily()
    ->withoutOverlapping(30);

Schedule::command(BroadcastDashboardStats::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(PruneAiProposedWorkflows::class)
    ->daily()
    ->withoutOverlapping(60);

Schedule::command(ReconcileMediaReplacementAttempts::class)
    ->hourly()
    ->withoutOverlapping(50);

Schedule::command(ReconcileStuckActionRequests::class)
    ->hourly()
    ->withoutOverlapping(50);

Schedule::command(ReconcileBazarrSubtitles::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// The weekly run refreshes the whole catalog from the Models.dev feed with
// verifier fallback; the monthly --verify run re-checks the core providers
// against their first-party pricing pages. The distinct arguments give Laravel
// distinct mutex names, so the two never share an overlap lock.
Schedule::command(RefreshAiPrices::class, ['--scheduled'])
    ->weekly()
    ->withoutOverlapping(120);

Schedule::command(RefreshAiPrices::class, ['--verify', '--scheduled'])
    ->monthly()
    ->withoutOverlapping(120);

Schedule::command(PollSabnzbdHistory::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(RefreshInterventionCount::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(RefreshSabnzbdDownloadCounts::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(RefreshWantedCount::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(WarmServiceCaches::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::job(new ReconcileSearchIndex)
    ->dailyAt('03:30')
    ->withoutOverlapping(10);

Schedule::job(new PruneSubtitleUploads)
    ->hourly()
    ->withoutOverlapping(10);

Schedule::job(new SyncAnimeMappingJob)
    ->weekly()
    ->withoutOverlapping(10);

Schedule::command(AggregateStatistics::class)
    ->hourlyAt(5)
    ->withoutOverlapping(50);

Schedule::command(PruneStatistics::class)
    ->dailyAt('04:30')
    ->withoutOverlapping(180);

// The two invocations carry distinct arguments, so Laravel derives distinct
// scheduling mutex names for them — the five-minute gauge sweep and the daily
// library/indexer snapshot never share a lock.
Schedule::command(CollectServiceGauges::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command(CollectServiceGauges::class, ['--library'])
    ->dailyAt('04:00')
    ->withoutOverlapping(60);

// Retention for the fastest-growing tables (config: mediamanager.retention;
// 0 disables a table).
Schedule::command('model:prune', [
    '--model' => [
        WebhookEvent::class,
        ActivityLog::class,
        EmbyActivity::class,
        AiUsageRecord::class,
        AiToolInvocation::class,
        AgentDecision::class,
        AiPriceRefreshRun::class,
        MediaReplacementAttempt::class,
        // Cases (with their attempts and uploads) before action requests: a
        // case pruned tonight frees the action requests it pointed at.
        SubtitleCase::class,
        ActionRequest::class,
    ],
])
    ->dailyAt('03:00')
    ->withoutOverlapping(180);

// Opt-in conversation retention (0 = keep) plus the orphaned chat
// attachment sweep, which always runs.
Schedule::command(PruneConversations::class)
    ->dailyAt('03:15')
    ->withoutOverlapping(60);

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
    ->withoutOverlapping(60);

// Queue bookkeeping: failed jobs and finished/cancelled/abandoned batches
// (the five-minute health check creates a batch every run).
$failedJobsDays = (int) config('mediamanager.retention.failed_jobs_days');

if ($failedJobsDays > 0) {
    Schedule::command(PruneFailedJobsCommand::class, ['--hours' => $failedJobsDays * 24])
        ->dailyAt('03:10')
        ->withoutOverlapping(30);
}

$jobBatchesHours = (int) config('mediamanager.retention.job_batches_days') * 24;

if ($jobBatchesHours > 0) {
    Schedule::command(PruneBatchesCommand::class, [
        '--hours' => $jobBatchesHours,
        '--unfinished' => $jobBatchesHours,
        '--cancelled' => $jobBatchesHours,
    ])
        ->dailyAt('03:20')
        ->withoutOverlapping(30);
}

// Telescope is a require-dev package: the class exists on dev machines (where
// telescope_entries otherwise grows unboundedly) and is absent from the
// production image, where scheduling the command would fail every night.
if (class_exists(Telescope::class)) {
    Schedule::command('telescope:prune', ['--hours' => 48])
        ->daily()
        ->withoutOverlapping(60);
}
