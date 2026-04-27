<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sonarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class MonitorSeriesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Toggle whether Sonarr monitors a series for new episodes. Use the series_id from SearchSeriesTool/GetSeriesTool. Required: series_id, monitored.';
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
            'type' => 'monitor_series',
            'target_service' => 'sonarr',
            'payload' => [
                'series_id' => (int) ($args['series_id'] ?? 0),
                'monitored' => (bool) ($args['monitored'] ?? true),
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
            'monitored' => $schema->boolean()
                ->description('Whether Sonarr should monitor this series for new episodes.')
                ->required(),
        ];
    }
}
