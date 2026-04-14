<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class RadarrActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'delete_movie' => $this->deleteMovie($actionRequest),
            default => throw new InvalidArgumentException(sprintf('RadarrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteMovie(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $movieId = (int) ($payload['radarr_movie_id'] ?? 0);

        throw_if($movieId <= 0, InvalidArgumentException::class, 'radarr_movie_id is required');

        $deleteFiles = (bool) ($payload['delete_files'] ?? false);

        $radarrClient = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
        $radarrClient->deleteMovie($movieId, $deleteFiles);

        return [
            'radarr_movie_id' => $movieId,
            'delete_files' => $deleteFiles,
        ];
    }
}
