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

class GetItemTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get items in Whisparr. With no whisparr_item_id, returns all items in the library. With an id, returns that one item. Works for both v3 (movies) and v2 (series) — the configured connection determines the resource.';
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
        $itemId = $request->toArray()['whisparr_item_id'] ?? null;
        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Whisparr);
        $whisparrClient = new WhisparrClient($serviceConnection);

        return $itemId === null ? $whisparrClient->getItems() : $whisparrClient->getItemById((int) $itemId);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'whisparr_item_id' => $schema->integer()
                ->description('Whisparr item id to fetch. Pass null to list all items.')
                ->required()
                ->nullable(),
        ];
    }
}
