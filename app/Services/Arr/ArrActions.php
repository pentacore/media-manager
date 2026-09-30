<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrActions;
use App\Services\Sonarr\SonarrActions;
use InvalidArgumentException;

/**
 * Executor for action types that exist for both Sonarr and Radarr
 * (`search_media`, `grab_release`): hands off by `payload.service`.
 */
final readonly class ArrActions implements ActionExecutor
{
    public function __construct(
        private SonarrActions $sonarrActions,
        private RadarrActions $radarrActions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->payload['service'] ?? null) {
            'sonarr' => $this->sonarrActions->execute($actionRequest),
            'radarr' => $this->radarrActions->execute($actionRequest),
            default => throw new InvalidArgumentException(sprintf('%s needs payload.service "sonarr" or "radarr"', $actionRequest->type)),
        };
    }
}
