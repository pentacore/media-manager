<?php

declare(strict_types=1);

namespace App\Ai\Tools\Whisparr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddItemTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Add an item to Whisparr by tmdb_id. Quality profile and root folder are required. Always queues an ActionRequest (auto-executes or pending approval per admin rules).';
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
            'type' => 'whisparr_add_item',
            'target_service' => 'whisparr',
            'payload' => [
                'tmdb_id' => (int) ($args['tmdb_id'] ?? 0),
                'quality_profile_id' => (int) ($args['quality_profile_id'] ?? 0),
                'root_folder_path' => (string) ($args['root_folder_path'] ?? ''),
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
            'tmdb_id' => $schema->integer()->description('TMDB ID. Look it up via SearchItemsTool first.')->required(),
            'quality_profile_id' => $schema->integer()->description('Whisparr quality profile id.')->required(),
            'root_folder_path' => $schema->string()->description('Whisparr root folder path.')->required(),
            'monitored' => $schema->boolean()->description('Whether Whisparr should monitor this item. Default true.')->required(),
        ];
    }
}
