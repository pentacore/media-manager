<?php

declare(strict_types=1);

namespace App\Ai\Tools\Seerr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetTitleTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Fetch full details for a single Seerr title by TMDB id. Specify media_type as "movie" or "tv".';
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

        $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        $client = new SeerrClient($connection);

        return match ($mediaType) {
            'movie' => $client->getMovieDetails($tmdbId),
            'tv' => $client->getTvDetails($tmdbId),
            default => throw new InvalidArgumentException(sprintf('Unknown media_type "%s". Expected "movie" or "tv".', $mediaType)),
        };
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tmdb_id' => $schema->integer()
                ->description('TMDB id for the title (use SearchCatalogTool to find).')
                ->required(),
            'media_type' => $schema->string()
                ->description('Either "movie" or "tv".')
                ->required(),
        ];
    }
}
