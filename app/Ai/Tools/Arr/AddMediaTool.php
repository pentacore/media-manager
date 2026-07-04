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

class AddMediaTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Add a series to Sonarr, a movie to Radarr, or an item to Whisparr. remote_id is the TheTVDB id '
            .'for sonarr and the TMDB id for radarr/whisparr — look it up via SearchMediaTool first. Quality '
            .'profile and root folder are required — get them via GetServiceStatusTool or by asking the user. '
            .'Always queues an ActionRequest (auto-executes or pending approval per admin rules).';
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

        $payload = [
            'quality_profile_id' => (int) ($args['quality_profile_id'] ?? 0),
            'root_folder_path' => (string) ($args['root_folder_path'] ?? ''),
            'monitored' => (bool) ($args['monitored'] ?? true),
        ];

        return match ($service) {
            'sonarr' => [
                'type' => 'add_series',
                'target_service' => 'sonarr',
                'payload' => [
                    'tvdb_id' => (int) ($args['remote_id'] ?? 0),
                    ...$payload,
                    'season_folder' => (bool) ($args['season_folder'] ?? true),
                ],
            ],
            'radarr' => [
                'type' => 'add_movie',
                'target_service' => 'radarr',
                'payload' => ['tmdb_id' => (int) ($args['remote_id'] ?? 0), ...$payload],
            ],
            'whisparr' => [
                'type' => 'whisparr_add_item',
                'target_service' => 'whisparr',
                'payload' => ['tmdb_id' => (int) ($args['remote_id'] ?? 0), ...$payload],
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
                ->description('Which service to add to: sonarr for TV series, radarr for movies, whisparr.')
                ->required(),
            'remote_id' => $schema->integer()
                ->description('TheTVDB id (sonarr) or TMDB id (radarr/whisparr). Look it up via SearchMediaTool first.')
                ->required(),
            'quality_profile_id' => $schema->integer()
                ->description('Quality profile id on the target service. Look up available profiles via the service admin if uncertain.')
                ->required(),
            'root_folder_path' => $schema->string()
                ->description('Root folder path on the target service (e.g. "/tv", "/movies"). Look up via the service admin if uncertain.')
                ->required(),
            'monitored' => $schema->boolean()
                ->description('Whether the service should monitor this item. Default true.')
                ->required(),
            'season_folder' => $schema->boolean()
                ->description('Sonarr only: use per-season subfolders. Default true. Pass null for radarr/whisparr.')
                ->required()
                ->nullable(),
        ];
    }
}
