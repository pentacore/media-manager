<?php

declare(strict_types=1);

namespace App\Services\Radarr;

use App\Cache\Services\RadarrCache;
use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Arr\ReleaseGrabber;
use App\Services\Arr\SearchCommandRunner;
use App\Services\MediaReplacement\PendingReplacementGuard;
use App\Services\MediaReplacement\ReplacementInFlight;
use App\Support\PayloadInt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RadarrActions implements ActionExecutor
{
    /**
     * Defaults keep `new RadarrActions` (used throughout the tests) working;
     * the container still injects when resolving.
     */
    public function __construct(
        private readonly PendingReplacementGuard $pendingReplacementGuard = new PendingReplacementGuard,
        private readonly ReleaseGrabber $releaseGrabber = new ReleaseGrabber,
        private readonly SearchCommandRunner $searchCommandRunner = new SearchCommandRunner,
    ) {}

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
            'search_media' => $this->searchMedia($actionRequest),
            'grab_release' => $this->grabRelease($actionRequest),
            default => throw new InvalidArgumentException(sprintf('RadarrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteMovie(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $movieId = PayloadInt::required($payload, 'radarr_movie_id');

        $deleteFiles = (bool) ($payload['delete_files'] ?? false);

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Radarr);
        new RadarrClient($serviceConnection)->deleteMovie($movieId, $deleteFiles);
        new RadarrCache($serviceConnection)->bustAll();

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
        $tmdbId = PayloadInt::required($payload, 'tmdb_id');

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Radarr);
        $radarrClient = new RadarrClient($serviceConnection);

        // Look up the full movie spec by tmdb_id (Radarr's lookup accepts "tmdb:{id}" syntax).
        $candidates = $radarrClient->searchMovies(sprintf('tmdb:%d', $tmdbId));

        throw_if($candidates === [], InvalidArgumentException::class, sprintf('No movie found in Radarr lookup for tmdb_id %d', $tmdbId));

        $seed = $candidates[0];

        $movie = $radarrClient->addMovie(array_merge($seed, [
            'qualityProfileId' => (int) ($payload['quality_profile_id'] ?? 0),
            'rootFolderPath' => (string) ($payload['root_folder_path'] ?? ''),
            'monitored' => (bool) ($payload['monitored'] ?? true),
            'addOptions' => ['searchForMovie' => true],
        ]));

        new RadarrCache($serviceConnection)->bustAll();

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
        $movieId = PayloadInt::required($payload, 'movie_id');

        $monitored = (bool) ($payload['monitored'] ?? true);

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Radarr);

        throw_if($this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, movieId: $movieId), ReplacementInFlight::forTitle());

        $radarrClient = new RadarrClient($serviceConnection);
        $movie = $radarrClient->fetchMovieById($movieId);
        $movie['monitored'] = $monitored;
        $radarrClient->updateMovie($movieId, $movie);
        new RadarrCache($serviceConnection)->bustAll();

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
        $movieId = PayloadInt::required($payload, 'movie_id');
        $qualityProfileId = PayloadInt::required($payload, 'quality_profile_id');

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Radarr);
        $radarrClient = new RadarrClient($serviceConnection);
        $movie = $radarrClient->fetchMovieById($movieId);
        $movie['qualityProfileId'] = $qualityProfileId;
        $radarrClient->updateMovie($movieId, $movie);
        new RadarrCache($serviceConnection)->bustAll();

        return [
            'radarr_movie_id' => $movieId,
            'quality_profile_id' => $qualityProfileId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchMedia(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $command = MediaSearchCommand::tryFrom((string) ($payload['command'] ?? ''));

        throw_unless($command instanceof MediaSearchCommand && $command->service() === ServiceType::Radarr, InvalidArgumentException::class, 'command is not a Radarr search');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Radarr);
        $response = $this->searchCommandRunner->run(new RadarrClient($serviceConnection), 'Radarr', $command, $command->arrParameters($payload));

        return [
            'command' => $command->value,
            'arr_command_id' => $response['id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grabRelease(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $guid = (string) ($payload['guid'] ?? '');
        $indexerId = (int) ($payload['indexer_id'] ?? 0);

        throw_if($guid === '' || $indexerId <= 0, InvalidArgumentException::class, 'guid and indexer_id are required');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Radarr);
        $this->releaseGrabber->grab(new RadarrClient($serviceConnection), 'Radarr', $guid, $indexerId);

        try {
            new RadarrCache($serviceConnection)->bustAll();
        } catch (Throwable $throwable) {
            // The grab already succeeded upstream — a stale cache is a
            // read-freshness problem, not a reason to report the grab as
            // failed (which would leave the member thinking nothing happened).
            Log::warning('RadarrActions: failed to bust the Radarr cache after a successful grab', [
                'service_connection_id' => $serviceConnection->id,
                'guid' => $guid,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }

        return [
            'indexer_id' => $indexerId,
            'title' => is_string($payload['release']['title'] ?? null) ? $payload['release']['title'] : null,
        ];
    }
}
