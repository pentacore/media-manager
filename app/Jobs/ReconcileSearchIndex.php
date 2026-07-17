<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Search\MovieIndexer;
use App\Services\Search\SeriesIndexer;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShouldBeUnique + a timeout below the queue `retry_after` (330s) prevent two
 * instances interleaving upserts with each other's delete-what-wasn't-seen
 * prunes — the schedule's withoutOverlapping() only guards the dispatch.
 */
class ReconcileSearchIndex implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 300;

    public function handle(SeriesIndexer $seriesIndexer, MovieIndexer $movieIndexer): void
    {
        $this->reconcileSonarr($seriesIndexer);
        $this->reconcileRadarr($movieIndexer);
    }

    private function reconcileSonarr(SeriesIndexer $seriesIndexer): void
    {
        foreach ($this->connections(ServiceType::Sonarr) as $connection) {
            try {
                $items = new SonarrClient($connection)->getSeries();
            } catch (Throwable $throwable) {
                Log::warning('ReconcileSearchIndex: Sonarr fetch failed', [
                    'connection_id' => $connection->id,
                    'message' => $throwable->getMessage(),
                ]);

                continue;
            }

            // Present = listed in the arr response, independent of whether
            // its upsert succeeds: a failed upsert must never mark the row
            // stale, or a bad sync run deletes the whole index.
            $presentIds = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sonarrId = (int) ($item['id'] ?? 0);

                if ($sonarrId === 0) {
                    continue;
                }

                $presentIds[] = $sonarrId;

                try {
                    $seriesIndexer->upsert($item, $connection);
                } catch (Throwable $throwable) {
                    Log::warning('ReconcileSearchIndex: series upsert failed', [
                        'connection_id' => $connection->id,
                        'sonarr_id' => $sonarrId,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            }

            if (! $this->pruneIsCredible($items, $presentIds, $connection, 'sonarr')) {
                continue;
            }

            IndexedSeries::query()
                ->where('service_connection_id', $connection->id)
                ->when($presentIds !== [], fn ($q) => $q->whereNotIn('sonarr_id', $presentIds))
                ->get()
                ->each(static fn (IndexedSeries $indexedSeries): bool => $indexedSeries->delete() !== false);
        }
    }

    private function reconcileRadarr(MovieIndexer $movieIndexer): void
    {
        foreach ($this->connections(ServiceType::Radarr) as $connection) {
            try {
                $items = new RadarrClient($connection)->getMovies();
            } catch (Throwable $throwable) {
                Log::warning('ReconcileSearchIndex: Radarr fetch failed', [
                    'connection_id' => $connection->id,
                    'message' => $throwable->getMessage(),
                ]);

                continue;
            }

            $presentIds = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $radarrId = (int) ($item['id'] ?? 0);

                if ($radarrId === 0) {
                    continue;
                }

                $presentIds[] = $radarrId;

                try {
                    $movieIndexer->upsert($item, $connection);
                } catch (Throwable $throwable) {
                    Log::warning('ReconcileSearchIndex: movie upsert failed', [
                        'connection_id' => $connection->id,
                        'radarr_id' => $radarrId,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            }

            if (! $this->pruneIsCredible($items, $presentIds, $connection, 'radarr')) {
                continue;
            }

            IndexedMovie::query()
                ->where('service_connection_id', $connection->id)
                ->when($presentIds !== [], fn ($q) => $q->whereNotIn('radarr_id', $presentIds))
                ->get()
                ->each(static fn (IndexedMovie $indexedMovie): bool => $indexedMovie->delete() !== false);
        }
    }

    /**
     * A non-empty arr payload in which not a single row carried a usable id
     * means the response shape changed (or is garbage) — pruning against an
     * empty "present" set would wipe the connection's entire index. A truly
     * empty payload (`[]`) stays credible: the library really is empty.
     *
     * @param  array<int, mixed>  $items
     * @param  array<int, int>  $presentIds
     */
    private function pruneIsCredible(array $items, array $presentIds, ServiceConnection $serviceConnection, string $service): bool
    {
        if ($items === [] || $presentIds !== []) {
            return true;
        }

        Log::warning('ReconcileSearchIndex: skipping prune — payload had items but no usable ids', [
            'connection_id' => $serviceConnection->id,
            'service' => $service,
            'item_count' => count($items),
        ]);

        return false;
    }

    /**
     * @return iterable<ServiceConnection>
     */
    private function connections(ServiceType $serviceType): iterable
    {
        return ServiceConnection::query()
            ->where('type', $serviceType)
            ->where('is_active', true)
            ->cursor();
    }
}
