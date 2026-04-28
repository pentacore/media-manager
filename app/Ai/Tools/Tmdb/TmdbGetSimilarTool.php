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

class TmdbGetSimilarTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get titles TMDB considers similar to the given tmdb_id (good fallback when the user has watched X and wants more like it).';
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

        return new TmdbClient()->getSimilar($tmdbId, $mediaType);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tmdb_id' => $schema->integer()
                ->description('TMDB numeric id of the seed title.')
                ->required(),
            'media_type' => $schema->string()
                ->description('Either "movie" or "tv".')
                ->required(),
        ];
    }
}
