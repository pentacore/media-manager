<?php

declare(strict_types=1);

namespace App\Ai\Tools\Whisparr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class MonitorItemTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Set the monitored flag for a Whisparr item. Queues an ActionRequest.';
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
            'type' => 'whisparr_monitor_item',
            'target_service' => 'whisparr',
            'payload' => [
                'whisparr_item_id' => (int) ($args['whisparr_item_id'] ?? 0),
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
            'whisparr_item_id' => $schema->integer()->description('Whisparr item id.')->required(),
            'monitored' => $schema->boolean()->description('Whether Whisparr should monitor this item.')->required(),
        ];
    }
}
