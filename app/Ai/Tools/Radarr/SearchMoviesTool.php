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

class SearchMoviesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search Radarr for movies by title (looks up the catalog, not what is currently downloaded).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function execute(Request $request): array
    {
        $query = (string) ($request->toArray()['query'] ?? '');
        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Radarr);

        return new RadarrClient($serviceConnection)->searchMovies($query);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Title fragment to search for, e.g. "Inception" or "Dune 2021".')
                ->required(),
        ];
    }
}
