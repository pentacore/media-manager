<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Services\Search\LibraryEmbedder;
use App\Settings\AiSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:embed-library {--chunk=50 : Number of items to embed per batch} {--missing-only : Only embed items without a vector}')]
#[Description('Generate semantic-search embeddings for indexed movies and series.')]
class EmbedLibrary extends Command
{
    public function handle(LibraryEmbedder $libraryEmbedder, AiSettings $aiSettings): int
    {
        if (! $libraryEmbedder->enabled()) {
            $this->warn('AI is disabled — nothing to embed.');

            return self::SUCCESS;
        }

        $missingOnly = (bool) $this->option('missing-only');
        $total = $libraryEmbedder->embedLibrary($missingOnly, (int) $this->option('chunk'));

        if (! $missingOnly && $total === $libraryEmbedder->libraryCount()) {
            $aiSettings->markEmbeddingsIndexed();
        }

        $this->info(sprintf('Embedded %d items.', $total));

        return self::SUCCESS;
    }
}
