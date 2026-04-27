<?php

declare(strict_types=1);

namespace App\Ai\Tools\Prowlarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchIndexersTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search across all configured Prowlarr indexers for a specific release. Returns a list of release candidates (title, indexer, size, seeders, age). Use when the user wants to find a specific release across their indexer pool.';
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
        $args = $request->toArray();
        $query = (string) ($args['query'] ?? '');

        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Prowlarr);

        return new ProwlarrClient($serviceConnection)->searchIndexers($query);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Release title to search for, e.g. "Severance S02E01" or "Inception 2010 1080p".')
                ->required(),
        ];
    }
}
