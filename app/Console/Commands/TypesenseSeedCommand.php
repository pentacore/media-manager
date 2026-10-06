<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
use App\Services\Search\MovieIndexer;
use App\Services\Search\SeriesIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

#[Description('Seed the IndexedSeries / IndexedMovie tables from Sonarr / Radarr APIs and push the result to Typesense.')]
#[Signature('typesense:seed
        {--service= : sonarr|radarr|all (default: all)}
        {--connection= : Limit to a single ServiceConnection id}
        {--fresh : Truncate the indexed_* rows for the targeted connections first}')]
class TypesenseSeedCommand extends Command
{
    public function handle(SeriesIndexer $seriesIndexer, MovieIndexer $movieIndexer, ArrConnections $arrConnections): int
    {
        $service = $this->option('service') ?: 'all';
        $connectionId = $this->option('connection');
        $connectionId = $connectionId !== null ? (int) $connectionId : null;

        $fresh = (bool) $this->option('fresh');

        if (! in_array($service, ['sonarr', 'radarr', 'all'], true)) {
            $this->error(sprintf('Invalid --service value: %s. Use sonarr|radarr|all.', $service));

            return self::FAILURE;
        }

        $sonarrCount = 0;
        $movieCount = 0;

        if (in_array($service, ['sonarr', 'all'], true)) {
            $sonarrCount = $this->seedSonarr($seriesIndexer, $arrConnections, $connectionId, $fresh);
        }

        if (in_array($service, ['radarr', 'all'], true)) {
            $movieCount = $this->seedRadarr($movieIndexer, $arrConnections, $connectionId, $fresh);
        }

        if ($sonarrCount > 0) {
            $this->info('Importing IndexedSeries to Typesense...');
            Artisan::call('scout:import', ['model' => IndexedSeries::class], $this->getOutput());
        }

        if ($movieCount > 0) {
            $this->info('Importing IndexedMovie to Typesense...');
            Artisan::call('scout:import', ['model' => IndexedMovie::class], $this->getOutput());
        }

        $this->info(sprintf('Seeded %d series + %d movies.', $sonarrCount, $movieCount));

        return self::SUCCESS;
    }

    private function seedSonarr(SeriesIndexer $seriesIndexer, ArrConnections $arrConnections, ?int $connectionId, bool $fresh): int
    {
        $count = 0;

        foreach ($this->connections(ServiceType::Sonarr, $connectionId) as $connection) {
            if ($fresh) {
                IndexedSeries::query()
                    ->where('service_connection_id', $connection->id)
                    ->delete();
            }

            try {
                $items = $arrConnections->sonarr($connection)->getSeries();
            } catch (Throwable $throwable) {
                $this->warn(sprintf('Sonarr fetch failed for connection #%d: %s', $connection->id, $throwable->getMessage()));

                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                try {
                    $seriesIndexer->upsert($item, $connection);
                    $count++;
                } catch (Throwable $throwable) {
                    $this->warn(sprintf('Skipping series: %s', $throwable->getMessage()));
                }
            }
        }

        return $count;
    }

    private function seedRadarr(MovieIndexer $movieIndexer, ArrConnections $arrConnections, ?int $connectionId, bool $fresh): int
    {
        $count = 0;

        foreach ($this->connections(ServiceType::Radarr, $connectionId) as $connection) {
            if ($fresh) {
                IndexedMovie::query()
                    ->where('service_connection_id', $connection->id)
                    ->delete();
            }

            try {
                $items = $arrConnections->radarr($connection)->getMovies();
            } catch (Throwable $throwable) {
                $this->warn(sprintf('Radarr fetch failed for connection #%d: %s', $connection->id, $throwable->getMessage()));

                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                try {
                    $movieIndexer->upsert($item, $connection);
                    $count++;
                } catch (Throwable $throwable) {
                    $this->warn(sprintf('Skipping movie: %s', $throwable->getMessage()));
                }
            }
        }

        return $count;
    }

    /**
     * @return iterable<ServiceConnection>
     */
    private function connections(ServiceType $serviceType, ?int $connectionId): iterable
    {
        $builder = ServiceConnection::query()
            ->where('type', $serviceType)
            ->where('is_active', true);

        if ($connectionId !== null) {
            $builder->where('id', $connectionId);
        }

        return $builder->cursor();
    }
}
