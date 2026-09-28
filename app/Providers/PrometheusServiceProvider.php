<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ActionRequestStatus;
use App\Enums\HealthStatus;
use App\Enums\QueueLane;
use App\Enums\TimeWindow;
use App\Enums\WebhookHandlingStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use App\Models\WebhookEvent;
use App\Services\Statistics\StatisticsRepository;
use App\Support\OpsHeartbeat;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Override;
use Spatie\Prometheus\Facades\Prometheus;
use Throwable;

/**
 * Registers the MediaManager gauges exported on the token-gated /metrics
 * endpoint. Each gauge closure runs at scrape time, so reads must stay cheap
 * and side-effect free, and every closure is wrapped in {@see self::safely()}
 * so one gauge's dependency failure (DB, Redis) degrades that gauge alone
 * instead of 500ing the whole scrape. Aggregate counts come from the
 * pre-rolled `stat_rollups` via {@see StatisticsRepository}; live gauges
 * (queue depth, active sessions, free disk) read the newest hour bucket per
 * dimension set so a scrape reflects the last collector pass rather than a
 * summed total.
 */
class PrometheusServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerServiceGauges();
        $this->registerActivityGauges();
        $this->registerLatestSampleGauges();
        $this->registerQueueGauges();
        $this->registerHeartbeatGauges();
        $this->registerWebhookLagGauge();
    }

    /**
     * Service health, one series per active connection.
     */
    private function registerServiceGauges(): void
    {
        Prometheus::addGauge('mediamanager_service_up')
            ->helpText('1 when an active service connection is healthy, 0 otherwise')
            ->label('service')
            ->value(fn (): array|float => $this->safely('mediamanager_service_up', fn (): array => ServiceConnection::query()
                ->where('is_active', true)
                ->get()
                ->map(fn (ServiceConnection $serviceConnection): array => [
                    $serviceConnection->health_status === HealthStatus::Healthy ? 1 : 0,
                    [$serviceConnection->name],
                ])
                ->all()));
    }

    /**
     * Rollup-backed activity totals for the current day.
     */
    private function registerActivityGauges(): void
    {
        Prometheus::addGauge('mediamanager_pending_actions')
            ->helpText('Number of ActionRequests awaiting approval')
            ->value(fn (): array|float => $this->safely('mediamanager_pending_actions', fn (): float => (float) ActionRequest::query()
                ->where('status', ActionRequestStatus::Pending)
                ->count()));

        Prometheus::addGauge('mediamanager_webhooks_received_today')
            ->helpText('Webhooks received today, by service')
            ->label('service')
            ->value(fn (): array|float => $this->safely('mediamanager_webhooks_received_today', fn (): array => collect($this->repository()->breakdown('webhooks.received', TimeWindow::Today, 'service'))
                ->map(fn (array $row): array => [(float) $row['count'], [$row['key']]])
                ->all()));

        Prometheus::addGauge('mediamanager_ai_cost_usd_today')
            ->helpText('AI spend in USD accrued today')
            ->value(fn (): array|float => $this->safely('mediamanager_ai_cost_usd_today', fn (): float => $this->repository()->total('ai.usage', TimeWindow::Today)['sum']));

        Prometheus::addGauge('mediamanager_watch_plays_today')
            ->helpText('Watch plays recorded today')
            ->value(fn (): array|float => $this->safely('mediamanager_watch_plays_today', fn (): float => (float) $this->repository()->total('watch.plays', TimeWindow::Today)['count']));

        Prometheus::addGauge('mediamanager_downloads_completed_today')
            ->helpText('Downloads completed today')
            ->value(fn (): array|float => $this->safely('mediamanager_downloads_completed_today', fn (): float => (float) $this->repository()->total('downloads.completed', TimeWindow::Today)['count']));
    }

    /**
     * Live gauges sourced from the newest hour-bucket rollup per dimension set.
     */
    private function registerLatestSampleGauges(): void
    {
        Prometheus::addGauge('mediamanager_queue_depth')
            ->helpText('Latest sampled download-queue depth, by service')
            ->label('service')
            ->value(fn (): array|float => $this->safely('mediamanager_queue_depth', fn (): array => $this->latestSamples('queue.depth', ['service'])));

        Prometheus::addGauge('mediamanager_sessions_active')
            ->helpText('Latest sampled active playback sessions, by connection')
            ->label('connection')
            ->value(fn (): array|float => $this->safely('mediamanager_sessions_active', fn (): array => $this->latestSamples('sessions.active', ['connection'])));

        Prometheus::addGauge('mediamanager_disk_free_bytes')
            ->helpText('Latest sampled free disk space in bytes, by connection and path')
            ->label('connection')
            ->label('path')
            ->value(fn (): array|float => $this->safely('mediamanager_disk_free_bytes', fn (): array => $this->latestSamples('service.disk_free_bytes', ['connection', 'path'])));
    }

    /**
     * Newest hour-bucket sample per distinct dimension set for a gauge metric,
     * shaped as `[[value, [labelValue, ...]], ...]` for the Prometheus gauge.
     * Labels are pulled from the row's JSON dimensions in the given order.
     *
     * The collector runs several times per hour and {@see StatsRecorder::sample}
     * accumulates each reading into the hour bucket's sum/count, so the mean
     * (`sum / count`) is the representative gauge value for that window rather
     * than the summed total.
     *
     * The two-hour bucket floor keeps the scrape query bounded (instead of
     * hydrating the full hour retention) and lets series for dead or deleted
     * connections go absent — a frozen last value would read as healthy.
     *
     * @param  list<string>  $labelKeys
     * @return list<array{0: float, 1: list<string>}>
     */
    private function latestSamples(string $metric, array $labelKeys): array
    {
        return StatRollup::query()
            ->where('metric', $metric)
            ->where('period', 'hour')
            ->where('bucket', '>=', CarbonImmutable::now('UTC')->subHours(2))
            ->orderByDesc('bucket')
            ->get()
            ->groupBy(fn (StatRollup $statRollup): string => (string) json_encode($statRollup->dimensions))
            ->map(fn ($group): StatRollup => $group->first())
            ->map(fn (StatRollup $statRollup): array => [
                $statRollup->count > 0 ? (float) ($statRollup->sum ?? 0) / $statRollup->count : 0.0,
                array_map(
                    fn (string $key): string => (string) ($statRollup->dimensions[$key] ?? ''),
                    $labelKeys,
                ),
            ])
            ->values()
            ->all();
    }

    private function repository(): StatisticsRepository
    {
        return $this->app->make(StatisticsRepository::class);
    }

    /**
     * Failed jobs and per-lane backlog on the default queue connection. Every
     * known lane is always exported (0 when empty) so alerts have a series
     * to read; a queue name outside `QueueLane` (manual dispatch, a removed
     * lane, ...) is folded into `queue="other"` instead of minting its own
     * label value, keeping cardinality bounded.
     */
    private function registerQueueGauges(): void
    {
        Prometheus::addGauge('mediamanager_failed_jobs')
            ->helpText('Failed queue jobs not yet retried or pruned, by queue (unknown queues roll up into "other")')
            ->label('queue')
            ->value(fn (): array|float => $this->safely('mediamanager_failed_jobs', function (): array {
                $counts = DB::table((string) config('queue.failed.table', 'failed_jobs'))
                    ->select('queue', DB::raw('count(*) as aggregate'))
                    ->groupBy('queue')
                    ->pluck('aggregate', 'queue')
                    ->map(fn (mixed $count): int => (int) $count)
                    ->all();

                $knownLanes = QueueLane::values();
                $lanes = array_fill_keys($knownLanes, 0);
                $lanes['other'] = 0;

                foreach ($counts as $queue => $count) {
                    $lanes[in_array($queue, $knownLanes, true) ? $queue : 'other'] += $count;
                }

                return collect($lanes)
                    ->map(fn (int $count, string $queue): array => [(float) $count, [$queue]])
                    ->values()
                    ->all();
            }));

        Prometheus::addGauge('mediamanager_job_queue_size')
            ->helpText('Jobs on each queue lane of the default queue connection, by state')
            ->label('queue')
            ->label('state')
            ->value(fn (): array|float => $this->safely('mediamanager_job_queue_size', function (): array {
                $queue = Queue::connection();

                return collect(QueueLane::cases())
                    ->flatMap(fn (QueueLane $queueLane): array => [
                        [(float) $queue->pendingSize($queueLane->value), [$queueLane->value, 'pending']],
                        [(float) $queue->delayedSize($queueLane->value), [$queueLane->value, 'delayed']],
                        [(float) $queue->reservedSize($queueLane->value), [$queueLane->value, 'reserved']],
                    ])
                    ->values()
                    ->all();
            }));
    }

    /**
     * Seconds since the scheduler and each queue lane last reported. A
     * component that never reported has no series (alert with absent()).
     */
    private function registerHeartbeatGauges(): void
    {
        Prometheus::addGauge('mediamanager_heartbeat_age_seconds')
            ->helpText('Seconds since the scheduler or a queue lane last recorded a heartbeat')
            ->label('component')
            ->value(fn (): array|float => $this->safely('mediamanager_heartbeat_age_seconds', fn (): array => collect(OpsHeartbeat::components())
                ->map(fn (string $component): array => [OpsHeartbeat::ageInSeconds($component), $component])
                ->reject(fn (array $sample): bool => $sample[0] === null)
                ->map(fn (array $sample): array => [(float) $sample[0], [$sample[1]]])
                ->values()
                ->all()));
    }

    /**
     * Age of the oldest webhook not yet handled — rises when the webhooks
     * lane stalls even if its worker is alive. Backed by a partial index on
     * (created_at) WHERE processed_at IS NULL (see the
     * add_pending_lookup_index_to_webhook_events_table migration) so the
     * scrape query stays cheap as the table grows.
     */
    private function registerWebhookLagGauge(): void
    {
        Prometheus::addGauge('mediamanager_webhook_oldest_pending_age_seconds')
            ->helpText('Age in seconds of the oldest webhook event still waiting to be processed (0 when none)')
            ->value(fn (): array|float => $this->safely('mediamanager_webhook_oldest_pending_age_seconds', function (): float {
                $oldest = WebhookEvent::query()
                    ->whereNull('processed_at')
                    ->where(fn (Builder $builder): Builder => $builder
                        ->whereNull('handling_status')
                        ->orWhere('handling_status', WebhookHandlingStatus::Processing))
                    ->min('created_at');

                return $oldest === null
                    ? 0.0
                    : (float) max(0, now()->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp());
            }));
    }

    /**
     * Runs a gauge's value collection, isolating a dependency failure
     * (Redis, cache, DB) to that gauge alone instead of 500ing the whole
     * /metrics scrape — spatie/laravel-prometheus has no error handling of
     * its own around these closures. On failure the gauge exports no series
     * for this scrape (an empty array is a no-op for both scalar and
     * labelled gauges, see Gauge::handleValueAndLabels()).
     */
    private function safely(string $gauge, Closure $callback): array|float
    {
        try {
            return $callback();
        } catch (Throwable $throwable) {
            Log::warning(sprintf('Prometheus gauge [%s] failed to collect', $gauge), [
                'exception' => $throwable,
            ]);

            return [];
        }
    }
}
