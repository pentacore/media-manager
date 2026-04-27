<?php

declare(strict_types=1);

namespace App\Ai\Tools\Radarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetMovieTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get movies in Radarr. With no movie_id, returns all movies in the library. With a movie_id, returns details for that one movie.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function execute(Request $request): array
    {
        $movieId = $request->toArray()['movie_id'] ?? null;
        $connection = ServiceConnection::resolveActive(ServiceType::Radarr);
        $client = new RadarrClient($connection);

        return $movieId === null
            ? $client->getMovies()
            : $client->getMovieById((int) $movieId);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'movie_id' => $schema->integer()
                ->description('Radarr movie id to fetch details for. Pass null to list all movies.')
                ->required()
                ->nullable(),
        ];
    }
}
