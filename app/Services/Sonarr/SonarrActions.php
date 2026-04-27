<?php

declare(strict_types=1);

namespace App\Services\Sonarr;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class SonarrActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'delete_series' => $this->deleteSeries($actionRequest),
            'add_series' => $this->addSeries($actionRequest),
            default => throw new InvalidArgumentException(sprintf('SonarrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteSeries(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $seriesId = (int) ($payload['sonarr_series_id'] ?? 0);

        throw_if($seriesId <= 0, InvalidArgumentException::class, 'sonarr_series_id is required');

        $deleteFiles = (bool) ($payload['delete_files'] ?? false);

        $sonarrClient = new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr));
        $sonarrClient->deleteSeries($seriesId, $deleteFiles);

        return [
            'sonarr_series_id' => $seriesId,
            'delete_files' => $deleteFiles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addSeries(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $tvdbId = (int) ($payload['tvdb_id'] ?? 0);

        throw_if($tvdbId <= 0, InvalidArgumentException::class, 'tvdb_id is required');

        $client = new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr));

        // Look up the full series spec by tvdb_id (Sonarr's lookup accepts "tvdb:{id}" syntax).
        $candidates = $client->searchSeries(sprintf('tvdb:%d', $tvdbId));

        throw_if($candidates === [], InvalidArgumentException::class, sprintf('No series found in Sonarr lookup for tvdb_id %d', $tvdbId));

        $seed = $candidates[0];

        $series = $client->addSeries(array_merge($seed, [
            'qualityProfileId' => (int) ($payload['quality_profile_id'] ?? 0),
            'rootFolderPath' => (string) ($payload['root_folder_path'] ?? ''),
            'monitored' => (bool) ($payload['monitored'] ?? true),
            'seasonFolder' => (bool) ($payload['season_folder'] ?? true),
            'addOptions' => ['searchForMissingEpisodes' => true],
        ]));

        return [
            'sonarr_series_id' => $series['id'] ?? null,
            'title' => $series['title'] ?? null,
            'tvdb_id' => $tvdbId,
        ];
    }
}
