<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Services\Search\LibraryEmbedder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('ai:embed-library {--chunk=50 : Number of items to embed per batch} {--missing-only : Only embed items without a vector}')]
#[Description('Generate semantic-search embeddings for indexed movies and series.')]
class EmbedLibrary extends Command
{
    public function handle(LibraryEmbedder $libraryEmbedder): int
    {
        if (! $libraryEmbedder->enabled()) {
            $this->warn('AI is disabled — nothing to embed.');

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $total = 0;

        foreach ([IndexedMovie::class, IndexedSeries::class] as $modelClass) {
            $query = $modelClass::query()->orderBy('id');

            if ($this->option('missing-only')) {
                $query->whereNull('embedding');
            }

            $query->chunkById($chunk, function (Collection $items) use ($libraryEmbedder, &$total): void {
                $vectors = $libraryEmbedder->embedMany($items);

                foreach ($items->values() as $index => $item) {
                    $vector = $vectors[$index] ?? null;

                    if ($vector !== null) {
                        $item->forceFill(['embedding' => $vector])->save();
                        $total++;
                    }
                }
            });
        }

        $this->info(sprintf('Embedded %d items.', $total));

        return self::SUCCESS;
    }
}
