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

class MonitorMediaTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Toggle the monitored flag for a Sonarr series, Radarr movie, or Whisparr item. Use the item_id '
            .'from SearchMediaTool/GetMediaTool. Queues an ActionRequest.';
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
        $monitored = (bool) ($args['monitored'] ?? true);

        return match ($service) {
            'sonarr' => [
                'type' => 'monitor_series',
                'target_service' => 'sonarr',
                'payload' => ['series_id' => $itemId, 'monitored' => $monitored],
            ],
            'radarr' => [
                'type' => 'monitor_movie',
                'target_service' => 'radarr',
                'payload' => ['movie_id' => $itemId, 'monitored' => $monitored],
            ],
            'whisparr' => [
                'type' => 'whisparr_monitor_item',
                'target_service' => 'whisparr',
                'payload' => ['whisparr_item_id' => $itemId, 'monitored' => $monitored],
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
                ->description('Which service the item lives in: sonarr for TV series, radarr for movies, whisparr.')
                ->required(),
            'item_id' => $schema->integer()
                ->description('Service-native id (Sonarr series id / Radarr movie id / Whisparr item id). Use SearchMediaTool/GetMediaTool to find.')
                ->required(),
            'monitored' => $schema->boolean()
                ->description('Whether the service should monitor this item.')
                ->required(),
        ];
    }
}
