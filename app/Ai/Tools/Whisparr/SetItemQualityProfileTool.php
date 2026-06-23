<?php

declare(strict_types=1);

namespace App\Ai\Tools\Whisparr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetItemQualityProfileTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Change the quality profile for a Whisparr item. Queues an ActionRequest.';
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
            'type' => 'whisparr_set_quality_profile',
            'target_service' => 'whisparr',
            'payload' => [
                'whisparr_item_id' => (int) ($args['whisparr_item_id'] ?? 0),
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
            'whisparr_item_id' => $schema->integer()->description('Whisparr item id.')->required(),
            'quality_profile_id' => $schema->integer()->description('Target quality profile id.')->required(),
        ];
    }
}
