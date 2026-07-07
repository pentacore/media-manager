<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\ServiceClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls live service state (disk space, queue depth, sessions, pending
 * requests, indexer stats) into gauge rollups, plus a daily library
 * snapshot. Each connection is sampled inside its own try/catch so one
 * unreachable service never stops the sweep — a gap reads as "unreachable",
 * a zero would lie. Stateless — Octane-safe.
 */
class ServiceGaugeCollector
{
    public function __construct(
        private readonly ServiceClientFactory $serviceClientFactory,
        private readonly StatsRecorder $statsRecorder,
    ) {}

    /**
     * Five-minute pass: live gauges for every active connection.
     */
    public function collect(): void
    {
        $at = CarbonImmutable::now('UTC');

        ServiceConnection::query()->where('is_active', true)->get()
            ->each(function (ServiceConnection $serviceConnection) use ($at): void {
                try {
                    $this->collectFor($serviceConnection, $at);
                } catch (Throwable $throwable) {
                    Log::info('Gauge collection skipped', [
                        'connection' => $serviceConnection->id,
                        'reason' => $throwable->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Daily pass: library counts + Prowlarr indexer stats, written directly
     * into today's day bucket with put() so a re-run is idempotent.
     */
    public function snapshotLibrary(): void
    {
        $bucket = CarbonImmutable::now('UTC')->startOfDay();

        $this->statsRecorder->put('library.movies', 'day', $bucket, [], DB::table('indexed_movies')->count());
        $this->statsRecorder->put('library.with_file', 'day', $bucket, [], DB::table('indexed_movies')->where('has_file', true)->count());
        $this->statsRecorder->put('library.series', 'day', $bucket, [], DB::table('indexed_series')->count());
        $this->statsRecorder->put('library.monitored', 'day', $bucket, ['kind' => 'movie'], DB::table('indexed_movies')->where('monitored', true)->count());
        $this->statsRecorder->put('library.monitored', 'day', $bucket, ['kind' => 'series'], DB::table('indexed_series')->where('monitored', true)->count());

        ServiceConnection::query()
            ->where('is_active', true)
            ->where('type', ServiceType::Prowlarr)
            ->get()
            ->each(function (ServiceConnection $serviceConnection) use ($bucket): void {
                try {
                    $this->snapshotIndexerStats($serviceConnection, $bucket);
                } catch (Throwable $throwable) {
                    Log::info('Indexer stats snapshot skipped', [
                        'connection' => $serviceConnection->id,
                        'reason' => $throwable->getMessage(),
                    ]);
                }
            });
    }

    private function collectFor(ServiceConnection $serviceConnection, CarbonImmutable $at): void
    {
        $client = $this->serviceClientFactory->make($serviceConnection);
        $dims = ['connection' => (string) $serviceConnection->id];

        match ($serviceConnection->type) {
            ServiceType::Emby => $this->statsRecorder->sample(
                'sessions.active',
                $dims,
                $at,
                (float) collect($client->getActiveSessions())->filter(fn (array $session): bool => isset($session['NowPlayingItem']))->count(),
            ),
            ServiceType::Seerr => $this->statsRecorder->sample(
                'requests.pending_gauge',
                $dims,
                $at,
                (float) ($client->getRequestCount()['pending'] ?? 0),
            ),
            ServiceType::SABnzbd => $this->collectSabnzbd($client, $serviceConnection, $at),
            ServiceType::Prowlarr => $this->collectDiskSpace($client->getDiskSpace(), $serviceConnection, $at),
            default => $this->collectArr($client, $serviceConnection, $at), // Sonarr / Radarr / Whisparr
        };
    }

    /**
     * Sonarr / Radarr / Whisparr: disk space + queue depth from totalRecords.
     */
    private function collectArr(object $client, ServiceConnection $serviceConnection, CarbonImmutable $at): void
    {
        $this->collectDiskSpace($client->getDiskSpace(), $serviceConnection, $at);

        $this->statsRecorder->sample(
            'queue.depth',
            ['connection' => (string) $serviceConnection->id, 'service' => $serviceConnection->type->value],
            $at,
            (float) ($client->getQueue(['pageSize' => 1])['totalRecords'] ?? 0),
        );
    }

    /**
     * SABnzbd: disk space rows + queue depth from noofslots.
     */
    private function collectSabnzbd(object $client, ServiceConnection $serviceConnection, CarbonImmutable $at): void
    {
        $this->collectDiskSpace($client->getDiskSpace(), $serviceConnection, $at);

        $this->statsRecorder->sample(
            'queue.depth',
            ['connection' => (string) $serviceConnection->id, 'service' => $serviceConnection->type->value],
            $at,
            (float) ($client->getQueue()['noofslots'] ?? 0),
        );
    }

    /**
     * Per disk-space row: sample free + total bytes, dims connection + path.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function collectDiskSpace(array $rows, ServiceConnection $serviceConnection, CarbonImmutable $at): void
    {
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;

            if ($path === null) {
                continue;
            }

            $dims = ['connection' => (string) $serviceConnection->id, 'path' => (string) $path];

            if (isset($row['freeSpace'])) {
                $this->statsRecorder->sample('service.disk_free_bytes', $dims, $at, (float) $row['freeSpace']);
            }

            if (isset($row['totalSpace'])) {
                $this->statsRecorder->sample('service.disk_total_bytes', $dims, $at, (float) $row['totalSpace']);
            }
        }
    }

    private function snapshotIndexerStats(ServiceConnection $serviceConnection, CarbonImmutable $bucket): void
    {
        $client = $this->serviceClientFactory->make($serviceConnection);
        $indexers = $client->getIndexerStats(sinceHours: 24)['indexers'] ?? [];

        foreach ($indexers as $indexer) {
            $name = $indexer['indexerName'] ?? (isset($indexer['indexerId']) ? (string) $indexer['indexerId'] : null);

            if ($name === null) {
                continue;
            }

            $dims = ['indexer' => (string) $name];

            $this->statsRecorder->put('indexer.grabs', 'day', $bucket, $dims, (int) ($indexer['numberOfGrabs'] ?? 0));
            $this->statsRecorder->put('indexer.queries', 'day', $bucket, $dims, (int) ($indexer['numberOfQueries'] ?? 0));
        }
    }
}
