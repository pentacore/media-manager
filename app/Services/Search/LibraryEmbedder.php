<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Ai\OpenRouterRequestOptions;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\AiUsage\AiUsageCaller;
use App\Settings\AiSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\EmbeddingsResponse;
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

    public function __construct(
        private readonly AiSettings $aiSettings,
        private readonly OpenRouterRequestOptions $openRouterRequestOptions,
    ) {}

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
            $response = resolve(AiUsageCaller::class)->during(self::class, fn (): EmbeddingsResponse => $this->pendingEmbeddings($texts)->generate(
                provider: $this->aiSettings->embeddingsProvider(),
                model: $this->aiSettings->embeddingsModel(),
            ));
        } catch (Throwable $throwable) {
            Log::warning('LibraryEmbedder: embedding generation failed', [
                'count' => count($texts),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return $items->map(static fn (): ?array => null)->all();
        }

        $wrongDimensions = null;

        $vectors = array_map(function (array $vector) use (&$wrongDimensions): ?array {
            if (count($vector) !== self::DIMENSIONS) {
                $wrongDimensions ??= count($vector);

                return null;
            }

            return $vector;
        }, $response->embeddings);

        if ($wrongDimensions !== null) {
            Log::warning('LibraryEmbedder: embedding vector had the wrong dimensions', [
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'expected' => self::DIMENSIONS,
                'actual' => $wrongDimensions,
            ]);
        }

        return $vectors;
    }

    /**
     * An embeddings request for the given texts at the library dimensions,
     * cached, and carrying OpenRouter routing preferences when the provider is
     * OpenRouter. Shared by indexing and query-time search so both embed with
     * identical options.
     *
     * @param  list<string>  $texts
     */
    public function pendingEmbeddings(array $texts): PendingEmbeddingsGeneration
    {
        return Embeddings::for($texts)
            ->dimensions(self::DIMENSIONS)
            ->cache()
            ->withProviderOptions(fn (Provider $provider): array => $this->openRouterRequestOptions->for($provider->driver()));
    }

    /**
     * Embed every indexed movie and series (or only those without a vector),
     * in chunks, and return how many got a vector.
     */
    public function embedLibrary(bool $missingOnly = false, int $chunk = 50): int
    {
        $total = 0;

        foreach ([IndexedMovie::class, IndexedSeries::class] as $modelClass) {
            $query = $modelClass::query()->orderBy('id');

            if ($missingOnly) {
                $query->whereNull('embedding');
            }

            $query->chunkById(max(1, $chunk), function (Collection $items) use (&$total): void {
                $vectors = $this->embedMany($items);

                foreach ($items->values() as $index => $item) {
                    $vector = $vectors[$index] ?? null;

                    if ($vector !== null) {
                        $item->forceFill(['embedding' => $vector])->save();
                        $total++;
                    }
                }
            });
        }

        return $total;
    }

    /**
     * How many library items a full re-embed must cover.
     */
    public function libraryCount(): int
    {
        return IndexedMovie::query()->count() + IndexedSeries::query()->count();
    }
}
