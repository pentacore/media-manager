<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Builds and generates library-item embeddings for semantic search.
 *
 * One embedding per indexed movie/series, computed from title, year,
 * genres, and overview. SDK-level caching dedupes unchanged texts.
 */
class LibraryEmbedder
{
    public const int DIMENSIONS = 256;

    public function enabled(): bool
    {
        return (bool) config('mediamanager.ai.enabled', false);
    }

    public function embeddingText(IndexedMovie|IndexedSeries $item): string
    {
        $genres = implode(', ', array_filter((array) ($item->genres ?? [])));

        return trim(sprintf(
            "%s (%s)\nGenres: %s\n%s",
            $item->title,
            $item->year !== null ? (string) $item->year : 'unknown year',
            $genres !== '' ? $genres : 'unknown',
            (string) ($item->overview ?? ''),
        ));
    }

    /**
     * @return array<int, float>|null
     */
    public function embed(IndexedMovie|IndexedSeries $item): ?array
    {
        $vectors = $this->embedMany(collect([$item]));

        return $vectors[0] ?? null;
    }

    /**
     * @param  Collection<int, IndexedMovie|IndexedSeries>  $items
     * @return array<int, array<int, float>|null>
     */
    public function embedMany(Collection $items): array
    {
        if (! $this->enabled() || $items->isEmpty()) {
            return $items->map(static fn (): ?array => null)->all();
        }

        $texts = $items->map(fn (IndexedMovie|IndexedSeries $item): string => $this->embeddingText($item))->all();

        try {
            $response = Embeddings::for($texts)
                ->dimensions(self::DIMENSIONS)
                ->cache()
                ->generate();
        } catch (Throwable $throwable) {
            Log::warning('LibraryEmbedder: embedding generation failed', [
                'count' => count($texts),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $items->map(static fn (): ?array => null)->all();
        }

        return $response->embeddings;
    }
}
