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
            'add_movie' => $this->addMovie($actionRequest),
            'monitor_movie' => $this->monitorMovie($actionRequest),
            'set_movie_quality_profile' => $this->setMovieQualityProfile($actionRequest),
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

    /**
     * @return array<string, mixed>
     */
    private function addMovie(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $tmdbId = (int) ($payload['tmdb_id'] ?? 0);

        throw_if($tmdbId <= 0, InvalidArgumentException::class, 'tmdb_id is required');

        $client = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));

        // Look up the full movie spec by tmdb_id (Radarr's lookup accepts "tmdb:{id}" syntax).
        $candidates = $client->searchMovies(sprintf('tmdb:%d', $tmdbId));

        throw_if($candidates === [], InvalidArgumentException::class, sprintf('No movie found in Radarr lookup for tmdb_id %d', $tmdbId));

        $seed = $candidates[0];

        $movie = $client->addMovie(array_merge($seed, [
            'qualityProfileId' => (int) ($payload['quality_profile_id'] ?? 0),
            'rootFolderPath' => (string) ($payload['root_folder_path'] ?? ''),
            'monitored' => (bool) ($payload['monitored'] ?? true),
            'addOptions' => ['searchForMovie' => true],
        ]));

        return [
            'radarr_movie_id' => $movie['id'] ?? null,
            'title' => $movie['title'] ?? null,
            'tmdb_id' => $tmdbId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorMovie(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $movieId = (int) ($payload['movie_id'] ?? 0);

        throw_if($movieId <= 0, InvalidArgumentException::class, 'movie_id is required');

        $monitored = (bool) ($payload['monitored'] ?? true);

        $client = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
        $movie = $client->getMovieById($movieId);
        $movie['monitored'] = $monitored;
        $client->updateMovie($movieId, $movie);

        return [
            'radarr_movie_id' => $movieId,
            'monitored' => $monitored,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function setMovieQualityProfile(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $movieId = (int) ($payload['movie_id'] ?? 0);
        $qualityProfileId = (int) ($payload['quality_profile_id'] ?? 0);

        throw_if($movieId <= 0, InvalidArgumentException::class, 'movie_id is required');
        throw_if($qualityProfileId <= 0, InvalidArgumentException::class, 'quality_profile_id is required');

        $client = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
        $movie = $client->getMovieById($movieId);
        $movie['qualityProfileId'] = $qualityProfileId;
        $client->updateMovie($movieId, $movie);

        return [
            'radarr_movie_id' => $movieId,
            'quality_profile_id' => $qualityProfileId,
        ];
    }
}
