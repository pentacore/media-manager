<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sonarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSeriesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get series in Sonarr. With no series_id, returns all series in the library. With a series_id, returns details for that one series.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function execute(Request $request): array
    {
        $seriesId = $request->toArray()['series_id'] ?? null;
        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Sonarr);
        $sonarrClient = new SonarrClient($serviceConnection);

        return $seriesId === null
            ? $sonarrClient->getSeries()
            : $sonarrClient->getSeriesById((int) $seriesId);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'series_id' => $schema->integer()
                ->description('Sonarr series id to fetch details for. Pass null to list all series.')
                ->required()
                ->nullable(),
        ];
    }
}
