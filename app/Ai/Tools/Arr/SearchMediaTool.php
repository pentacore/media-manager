<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchMediaTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search the Sonarr (TV series), Radarr (movies), or Whisparr catalog by title '
            .'(looks up what can be added, not what is currently downloaded).';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $query = (string) ($args['query'] ?? '');
        $service = mb_strtolower((string) ($args['service'] ?? ''));

        $serviceConnection = ServiceConnection::resolveActive(match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            'whisparr' => ServiceType::Whisparr,
            default => throw new InvalidArgumentException('service must be "sonarr", "radarr", or "whisparr".'),
        });

        return match ($service) {
            'sonarr' => new SonarrClient($serviceConnection)->searchSeries($query),
            'radarr' => new RadarrClient($serviceConnection)->searchMovies($query),
            default => new WhisparrClient($serviceConnection)->searchItems($query),
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
                ->description('Which service catalog to search: sonarr for TV series, radarr for movies, whisparr.')
                ->required(),
            'query' => $schema->string()
                ->description('Title fragment to search for, e.g. "Severance" or "Dune 2021".')
                ->required(),
        ];
    }
}