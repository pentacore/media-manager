<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Http\Resources\Bazarr\SubtitleHistoryResource;
use App\Http\Resources\Bazarr\SubtitleItemResource;
use App\Models\ServiceConnection;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Reads one Bazarr episode or movie by its explicit identifier and returns the
 * sanitized inventory item plus its ten most recent history rows. Every
 * missing or unreadable piece is a typed exception the caller maps to its own
 * response; nothing is guessed.
 */
final readonly class SubtitleInspector
{
    public function __construct(
        private ServiceClientFactory $serviceClientFactory,
        private SubtitleInventoryConnections $subtitleInventoryConnections,
        private SubtitleItemMapper $subtitleItemMapper,
    ) {}

    /**
     * @return array{
     *     item: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function inspect(
        ServiceConnection $serviceConnection,
        string $mediaType,
        int $mediaId,
    ): array {
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Media type must be episode or movie.');
        throw_if($mediaId <= 0, InvalidArgumentException::class, 'Media ID must be positive.');

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);

        if ($mediaType === 'episode') {
            return $this->inspectEpisode($serviceConnection, $bazarrClient, $mediaId);
        }

        return $this->inspectMovie($serviceConnection, $bazarrClient, $mediaId);
    }

    /**
     * @return array{
     *     item: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    private function inspectEpisode(
        ServiceConnection $serviceConnection,
        BazarrClient $bazarrClient,
        int $episodeId,
    ): array {
        $sonarr = $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Sonarr);

        throw_if(! $sonarr instanceof ServiceConnection, ModelNotFoundException::class, 'The mapped Sonarr connection is missing or inactive.');

        $episode = collect($bazarrClient->getEpisodes(episodeIds: [$episodeId])['data'])
            ->first(fn (array $candidate): bool => $this->subtitleItemMapper->positiveInteger($candidate['sonarrEpisodeId'] ?? null) === $episodeId);

        throw_unless(is_array($episode), ModelNotFoundException::class, 'The requested Bazarr episode was not found.');

        $seriesId = $this->subtitleItemMapper->positiveInteger($episode['sonarrSeriesId'] ?? null);

        throw_if($seriesId === null, UnexpectedValueException::class, 'The requested Bazarr episode is missing its Sonarr series ID.');

        $sonarrClient = $this->serviceClientFactory->make($sonarr);
        throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

        $item = $this->subtitleItemMapper->episodeItem($episode, $sonarrClient->getSeriesById($seriesId), $sonarr);

        throw_if($item === null, UnexpectedValueException::class, 'The requested Bazarr episode could not be projected.');

        $history = array_values(array_filter(array_map(
            fn (array $history): ?array => $this->subtitleItemMapper->historyItem($history, 'episode'),
            $bazarrClient->getEpisodeHistory(length: 10, episodeId: $episodeId)['data'],
        )));

        return [
            'item' => new SubtitleItemResource($item)->resolve(),
            'history' => array_map(
                static fn (array $historyItem): array => new SubtitleHistoryResource($historyItem)->resolve(),
                $history,
            ),
            'partial' => false,
            'errors' => [],
        ];
    }

    /**
     * @return array{
     *     item: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    private function inspectMovie(
        ServiceConnection $serviceConnection,
        BazarrClient $bazarrClient,
        int $radarrId,
    ): array {
        throw_if(
            ! $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Radarr) instanceof ServiceConnection,
            ModelNotFoundException::class,
            'The mapped Radarr connection is missing or inactive.',
        );

        $movie = $bazarrClient->findMovieByRadarrId($radarrId);

        throw_unless(is_array($movie), ModelNotFoundException::class, 'The requested Bazarr movie was not found.');

        $item = $this->subtitleItemMapper->movieItem($movie);

        throw_if($item === null, UnexpectedValueException::class, 'The requested Bazarr movie could not be projected.');

        $history = array_values(array_filter(array_map(
            fn (array $history): ?array => $this->subtitleItemMapper->historyItem($history, 'movie'),
            $bazarrClient->getMovieHistory(length: 10, radarrId: $radarrId)['data'],
        )));

        return [
            'item' => new SubtitleItemResource($item)->resolve(),
            'history' => array_map(
                static fn (array $historyItem): array => new SubtitleHistoryResource($historyItem)->resolve(),
                $history,
            ),
            'partial' => false,
            'errors' => [],
        ];
    }
}
