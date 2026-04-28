<?php

declare(strict_types=1);

namespace App\Ai\Tools\Radarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class MonitorMovieTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Toggle whether Radarr monitors a movie. Use the movie_id from SearchMoviesTool/GetMovieTool. Required: movie_id, monitored.';
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
            'type' => 'monitor_movie',
            'target_service' => 'radarr',
            'payload' => [
                'movie_id' => (int) ($args['movie_id'] ?? 0),
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
            'movie_id' => $schema->integer()
                ->description('Radarr movie id (use SearchMoviesTool/GetMovieTool to find).')
                ->required(),
            'monitored' => $schema->boolean()
                ->description('Whether Radarr should monitor this movie.')
                ->required(),
        ];
    }
}
