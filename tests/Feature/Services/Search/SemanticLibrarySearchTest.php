<?php

declare(strict_types=1);

use App\Services\Search\LibraryEmbedder;
use App\Services\Search\SemanticLibrarySearch;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;

test('returns unavailable when ai disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $result = resolve(SemanticLibrarySearch::class)->search('moody sci-fi');

    expect($result['available'])->toBeFalse()
        ->and($result['results'])->toBe([]);
});

test('returns unavailable when scout driver is not typesense', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'database');

    $result = resolve(SemanticLibrarySearch::class)->search('moody sci-fi');

    expect($result['available'])->toBeFalse()
        ->and($result['results'])->toBe([]);
});

test('returns unavailable when query embedding generation throws', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'typesense');
    Embeddings::fake(fn () => throw new RuntimeException('provider down'));

    $result = resolve(SemanticLibrarySearch::class)->search('moody sci-fi');

    expect($result['available'])->toBeFalse()
        ->and($result['results'])->toBe([]);
});

test('vector search returns scored library hits merged and ordered by score', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'typesense');
    // No reranking provider configured -> vector order is preserved.
    config()->set('ai.default_for_reranking', '');
    Embeddings::fake();

    $service = makeSearchWithHits([
        'movies' => [
            hit(['radarr_id' => 11, 'title' => 'Blade Runner', 'year' => 1982, 'overview' => 'Neo-noir sci-fi.'], 0.1),
            hit(['radarr_id' => 12, 'title' => 'Arrival', 'year' => 2016, 'overview' => 'Linguist meets aliens.'], 0.4),
        ],
        'series' => [
            hit(['sonarr_id' => 21, 'title' => 'Dark', 'year' => 2017, 'overview' => 'Time travel in a small town.'], 0.05),
        ],
    ]);

    $result = $service->search('moody sci-fi', 10);

    expect($result['available'])->toBeTrue()
        ->and($result['results'])->toHaveCount(3);

    // Ordered by descending score => (1 - distance). Dark (0.95), Blade Runner (0.9), Arrival (0.6).
    expect(array_column($result['results'], 'title'))->toBe(['Dark', 'Blade Runner', 'Arrival']);

    $first = $result['results'][0];
    expect($first)->toMatchArray([
        'kind' => 'series',
        'id' => 21,
        'title' => 'Dark',
        'year' => 2017,
    ]);
    expect($first['score'])->toBeGreaterThan(0.9)
        ->and($first['overview'])->toBe('Time travel in a small town.');

    // Projection maps the correct id column per kind.
    $bladeRunner = collect($result['results'])->firstWhere('title', 'Blade Runner');
    expect($bladeRunner['kind'])->toBe('movie')
        ->and($bladeRunner['id'])->toBe(11);
});

test('kind filter restricts the collections that are queried', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'typesense');
    config()->set('ai.default_for_reranking', '');
    Embeddings::fake();

    $service = makeSearchWithHits([
        'movies' => [hit(['radarr_id' => 11, 'title' => 'Blade Runner', 'year' => 1982, 'overview' => 'x'], 0.1)],
        'series' => [hit(['sonarr_id' => 21, 'title' => 'Dark', 'year' => 2017, 'overview' => 'x'], 0.05)],
    ]);

    $result = $service->search('moody sci-fi', 10, 'movie');

    expect($result['available'])->toBeTrue()
        ->and(array_column($result['results'], 'kind'))->toBe(['movie']);
});

test('limit truncates the merged result set', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'typesense');
    config()->set('ai.default_for_reranking', '');
    Embeddings::fake();

    $service = makeSearchWithHits([
        'movies' => [
            hit(['radarr_id' => 1, 'title' => 'A', 'year' => 2000, 'overview' => 'x'], 0.1),
            hit(['radarr_id' => 2, 'title' => 'B', 'year' => 2000, 'overview' => 'x'], 0.2),
        ],
        'series' => [
            hit(['sonarr_id' => 3, 'title' => 'C', 'year' => 2000, 'overview' => 'x'], 0.05),
        ],
    ]);

    $result = $service->search('x', 2);

    expect($result['results'])->toHaveCount(2)
        ->and(array_column($result['results'], 'title'))->toBe(['C', 'A']);
});

test('reranking reorders and rescores hits when a provider is configured', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'typesense');
    config()->set('ai.default_for_reranking', 'cohere');
    config()->set('ai.providers.cohere.key', 'test-key');
    Embeddings::fake();

    // Rerank flips the vector order: put index 1 (Arrival) first with a fresh score.
    Reranking::fake(fn (RerankingPrompt $prompt): RerankingResponse => new RerankingResponse([
        new RankedDocument(index: 1, document: $prompt->documents[1], score: 0.99),
        new RankedDocument(index: 0, document: $prompt->documents[0], score: 0.42),
    ], new Meta('cohere', 'rerank-english-v3.0')));

    $service = makeSearchWithHits([
        'movies' => [
            hit(['radarr_id' => 11, 'title' => 'Blade Runner', 'year' => 1982, 'overview' => 'a'], 0.05),
            hit(['radarr_id' => 12, 'title' => 'Arrival', 'year' => 2016, 'overview' => 'b'], 0.10),
        ],
        'series' => [],
    ]);

    $result = $service->search('moody sci-fi', 10);

    expect(array_column($result['results'], 'title'))->toBe(['Arrival', 'Blade Runner']);
    expect($result['results'][0]['score'])->toBe(0.99)
        ->and($result['results'][1]['score'])->toBe(0.42);
});

test('reranking failure falls back to vector ordering', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('scout.driver', 'typesense');
    config()->set('ai.default_for_reranking', 'cohere');
    config()->set('ai.providers.cohere.key', 'test-key');
    Embeddings::fake();

    Reranking::fake(fn () => throw new RuntimeException('rerank provider down'));

    $service = makeSearchWithHits([
        'movies' => [
            hit(['radarr_id' => 11, 'title' => 'Blade Runner', 'year' => 1982, 'overview' => 'a'], 0.05),
            hit(['radarr_id' => 12, 'title' => 'Arrival', 'year' => 2016, 'overview' => 'b'], 0.10),
        ],
        'series' => [],
    ]);

    $result = $service->search('moody sci-fi', 10);

    // Vector order preserved: Blade Runner (0.95) before Arrival (0.9).
    expect(array_column($result['results'], 'title'))->toBe(['Blade Runner', 'Arrival']);
});

/**
 * Build a Typesense-hit array shaped like a multi_search document hit.
 *
 * @param  array<string, mixed>  $document
 * @return array{document: array<string, mixed>, vector_distance: float}
 */
function hit(array $document, float $distance): array
{
    return ['document' => $document, 'vector_distance' => $distance];
}

/**
 * Construct a SemanticLibrarySearch whose protected rawVectorSearch is overridden
 * to return canned hits keyed by collection basename (movies|series).
 *
 * @param  array{movies?: array<int, array<string, mixed>>, series?: array<int, array<string, mixed>>}  $hitsByCollection
 */
function makeSearchWithHits(array $hitsByCollection): SemanticLibrarySearch
{
    return new class(resolve(LibraryEmbedder::class), $hitsByCollection) extends SemanticLibrarySearch
    {
        /**
         * @param  array<string, array<int, array<string, mixed>>>  $hitsByCollection
         */
        public function __construct(LibraryEmbedder $libraryEmbedder, private readonly array $hitsByCollection)
        {
            parent::__construct($libraryEmbedder);
        }

        protected function rawVectorSearch(array $vector, string $collection, int $k): array
        {
            $key = str_contains($collection, 'series') ? 'series' : 'movies';

            return $this->hitsByCollection[$key] ?? [];
        }
    };
}
