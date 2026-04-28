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

class TmdbGetCreditsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get cast and crew for a TMDB title — useful when a user asks "what else has this director done?" or "who else stars in this?".';
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

        return new TmdbClient()->getCredits($tmdbId, $mediaType);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tmdb_id' => $schema->integer()
                ->description('TMDB numeric id.')
                ->required(),
            'media_type' => $schema->string()
                ->description('Either "movie" or "tv".')
                ->required(),
        ];
    }
}
