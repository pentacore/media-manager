<?php

declare(strict_types=1);

namespace App\Ai\Tools\Whisparr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchItemsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search the Whisparr lookup by a free-text term to find items to add.';
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
        $term = (string) ($request->toArray()['term'] ?? '');
        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Whisparr);

        return new WhisparrClient($serviceConnection)->searchItems($term);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'term' => $schema->string()
                ->description('Search term, e.g. a title.')
                ->required(),
        ];
    }
}
