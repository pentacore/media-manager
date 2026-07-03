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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileSearchIndex implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

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

            $seenIds = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sonarrId = (int) ($item['id'] ?? 0);

                if ($sonarrId === 0) {
                    continue;
                }

                try {
                    $seriesIndexer->upsert($item, $connection);
                    $seenIds[] = $sonarrId;
                } catch (Throwable $throwable) {
                    Log::warning('ReconcileSearchIndex: series upsert failed', [
                        'connection_id' => $connection->id,
                        'sonarr_id' => $sonarrId,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            }

            IndexedSeries::query()
                ->where('service_connection_id', $connection->id)
                ->when($seenIds !== [], fn ($q) => $q->whereNotIn('sonarr_id', $seenIds))
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

            $seenIds = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $radarrId = (int) ($item['id'] ?? 0);

                if ($radarrId === 0) {
                    continue;
                }

                try {
                    $movieIndexer->upsert($item, $connection);
                    $seenIds[] = $radarrId;
                } catch (Throwable $throwable) {
                    Log::warning('ReconcileSearchIndex: movie upsert failed', [
                        'connection_id' => $connection->id,
                        'radarr_id' => $radarrId,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            }

            IndexedMovie::query()
                ->where('service_connection_id', $connection->id)
                ->when($seenIds !== [], fn ($q) => $q->whereNotIn('radarr_id', $seenIds))
                ->get()
                ->each(static fn (IndexedMovie $indexedMovie): bool => $indexedMovie->delete() !== false);
        }
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
