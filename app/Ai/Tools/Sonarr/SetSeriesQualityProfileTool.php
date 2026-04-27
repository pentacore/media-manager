<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sonarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetSeriesQualityProfileTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Change the quality profile for a series in Sonarr. Use the series_id from SearchSeriesTool/GetSeriesTool. quality_profile_id from Sonarr\'s quality profiles list.';
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

        return [
            'type' => 'set_series_quality_profile',
            'target_service' => 'sonarr',
            'payload' => [
                'series_id' => (int) ($args['series_id'] ?? 0),
                'quality_profile_id' => (int) ($args['quality_profile_id'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'series_id' => $schema->integer()
                ->description('Sonarr series id (use SearchSeriesTool/GetSeriesTool to find).')
                ->required(),
            'quality_profile_id' => $schema->integer()
                ->description('Sonarr quality profile id to apply.')
                ->required(),
        ];
    }
}
