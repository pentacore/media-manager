<?php

declare(strict_types=1);

namespace App\Ai\Tools\Tmdb;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class TmdbGetTitleTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Fetch full TMDB metadata for a movie or TV show by tmdb_id (richer than the Seerr proxy: tagline, runtime, certifications, full episode lists for TV).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $tmdbId = (int) ($args['tmdb_id'] ?? 0);
        $mediaType = (string) ($args['media_type'] ?? '');

        return new TmdbClient()->getTitle($tmdbId, $mediaType);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tmdb_id' => $schema->integer()
                ->description('TMDB numeric id, e.g. 27205 for Inception.')
                ->required(),
            'media_type' => $schema->string()
                ->description('Either "movie" or "tv".')
                ->required(),
        ];
    }
}
