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

class SearchCatalogTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search the Seerr catalog by title (multi-search across movies, TV shows, and people).';
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
        $query = (string) ($request->toArray()['query'] ?? '');
        $connection = ServiceConnection::resolveActive(ServiceType::Seerr);

        return (new SeerrClient($connection))->search($query);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Title fragment to search for, e.g. "Inception" or "Breaking Bad".')
                ->required(),
        ];
    }
}
