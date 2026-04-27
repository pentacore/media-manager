<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sonarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddSeriesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Add a series to Sonarr by tvdb_id. Quality profile and root folder are required — get them via GetServiceStatusTool or by asking the user. Always queues an ActionRequest (auto-executes or pending approval per admin rules).';
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
            'type' => 'add_series',
            'target_service' => 'sonarr',
            'payload' => [
                'tvdb_id' => (int) ($args['tvdb_id'] ?? 0),
                'quality_profile_id' => (int) ($args['quality_profile_id'] ?? 0),
                'root_folder_path' => (string) ($args['root_folder_path'] ?? ''),
                'monitored' => (bool) ($args['monitored'] ?? true),
                'season_folder' => (bool) ($args['season_folder'] ?? true),
            ],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tvdb_id' => $schema->integer()
                ->description('TheTVDB ID for the series. Look it up via SearchSeriesTool first.')
                ->required(),
            'quality_profile_id' => $schema->integer()
                ->description('Sonarr quality profile id. Look up available profiles via Sonarr admin if uncertain.')
                ->required(),
            'root_folder_path' => $schema->string()
                ->description('Sonarr root folder path (e.g. "/tv"). Look up via Sonarr admin if uncertain.')
                ->required(),
            'monitored' => $schema->boolean()
                ->description('Whether Sonarr should monitor for new episodes. Default true.')
                ->required(),
            'season_folder' => $schema->boolean()
                ->description('Whether to use per-season subfolders. Default true.')
                ->required(),
        ];
    }
}
