<?php

declare(strict_types=1);

namespace App\Ai\Tools\Whisparr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteItemTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Delete an item from Whisparr by its Whisparr item id. Optionally delete files. Queues an ActionRequest.';
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
            'type' => 'whisparr_delete_item',
            'target_service' => 'whisparr',
            'payload' => [
                'whisparr_item_id' => (int) ($args['whisparr_item_id'] ?? 0),
                'delete_files' => (bool) ($args['delete_files'] ?? false),
            ],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'whisparr_item_id' => $schema->integer()->description('Whisparr item id to delete.')->required(),
            'delete_files' => $schema->boolean()->description('Also delete files on disk. Default false.')->required(),
        ];
    }
}
