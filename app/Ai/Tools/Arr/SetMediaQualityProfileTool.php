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

class SetMediaQualityProfileTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Change the quality profile for a Sonarr series, Radarr movie, or Whisparr item. Use the item_id '
            .'from SearchMediaTool/GetMediaTool and a quality_profile_id from the target service. Queues an ActionRequest.';
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
        $qualityProfileId = (int) ($args['quality_profile_id'] ?? 0);

        return match ($service) {
            'sonarr' => [
                'type' => 'set_series_quality_profile',
                'target_service' => 'sonarr',
                'payload' => ['series_id' => $itemId, 'quality_profile_id' => $qualityProfileId],
            ],
            'radarr' => [
                'type' => 'set_movie_quality_profile',
                'target_service' => 'radarr',
                'payload' => ['movie_id' => $itemId, 'quality_profile_id' => $qualityProfileId],
            ],
            'whisparr' => [
                'type' => 'whisparr_set_quality_profile',
                'target_service' => 'whisparr',
                'payload' => ['whisparr_item_id' => $itemId, 'quality_profile_id' => $qualityProfileId],
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
            'quality_profile_id' => $schema->integer()
                ->description('Quality profile id to apply (from the target service).')
                ->required(),
        ];
    }
}
