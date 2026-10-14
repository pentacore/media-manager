<?php

declare(strict_types=1);

namespace App\Ai\Tools\Seerr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrReadFailure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
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

        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Seerr);
        $seerrClient = new SeerrClient($serviceConnection);

        try {
            return match ($mediaType) {
                'movie' => $seerrClient->getMovieDetails($tmdbId),
                'tv' => $seerrClient->getTvDetails($tmdbId),
                default => throw new InvalidArgumentException(sprintf('Unknown media_type "%s". Expected "movie" or "tv".', $mediaType)),
            };
        } catch (RequestException $requestException) {
            // Seerr answered without its data (a login page in front of it, a
            // rejected API key): a retry gets the same answer, so tell the
            // model to stop and hand it to an admin. An unknown title (404),
            // an outage and anything else stay BaseTool's tool_failed.
            throw_if($requestException->response->status() === 404 || ! SeerrReadFailure::isRefusal($requestException), $requestException);

            return [
                'error' => 'seerr_refused',
                'message' => 'Seerr refused the lookup: it answered without its API data, usually because a login page sits in front of Seerr or its API key was rejected. Retrying will not help. Tell the user an admin needs to check the Seerr connection.',
            ];
        }
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
