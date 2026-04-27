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
use Laravel\Ai\Tools\Request;
use Stringable;

class DiscoverMoviesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Discover movies in the Seerr catalog. Optionally filter by TMDB genre id, sort key (e.g. "popularity.desc", "vote_average.desc"), and page.';
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
        $connection = ServiceConnection::resolveActive(ServiceType::Seerr);

        $options = array_filter([
            'genre' => $args['genre'] ?? null,
            'sortBy' => $args['sort_by'] ?? null,
            'page' => $args['page'] ?? null,
        ], fn ($value): bool => $value !== null);

        return (new SeerrClient($connection))->discoverMovies($options);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'genre' => $schema->string()
                ->description('Optional TMDB genre id to filter by (e.g. "28" for Action).')
                ->required()
                ->nullable(),
            'sort_by' => $schema->string()
                ->description('Optional sort key, e.g. "popularity.desc" or "vote_average.desc".')
                ->required()
                ->nullable(),
            'page' => $schema->integer()
                ->description('Optional page number (1-indexed).')
                ->required()
                ->nullable(),
        ];
    }
}
