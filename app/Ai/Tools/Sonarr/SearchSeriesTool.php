<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sonarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchSeriesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search Sonarr for TV series by title (looks up the catalog, not what is currently downloaded).';
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
        $connection = ServiceConnection::resolveActive(ServiceType::Sonarr);

        return (new SonarrClient($connection))->searchSeries($query);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Title fragment to search for, e.g. "Severance" or "The Bear 2022".')
                ->required(),
        ];
    }
}
