<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteMediaTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Delete a series from Sonarr, a movie from Radarr, or an item from Whisparr. ALWAYS queues an '
            .'ActionRequest (auto-executed or pending approval per the admin rules). Identify the item_id first '
            .'via SearchMediaTool or GetMediaTool — never guess.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array{type: string, target_service: string, payload: array<string, mixed>}
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $itemId = (int) ($args['item_id'] ?? 0);
        $deleteFiles = (bool) ($args['delete_files'] ?? false);

        return match ($service) {
            'sonarr' => [
                'type' => 'delete_series',
                'target_service' => 'sonarr',
                'payload' => ['sonarr_series_id' => $itemId, 'delete_files' => $deleteFiles],
            ],
            'radarr' => [
                'type' => 'delete_movie',
                'target_service' => 'radarr',
                'payload' => ['radarr_movie_id' => $itemId, 'delete_files' => $deleteFiles],
            ],
            'whisparr' => [
                'type' => 'whisparr_delete_item',
                'target_service' => 'whisparr',
                'payload' => ['whisparr_item_id' => $itemId, 'delete_files' => $deleteFiles],
            ],
            default => throw new InvalidArgumentException('service must be "sonarr", "radarr", or "whisparr".'),
        };
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->enum(['sonarr', 'radarr', 'whisparr'])
                ->description('Which service to delete from: sonarr for TV series, radarr for movies, whisparr.')
                ->required(),
            'item_id' => $schema->integer()
                ->description('Service-native id (Sonarr series id / Radarr movie id / Whisparr item id). Use SearchMediaTool/GetMediaTool to find.')
                ->required(),
            'delete_files' => $schema->boolean()
                ->description('Delete the underlying media files too. Default false (just removes from the service).')
                ->required(),
        ];
    }
}
