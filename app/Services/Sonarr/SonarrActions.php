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
}
