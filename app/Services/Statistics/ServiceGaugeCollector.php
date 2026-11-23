<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\ServiceType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Arr\ArrClient;
use App\Services\Emby\EmbyClient;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\Seerr\SeerrClient;
use App\Services\ServiceClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
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
                (float) collect($this->clientAs($client, EmbyClient::class)->getActiveSessions())->filter(fn (array $session): bool => isset($session['NowPlayingItem']))->count(),
            ),
            ServiceType::Seerr => $this->statsRecorder->sample(
                'requests.pending_gauge',
                $dims,
                $at,
                (float) ($this->clientAs($client, SeerrClient::class)->getRequestCount()['pending'] ?? 0),
            ),
            ServiceType::SABnzbd => $this->collectSabnzbd($this->clientAs($client, SabnzbdClient::class), $serviceConnection, $at),
            ServiceType::Prowlarr => $this->collectDiskSpace($this->clientAs($client, ProwlarrClient::class)->getDiskSpace(), $serviceConnection, $at),
            ServiceType::Bazarr => $this->collectBazarr($serviceConnection, $at),
            default => $this->collectArr($this->clientAs($client, ArrClient::class), $serviceConnection, $at), // Sonarr / Radarr / Whisparr
        };
    }

    /**
     * The factory returns a union of clients; the connection type decides which
     * one a branch gets, so a mismatch is a programming error.
     *
     * @template TClient of object
     *
     * @param  class-string<TClient>  $class
     * @return TClient
     */
    private function clientAs(object $client, string $class): object
    {
        throw_unless($client instanceof $class, LogicException::class, sprintf('Expected a %s for this connection, got %s.', $class, $client::class));

        return $client;
    }

    private function collectBazarr(ServiceConnection $serviceConnection, CarbonImmutable $at): void
    {
        $dimensions = ['connection' => (string) $serviceConnection->id];
        $counts = SubtitleCase::query()
            ->where('bazarr_connection_id', $serviceConnection->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $activeStatuses = [
            SubtitleCaseStatus::Observing,
            SubtitleCaseStatus::BazarrSearching,
            SubtitleCaseStatus::DownloadRequested,
            SubtitleCaseStatus::ReplacementEligible,
            SubtitleCaseStatus::AdvisorRunning,
            SubtitleCaseStatus::ReplacementRequested,
            SubtitleCaseStatus::NeedsReview,
        ];

        foreach (SubtitleCaseStatus::cases() as $subtitleCaseStatus) {
            $this->statsRecorder->sample(
                'subtitles.cases',
                [...$dimensions, 'status' => $subtitleCaseStatus->value],
                $at,
                (float) ($counts[$subtitleCaseStatus->value] ?? 0),
            );
        }

        $wantedCount = array_sum(array_map(
            static fn (SubtitleCaseStatus $subtitleCaseStatus): int => (int) ($counts[$subtitleCaseStatus->value] ?? 0),
            $activeStatuses,
        ));
        $missingRequirementCount = SubtitleCase::query()
            ->where('bazarr_connection_id', $serviceConnection->id)
            ->whereIn('status', $activeStatuses)
            ->select(['id', 'evidence'])
            ->lazyById()
            ->sum(static function (SubtitleCase $subtitleCase): int {
                $missingLanguages = $subtitleCase->evidence['missing_languages'] ?? null;

                return is_array($missingLanguages) ? count($missingLanguages) : 0;
            });

        $this->statsRecorder->sample('subtitles.wanted', $dimensions, $at, (float) $wantedCount);
        $this->statsRecorder->sample('subtitles.missing_requirements', $dimensions, $at, (float) $missingRequirementCount);
    }

    /**
     * Sonarr / Radarr / Whisparr: disk space + queue depth from totalRecords.
     */
    private function collectArr(ArrClient $client, ServiceConnection $serviceConnection, CarbonImmutable $at): void
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
    private function collectSabnzbd(SabnzbdClient $client, ServiceConnection $serviceConnection, CarbonImmutable $at): void
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
        $client = $this->clientAs($this->serviceClientFactory->make($serviceConnection), ProwlarrClient::class);
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
