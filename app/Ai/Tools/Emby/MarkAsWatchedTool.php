<?php

declare(strict_types=1);

namespace App\Ai\Tools\Emby;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class MarkAsWatchedTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Mark an Emby item as played for a specific Emby user. Both ids are required — use NowPlayingTool/WatchHistoryTool to find them.';
    }

    public function risk(): Risk
    {
        return Risk::SafeWrite;
    }

    /**
     * @return array{played: bool, item_id: string, user_id: string}
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $userId = (string) ($args['emby_user_id'] ?? '');
        $itemId = (string) ($args['emby_item_id'] ?? '');
        $connection = ServiceConnection::resolveActive(ServiceType::Emby);

        $result = (new EmbyClient($connection))->markItemPlayed($userId, $itemId);

        return [
            'played' => (bool) ($result['Played'] ?? true),
            'item_id' => $itemId,
            'user_id' => $userId,
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'emby_user_id' => $schema->string()->description('Emby user id (UUID).')->required(),
            'emby_item_id' => $schema->string()->description('Emby item id (UUID).')->required(),
        ];
    }
}
