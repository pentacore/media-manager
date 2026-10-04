<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\Search\LibraryEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Generates and persists the semantic-search embedding for a single indexed
 * library item. Saving the row re-syncs Scout automatically.
 */
#[Queue(QueueLane::Ai)]
class EmbedLibraryItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * Below the workers' --timeout (300s) and redis retry_after (330s); no
     * tighter bound is known for this job.
     */
    public int $timeout = 270;

    /**
     * @param  class-string<IndexedMovie|IndexedSeries>  $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int $modelId,
    ) {}

    public function handle(LibraryEmbedder $libraryEmbedder): void
    {
        if (! $libraryEmbedder->enabled()) {
            return;
        }

        if (! in_array($this->modelClass, [IndexedMovie::class, IndexedSeries::class], true)) {
            return;
        }

        $item = $this->modelClass::query()->find($this->modelId);

        if ($item === null) {
            return;
        }

        $vector = $libraryEmbedder->embed($item);

        if ($vector === null) {
            return;
        }

        $item->forceFill(['embedding' => $vector])->save();
    }
}
