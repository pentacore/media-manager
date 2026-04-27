<?php

declare(strict_types=1);

namespace App\Ai\Tools\Radarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteMovieTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Delete a movie from Radarr. ALWAYS queues an ActionRequest (auto-executed or pending approval per the admin rules). Identify the movie_id first via SearchMoviesTool or GetMovieTool — never guess.';
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
            'type' => 'delete_movie',
            'target_service' => 'radarr',
            'payload' => [
                'radarr_movie_id' => (int) ($args['movie_id'] ?? 0),
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
            'movie_id' => $schema->integer()
                ->description('Radarr movie id (use SearchMoviesTool/GetMovieTool to find).')
                ->required(),
            'delete_files' => $schema->boolean()
                ->description('Delete the underlying media files too. Default false (just removes from Radarr).')
                ->required(),
        ];
    }
}
