<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\Search\LibraryEmbedder;
use App\Settings\AiSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Re-embeds the whole library after the embeddings provider or model changed.
 * Each run embeds one page and dispatches the next, so a large library never
 * hits the job timeout; the signature is stamped only when every item got a
 * vector AND the selection has not changed again since the run started,
 * otherwise the settings page keeps showing the stale banner.
 */
#[Queue(QueueLane::Ai)]
#[UniqueFor(600)]
class ReembedLibrary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Whether a re-embed chain is currently running, across every page and
     * both model classes; claimed by the controller before the first
     * dispatch, refreshed by every page, and cleared when the chain ends or
     * fails so a click after that never starts a second, parallel chain. A
     * crashed worker simply lets it expire.
     */
    public const string RUNNING_CACHE_KEY = 'ai:embeddings:reembed-running';

    public const int RUNNING_CACHE_TTL = 900;

    private const int PAGE_SIZE = 200;

    private const int CHUNK_SIZE = 50;

    public int $tries = 1;

    // Below the queue-ai worker's --timeout (300s) and the redis
    // retry_after (330s), with headroom well above one 200-item page.
    public int $timeout = 280;

    /**
     * @param  class-string<IndexedMovie|IndexedSeries>  $modelClass
     * @param  ?string  $startingSignature  The embeddings signature captured
     *                                      when the very first page of this
     *                                      run was dispatched, or null on
     *                                      the first page, which captures it.
     */
    public function __construct(
        public string $modelClass = IndexedMovie::class,
        public int $afterId = 0,
        public bool $failed = false,
        public ?string $startingSignature = null,
    ) {}

    public function uniqueId(): string
    {
        return sprintf('%s:%d', $this->modelClass, $this->afterId);
    }

    public function handle(LibraryEmbedder $libraryEmbedder, AiSettings $aiSettings): void
    {
        if (! $libraryEmbedder->enabled()) {
            Cache::forget(self::RUNNING_CACHE_KEY);

            return;
        }

        // Refresh the running flag's TTL before working, so a long chain of
        // pages keeps it held past any single page's TTL window.
        Cache::put(self::RUNNING_CACHE_KEY, true, self::RUNNING_CACHE_TTL);

        $startingSignature = $this->startingSignature ?? $aiSettings->embeddingsSignature();

        $items = $this->modelClass::query()
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get();

        $failed = $this->failed;

        foreach ($items->chunk(self::CHUNK_SIZE) as $chunk) {
            /** @var Collection<int, IndexedMovie|IndexedSeries> $chunkItems */
            $chunkItems = $chunk->values();
            $vectors = $libraryEmbedder->embedMany($chunkItems);

            foreach ($chunkItems as $index => $item) {
                $vector = $vectors[$index] ?? null;

                if ($vector === null) {
                    $failed = true;

                    continue;
                }

                $item->forceFill(['embedding' => $vector])->save();
            }
        }

        if ($items->count() === self::PAGE_SIZE) {
            dispatch(new self($this->modelClass, (int) $items->last()->id, $failed, $startingSignature));

            return;
        }

        if ($this->modelClass === IndexedMovie::class) {
            dispatch(new self(IndexedSeries::class, 0, $failed, $startingSignature));

            return;
        }

        if (! $failed && $startingSignature === $aiSettings->embeddingsSignature()) {
            $aiSettings->markEmbeddingsIndexed();
        }

        Cache::forget(self::RUNNING_CACHE_KEY);
    }

    /**
     * Unblocks the next click: a page that throws (retries exhausted) must
     * not leave the running flag held for the rest of its TTL.
     */
    public function failed(?Throwable $throwable): void
    {
        Cache::forget(self::RUNNING_CACHE_KEY);
    }
}
