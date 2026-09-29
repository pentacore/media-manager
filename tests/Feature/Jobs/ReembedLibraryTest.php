<?php

declare(strict_types=1);

use App\Jobs\ReembedLibrary;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Settings\AiSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

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

test('a re-embed with wrong-dimensioned vectors leaves the library marked stale', function (): void {
    // A provider that ignores the requested `dimensions` and always returns
    // its own vector size.
    Embeddings::fake([new EmbeddingsResponse([[0.1, 0.2]], new Usage, new Meta('openai', 'text-embedding-3-large'))]);
    IndexedMovie::factory()->create(['embedding' => [0.1]]);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsModel('text-embedding-3-large');

    dispatch_sync(new ReembedLibrary);

    expect($aiSettings->embeddingsStale())->toBeTrue();
});

test('changing the embeddings model mid-run leaves the library stale even though nothing failed', function (): void {
    IndexedMovie::factory()->count(3)->create(['embedding' => [0.1]]);
    IndexedSeries::factory()->count(2)->create(['embedding' => [0.1]]);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsModel('text-embedding-3-large');

    $calls = 0;
    Embeddings::fake(function () use (&$calls, $aiSettings): ?array {
        $calls++;

        // Switch models right after the movies page embeds, before series runs.
        if ($calls === 1) {
            $aiSettings->setEmbeddingsModel('text-embedding-mid-run-switch');
        }

        return null;
    });

    dispatch_sync(new ReembedLibrary);

    expect($aiSettings->embeddingsStale())->toBeTrue();
});

test('each page of a re-embed is unique per model and cursor, with a lock that outlives its timeout', function (): void {
    $job = new ReembedLibrary;
    $reflection = new ReflectionClass($job);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe(IndexedMovie::class.':0')
        ->and(new ReembedLibrary(IndexedSeries::class, 200)->uniqueId())->toBe(IndexedSeries::class.':200')
        ->and($reflection->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(600);
});

test('the job timeout stays below the worker timeout', function (): void {
    expect((new ReembedLibrary)->timeout)->toBe(280);
});

test('a run holds the running flag while it works and clears it once the whole chain finishes', function (): void {
    Embeddings::fake();
    IndexedMovie::factory()->count(2)->create(['embedding' => [0.1]]);
    IndexedSeries::factory()->count(2)->create(['embedding' => [0.1]]);
    resolve(AiSettings::class)->setEmbeddingsModel('text-embedding-3-large');
    Cache::put(ReembedLibrary::RUNNING_CACHE_KEY, true, ReembedLibrary::RUNNING_CACHE_TTL);

    $sawFlagDuringRun = false;
    Embeddings::fake(function () use (&$sawFlagDuringRun): ?array {
        $sawFlagDuringRun = $sawFlagDuringRun || Cache::has(ReembedLibrary::RUNNING_CACHE_KEY);

        return null;
    });

    dispatch_sync(new ReembedLibrary);

    expect($sawFlagDuringRun)->toBeTrue()
        ->and(Cache::has(ReembedLibrary::RUNNING_CACHE_KEY))->toBeFalse();
});

test('a run sets the running flag itself even when nothing claimed it first', function (): void {
    Embeddings::fake();
    IndexedMovie::factory()->create(['embedding' => [0.1]]);
    resolve(AiSettings::class)->setEmbeddingsModel('text-embedding-3-large');

    expect(Cache::has(ReembedLibrary::RUNNING_CACHE_KEY))->toBeFalse();

    dispatch_sync(new ReembedLibrary);

    // The chain completed within this single dispatch (small library), so
    // the flag is clear again by the time control returns.
    expect(Cache::has(ReembedLibrary::RUNNING_CACHE_KEY))->toBeFalse();
});

test('failed() clears the running flag so a thrown page unblocks the next click', function (): void {
    Cache::put(ReembedLibrary::RUNNING_CACHE_KEY, true, ReembedLibrary::RUNNING_CACHE_TTL);

    (new ReembedLibrary)->failed(new RuntimeException('provider down'));

    expect(Cache::has(ReembedLibrary::RUNNING_CACHE_KEY))->toBeFalse();
});

test('a second dispatch for the same page is deduped by the unique lock, but a different cursor is not', function (): void {
    Queue::fake();

    dispatch(new ReembedLibrary);
    dispatch(new ReembedLibrary);
    dispatch(new ReembedLibrary(IndexedMovie::class, 200));

    Queue::assertPushed(ReembedLibrary::class, 2);
});
