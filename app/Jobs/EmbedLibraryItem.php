<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\Search\LibraryEmbedder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generates and persists the semantic-search embedding for a single indexed
 * library item. Saving the row re-syncs Scout automatically.
 */
class EmbedLibraryItem implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

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
