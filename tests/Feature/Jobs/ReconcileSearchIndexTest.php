<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Jobs\ReconcileSearchIndex;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Search\MovieIndexer;
use App\Services\Search\SeriesIndexer;

function reconcileConnection(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
    ]);
}

test('prunes rows missing from the arr payload and keeps listed ones', function (): void {
    $connection = reconcileConnection();
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 10]);
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 99]);

    $seriesIndexer = $this->mock(SeriesIndexer::class);
    $seriesIndexer->shouldReceive('upsert')->once();

    Http::fake(['sonarr.local:8989/api/v3/series*' => Http::response([['id' => 10, 'title' => 'Kept']])]);

    new ReconcileSearchIndex()->handle($seriesIndexer, $this->mock(MovieIndexer::class));

    expect(IndexedSeries::query()->where('sonarr_id', 10)->exists())->toBeTrue()
        ->and(IndexedSeries::query()->where('sonarr_id', 99)->exists())->toBeFalse();
});

test('a failed upsert never marks the row stale', function (): void {
    $connection = reconcileConnection();
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 10]);
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 11]);

    $seriesIndexer = $this->mock(SeriesIndexer::class);
    $seriesIndexer->shouldReceive('upsert')->twice()->andThrow(new RuntimeException('typesense down'));

    Http::fake(['sonarr.local:8989/api/v3/series*' => Http::response([
        ['id' => 10, 'title' => 'A'],
        ['id' => 11, 'title' => 'B'],
    ])]);

    new ReconcileSearchIndex()->handle($seriesIndexer, $this->mock(MovieIndexer::class));

    // Regression: seen-set used to be built from successful upserts only, so
    // an all-fail run pruned every row for the connection.
    expect(IndexedSeries::query()->where('service_connection_id', $connection->id)->count())->toBe(2);
});

test('skips the prune when the payload has items but no usable ids', function (): void {
    $connection = reconcileConnection();
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 10]);

    $seriesIndexer = $this->mock(SeriesIndexer::class);
    $seriesIndexer->shouldNotReceive('upsert');

    Http::fake(['sonarr.local:8989/api/v3/series*' => Http::response([['unexpected' => 'shape'], ['id' => 0]])]);

    new ReconcileSearchIndex()->handle($seriesIndexer, $this->mock(MovieIndexer::class));

    expect(IndexedSeries::query()->where('service_connection_id', $connection->id)->count())->toBe(1);
});

test('an empty payload legitimately empties the index for the connection', function (): void {
    $connection = reconcileConnection();
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 10]);

    Http::fake(['sonarr.local:8989/api/v3/series*' => Http::response([])]);

    new ReconcileSearchIndex()->handle($this->mock(SeriesIndexer::class), $this->mock(MovieIndexer::class));

    expect(IndexedSeries::query()->where('service_connection_id', $connection->id)->exists())->toBeFalse();
});

test('a fetch failure leaves the connection index untouched', function (): void {
    $connection = reconcileConnection();
    IndexedSeries::factory()->create(['service_connection_id' => $connection->id, 'sonarr_id' => 10]);

    Http::fake(['sonarr.local:8989/*' => Http::response('boom', 500)]);

    new ReconcileSearchIndex()->handle($this->mock(SeriesIndexer::class), $this->mock(MovieIndexer::class));

    expect(IndexedSeries::query()->where('service_connection_id', $connection->id)->count())->toBe(1);
});

test('radarr reconciliation shares the failed-upsert protection', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
    ]);
    IndexedMovie::factory()->create(['service_connection_id' => $connection->id, 'radarr_id' => 5]);

    $movieIndexer = $this->mock(MovieIndexer::class);
    $movieIndexer->shouldReceive('upsert')->once()->andThrow(new RuntimeException('typesense down'));

    Http::fake(['radarr.local:7878/api/v3/movie*' => Http::response([['id' => 5, 'title' => 'Kept']])]);

    new ReconcileSearchIndex()->handle($this->mock(SeriesIndexer::class), $movieIndexer);

    expect(IndexedMovie::query()->where('radarr_id', 5)->exists())->toBeTrue();
});
