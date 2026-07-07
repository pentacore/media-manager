<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ActionRequestStatus;
use App\Enums\HealthStatus;
use App\Enums\TimeWindow;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use App\Services\Statistics\StatisticsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\ServiceProvider;
use Override;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Registers the MediaManager gauges exported on the token-gated /metrics
 * endpoint. Each gauge closure runs at scrape time, so reads must stay cheap
 * and side-effect free. Aggregate counts come from the pre-rolled
 * `stat_rollups` via {@see StatisticsRepository}; live gauges (queue depth,
 * active sessions, free disk) read the newest hour bucket per dimension set
 * so a scrape reflects the last collector pass rather than a summed total.
 */
class PrometheusServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerServiceGauges();
        $this->registerActivityGauges();
        $this->registerLatestSampleGauges();
    }

    /**
     * Service health, one series per active connection.
     */
    private function registerServiceGauges(): void
    {
        Prometheus::addGauge('mediamanager_service_up')
            ->helpText('1 when an active service connection is healthy, 0 otherwise')
            ->label('service')
            ->value(fn (): array => ServiceConnection::query()
                ->where('is_active', true)
                ->get()
                ->map(fn (ServiceConnection $serviceConnection): array => [
                    $serviceConnection->health_status === HealthStatus::Healthy ? 1 : 0,
                    [$serviceConnection->name],
                ])
                ->all());
    }

    /**
     * Rollup-backed activity totals for the current day.
     */
    private function registerActivityGauges(): void
    {
        Prometheus::addGauge('mediamanager_pending_actions')
            ->helpText('Number of ActionRequests awaiting approval')
            ->value(fn (): float => (float) ActionRequest::query()
                ->where('status', ActionRequestStatus::Pending)
                ->count());

        Prometheus::addGauge('mediamanager_webhooks_received_today')
            ->helpText('Webhooks received today, by service')
            ->label('service')
            ->value(fn (): array => collect($this->repository()->breakdown('webhooks.received', TimeWindow::Today, 'service'))
                ->map(fn (array $row): array => [(float) $row['count'], [$row['key']]])
                ->all());

        Prometheus::addGauge('mediamanager_ai_cost_usd_today')
            ->helpText('AI spend in USD accrued today')
            ->value(fn (): float => $this->repository()->total('ai.usage', TimeWindow::Today)['sum']);

        Prometheus::addGauge('mediamanager_watch_plays_today')
            ->helpText('Watch plays recorded today')
            ->value(fn (): float => (float) $this->repository()->total('watch.plays', TimeWindow::Today)['count']);

        Prometheus::addGauge('mediamanager_downloads_completed_today')
            ->helpText('Downloads completed today')
            ->value(fn (): float => (float) $this->repository()->total('downloads.completed', TimeWindow::Today)['count']);
    }

    /**
     * Live gauges sourced from the newest hour-bucket rollup per dimension set.
     */
    private function registerLatestSampleGauges(): void
    {
        Prometheus::addGauge('mediamanager_queue_depth')
            ->helpText('Latest sampled download-queue depth, by service')
            ->label('service')
            ->value(fn (): array => $this->latestSamples('queue.depth', ['service']));

        Prometheus::addGauge('mediamanager_sessions_active')
            ->helpText('Latest sampled active playback sessions, by connection')
            ->label('connection')
            ->value(fn (): array => $this->latestSamples('sessions.active', ['connection']));

        Prometheus::addGauge('mediamanager_disk_free_bytes')
            ->helpText('Latest sampled free disk space in bytes, by connection and path')
            ->label('connection')
            ->label('path')
            ->value(fn (): array => $this->latestSamples('service.disk_free_bytes', ['connection', 'path']));
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
}
