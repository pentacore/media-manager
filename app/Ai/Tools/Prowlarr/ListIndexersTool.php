<?php

declare(strict_types=1);

namespace App\Ai\Tools\Prowlarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListIndexersTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'List all configured indexers in Prowlarr (id, name, implementation, enabled, priority). Use to inspect indexer health/configuration.';
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
        $connection = ServiceConnection::resolveActive(ServiceType::Prowlarr);

        return (new ProwlarrClient($connection))->listIndexers();
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
