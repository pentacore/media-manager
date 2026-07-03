<?php

declare(strict_types=1);

use App\Jobs\EmbedLibraryItem;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Search\LibraryEmbedder;
use App\Services\Search\MovieIndexer;
use App\Services\Search\SeriesIndexer;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

test('job embeds the item and persists the vector', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Embeddings::fake();

    $movie = IndexedMovie::factory()->create(['embedding' => null]);

    (new EmbedLibraryItem(IndexedMovie::class, $movie->id))->handle(resolve(LibraryEmbedder::class));

    expect($movie->refresh()->embedding)->toBeArray()->not->toBeEmpty();
});

test('job no-ops when ai disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    Embeddings::fake();

    $movie = IndexedMovie::factory()->create(['embedding' => null]);

    (new EmbedLibraryItem(IndexedMovie::class, $movie->id))->handle(resolve(LibraryEmbedder::class));

    expect($movie->refresh()->embedding)->toBeNull();
    Embeddings::assertNothingGenerated();
});

test('job no-ops for an unknown model class', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Embeddings::fake();

    (new EmbedLibraryItem(User::class, 1))->handle(resolve(LibraryEmbedder::class));

    Embeddings::assertNothingGenerated();
});

test('job no-ops when the row is missing', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Embeddings::fake();

    (new EmbedLibraryItem(IndexedMovie::class, 999_999))->handle(resolve(LibraryEmbedder::class));

    Embeddings::assertNothingGenerated();
});

test('MovieIndexer dispatches EmbedLibraryItem when a movie is created', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create();

    $movie = resolve(MovieIndexer::class)->upsert(['id' => 4242, 'title' => 'Fresh'], $connection);

    Queue::assertPushed(EmbedLibraryItem::class, fn (EmbedLibraryItem $job): bool => $job->modelClass === IndexedMovie::class && $job->modelId === $movie->id);
});

test('SeriesIndexer dispatches EmbedLibraryItem when a series is created', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    $series = resolve(SeriesIndexer::class)->upsert(['id' => 5353, 'title' => 'Fresh Show'], $connection);

    Queue::assertPushed(EmbedLibraryItem::class, fn (EmbedLibraryItem $job): bool => $job->modelClass === IndexedSeries::class && $job->modelId === $series->id);
});
