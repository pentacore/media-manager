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
use Illuminate\Support\Collection;

/**
 * Re-embeds the whole library after the embeddings provider or model changed.
 * Each run embeds one page and dispatches the next, so a large library never
 * hits the job timeout; the signature is stamped only when every item got a
 * vector, otherwise the settings page keeps showing the stale banner.
 */
#[Queue(QueueLane::Ai)]
class ReembedLibrary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int PAGE_SIZE = 200;

    private const int CHUNK_SIZE = 50;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  class-string<IndexedMovie|IndexedSeries>  $modelClass
     */
    public function __construct(
        public string $modelClass = IndexedMovie::class,
        public int $afterId = 0,
        public bool $failed = false,
    ) {}

    public function uniqueId(): string
    {
        return sprintf('%s:%d', $this->modelClass, $this->afterId);
    }

    public function handle(LibraryEmbedder $libraryEmbedder, AiSettings $aiSettings): void
    {
        if (! $libraryEmbedder->enabled()) {
            return;
        }

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
            dispatch(new self($this->modelClass, (int) $items->last()->id, $failed));

            return;
        }

        if ($this->modelClass === IndexedMovie::class) {
            dispatch(new self(IndexedSeries::class, 0, $failed));

            return;
        }

        if (! $failed) {
            $aiSettings->markEmbeddingsIndexed();
        }
    }
}
