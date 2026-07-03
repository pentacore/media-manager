<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Scout\EngineManager;
use Throwable;

/**
 * k-NN semantic search over the Typesense library collections using the
 * stored embedding vectors. Optionally reranks merged movie+series hits
 * with the configured reranking provider (Cohere/Jina).
 */
class SemanticLibrarySearch
{
    public function __construct(private readonly LibraryEmbedder $libraryEmbedder) {}

    /**
     * @return array{available: bool, results: array<int, array<string, mixed>>}
     */
    public function search(string $query, int $limit = 10, ?string $kind = null): array
    {
        if (! $this->libraryEmbedder->enabled() || config('scout.driver') !== 'typesense') {
            return ['available' => false, 'results' => []];
        }

        try {
            $vector = Embeddings::for([$query])
                ->dimensions(LibraryEmbedder::DIMENSIONS)
                ->cache()
                ->generate()
                ->first();
        } catch (Throwable $throwable) {
            Log::warning('SemanticLibrarySearch: query embedding failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return ['available' => false, 'results' => []];
        }

        $hits = [];

        if ($kind === null || $kind === 'movie') {
            $hits = [...$hits, ...$this->projectHits(
                $this->rawVectorSearch($vector, (new IndexedMovie)->searchableAs(), $limit),
                'movie',
            )];
        }

        if ($kind === null || $kind === 'series') {
            $hits = [...$hits, ...$this->projectHits(
                $this->rawVectorSearch($vector, (new IndexedSeries)->searchableAs(), $limit),
                'series',
            )];
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $hits = array_slice($hits, 0, $limit);

        return ['available' => true, 'results' => $this->maybeRerank($query, $hits, $limit)];
    }

    /**
     * Raw Typesense k-NN search. Kept small so tests can override it.
     *
     * The Scout Typesense engine exposes no public client accessor; its
     * `__call` proxy forwards unknown methods to the underlying
     * `Typesense\Client`, so `getMultiSearch()` returns the client's
     * `MultiSearch` helper which performs the `/multi_search` request.
     *
     * @param  array<int, float>  $vector
     * @return array<int, array<string, mixed>>
     */
    protected function rawVectorSearch(array $vector, string $collection, int $k): array
    {
        $engine = resolve(EngineManager::class)->engine('typesense');

        $response = $engine->getMultiSearch()->perform([
            'searches' => [[
                'collection' => $collection,
                'q' => '*',
                'vector_query' => sprintf('embedding:([%s], k:%d)', implode(',', $vector), $k),
                'per_page' => $k,
                'exclude_fields' => 'embedding',
            ]],
        ], []);

        return $response['results'][0]['hits'] ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function projectHits(array $hits, string $kind): array
    {
        return array_map(static function (array $hit) use ($kind): array {
            $document = $hit['document'] ?? [];

            return [
                'kind' => $kind,
                'id' => (int) ($document[$kind === 'movie' ? 'radarr_id' : 'sonarr_id'] ?? 0),
                'title' => (string) ($document['title'] ?? ''),
                'year' => isset($document['year']) ? (int) $document['year'] : null,
                'overview' => mb_substr((string) ($document['overview'] ?? ''), 0, 300),
                'score' => 1.0 - (float) ($hit['vector_distance'] ?? 1.0),
            ];
        }, $hits);
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function maybeRerank(string $query, array $hits, int $limit): array
    {
        if ($hits === [] || ! $this->rerankingConfigured()) {
            return $hits;
        }

        try {
            $documents = array_map(
                static fn (array $hit): string => sprintf('%s (%s): %s', $hit['title'], $hit['year'] ?? '?', $hit['overview']),
                $hits,
            );

            $response = Reranking::of($documents)->limit($limit)->rerank($query);

            $reranked = [];

            foreach ($response->results as $rankedDocument) {
                $hit = $hits[$rankedDocument->index];
                $hit['score'] = $rankedDocument->score;
                $reranked[] = $hit;
            }

            return $reranked;
        } catch (Throwable $throwable) {
            Log::warning('SemanticLibrarySearch: rerank failed, returning vector order', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $hits;
        }
    }

    private function rerankingConfigured(): bool
    {
        $provider = (string) config('ai.default_for_reranking', '');

        return $provider !== '' && (string) config(sprintf('ai.providers.%s.key', $provider)) !== '';
    }
}
