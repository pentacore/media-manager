<?php

declare(strict_types=1);

use App\Jobs\ReembedLibrary;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
});

test('a re-embed covers movies then series and stamps the new signature', function (): void {
    Embeddings::fake();
    IndexedMovie::factory()->count(3)->create(['embedding' => [0.1]]);
    IndexedSeries::factory()->count(2)->create(['embedding' => [0.1]]);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsModel('text-embedding-3-large');

    dispatch_sync(new ReembedLibrary);

    expect($aiSettings->embeddingsStale())->toBeFalse()
        ->and(IndexedMovie::query()->get()->every(fn (IndexedMovie $indexedMovie): bool => count($indexedMovie->embedding) > 1))->toBeTrue()
        ->and(IndexedSeries::query()->get()->every(fn (IndexedSeries $indexedSeries): bool => count($indexedSeries->embedding) > 1))->toBeTrue();
});

test('a re-embed with failures leaves the library marked stale', function (): void {
    Embeddings::fake(fn () => throw new RuntimeException('provider down'));
    IndexedMovie::factory()->create(['embedding' => [0.1]]);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsModel('text-embedding-3-large');

    dispatch_sync(new ReembedLibrary);

    expect($aiSettings->embeddingsStale())->toBeTrue();
});

test('each page of a re-embed is unique per model and cursor', function (): void {
    expect((new ReembedLibrary)->uniqueId())->toBe(IndexedMovie::class.':0')
        ->and(new ReembedLibrary(IndexedSeries::class, 200)->uniqueId())->toBe(IndexedSeries::class.':200');
});
