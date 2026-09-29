<?php

declare(strict_types=1);

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\Search\LibraryEmbedder;
use App\Settings\AiSettings;
use App\Settings\OpenRouterSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default_for_embeddings', 'openai');
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
});

test('embeddings default to the configured provider and its default model', function (): void {
    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->embeddingsProvider())->toBe('openai')
        ->and($aiSettings->embeddingsModel())->toBeNull()
        ->and($aiSettings->embeddingsSignature())->toBe('openai|text-embedding-3-small');
});

test('a fresh install is not stale', function (): void {
    expect(resolve(AiSettings::class)->embeddingsStale())->toBeFalse();
});

test('changing the embeddings model marks the library stale until re-indexed', function (): void {
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsProvider('openrouter');
    $aiSettings->setEmbeddingsModel('openai/text-embedding-3-small');

    expect($aiSettings->embeddingsStale())->toBeTrue();

    $aiSettings->markEmbeddingsIndexed();

    expect($aiSettings->embeddingsStale())->toBeFalse()
        ->and($aiSettings->embeddingsIndexedWith())->toBe('openrouter|openai/text-embedding-3-small');
});

test('library embeddings are generated on the selected provider and model with routing options', function (): void {
    Embeddings::fake();
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsProvider('openrouter');
    $aiSettings->setEmbeddingsModel('openai/text-embedding-3-small');

    resolve(OpenRouterSettings::class)->setDenyDataCollection(true);

    resolve(LibraryEmbedder::class)->embed(IndexedMovie::factory()->make(['title' => 'Dune']));

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $embeddingsPrompt): bool => $embeddingsPrompt->provider->name() === 'openrouter'
        && $embeddingsPrompt->model === 'openai/text-embedding-3-small'
        && $embeddingsPrompt->providerOptions === ['provider' => ['data_collection' => 'deny']]);
});

test('embedLibrary embeds every item and reports the count', function (): void {
    Embeddings::fake();
    IndexedMovie::factory()->count(3)->create(['embedding' => null]);
    IndexedSeries::factory()->count(2)->create(['embedding' => null]);

    $libraryEmbedder = resolve(LibraryEmbedder::class);

    expect($libraryEmbedder->embedLibrary(chunk: 2))->toBe(5)
        ->and($libraryEmbedder->libraryCount())->toBe(5)
        ->and(IndexedMovie::whereNull('embedding')->count())->toBe(0);
});

test('a full ai:embed-library run stamps the index signature', function (): void {
    Embeddings::fake();
    IndexedMovie::factory()->create(['embedding' => null]);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsModel('text-embedding-3-large');

    $this->artisan('ai:embed-library')->assertSuccessful();

    expect($aiSettings->embeddingsStale())->toBeFalse();
});

test('a missing-only run does not stamp the index signature', function (): void {
    Embeddings::fake();
    IndexedMovie::factory()->create(['embedding' => null]);
    $aiSettings = resolve(AiSettings::class);
    $aiSettings->setEmbeddingsModel('text-embedding-3-large');

    $this->artisan('ai:embed-library', ['--missing-only' => true])->assertSuccessful();

    expect($aiSettings->embeddingsStale())->toBeTrue();
});
