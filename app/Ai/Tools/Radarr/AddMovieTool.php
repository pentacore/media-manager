<?php

declare(strict_types=1);

namespace App\Ai\Tools\Radarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddMovieTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Add a movie to Radarr by tmdb_id. Quality profile and root folder are required — get them via GetServiceStatusTool or by asking the user. Always queues an ActionRequest (auto-executes or pending approval per admin rules).';
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
            'type' => 'add_movie',
            'target_service' => 'radarr',
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
            'tmdb_id' => $schema->integer()
                ->description('TMDB ID for the movie. Look it up via SearchMoviesTool first.')
                ->required(),
            'quality_profile_id' => $schema->integer()
                ->description('Radarr quality profile id. Look up available profiles via Radarr admin if uncertain.')
                ->required(),
            'root_folder_path' => $schema->string()
                ->description('Radarr root folder path (e.g. "/movies"). Look up via Radarr admin if uncertain.')
                ->required(),
            'monitored' => $schema->boolean()
                ->description('Whether Radarr should monitor for this movie. Default true.')
                ->required(),
        ];
    }
}
