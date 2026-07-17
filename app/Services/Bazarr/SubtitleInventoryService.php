<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Enums\MediaReplacementScope;
use App\Enums\ServiceType;
use App\Http\Resources\Bazarr\SubtitleHistoryResource;
use App\Http\Resources\Bazarr\SubtitleItemResource;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\LanguageNormalizer;
use App\Services\MediaReplacement\SonarrMediaScopeResolver;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use App\Settings\MediaReplacementSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

final readonly class SubtitleInventoryService
{
    private const int EPISODE_SERIES_BATCH_SIZE = 50;

    private const int MAX_PER_PAGE = 100;

    public function __construct(
        private ServiceClientFactory $serviceClientFactory,
        private MediaReplacementSettings $mediaReplacementSettings,
        private LanguageNormalizer $languageNormalizer,
        private SonarrMediaScopeResolver $sonarrMediaScopeResolver,
    ) {}

    /**
     * @return array{
     *     missing: array{episodes: int, movies: int, total: int},
     *     health_issue_count: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function overview(ServiceConnection $serviceConnection): array
    {
        $bazarrClient = $this->bazarrClient($serviceConnection);
        $episodeTotal = 0;
        $movieTotal = 0;
        $healthIssueCount = 0;
        $errors = [];

        try {
            $episodeTotal = $bazarrClient->getWantedEpisodes(length: 1)['total'];
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $errors[] = 'Episode subtitle counts are temporarily unavailable.';
        }

        try {
            $movieTotal = $bazarrClient->getWantedMovies(length: 1)['total'];
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $errors[] = 'Movie subtitle counts are temporarily unavailable.';
        }

        try {
            $healthIssueCount = count($bazarrClient->getHealth()['data']);
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $errors[] = 'Bazarr health details are temporarily unavailable.';
        }

        return [
            'missing' => [
                'episodes' => $episodeTotal,
                'movies' => $movieTotal,
                'total' => $episodeTotal + $movieTotal,
            ],
            'health_issue_count' => $healthIssueCount,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function library(
        ServiceConnection $serviceConnection,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        $this->validatePagination($page, $perPage);

        $bazarrClient = $this->bazarrClient($serviceConnection);
        $items = [];
        $errors = [];

        $sonarr = $this->activeMappedConnection($serviceConnection, BazarrServiceRole::Sonarr);

        if (!$sonarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Sonarr connection is missing or inactive.';
        } else {
            try {
                $items = [
                    ...$items,
                    ...$this->episodeLibrary($bazarrClient, $sonarr),
                ];
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Sonarr episode inventory is temporarily unavailable.';
            }
        }

        $radarr = $this->activeMappedConnection($serviceConnection, BazarrServiceRole::Radarr);

        if (!$radarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Radarr connection is missing or inactive.';
        } else {
            try {
                $moviePage = $bazarrClient->getMovies(
                    start: 0,
                    length: min(self::MAX_PER_PAGE, $page * $perPage),
                );
                $items = [
                    ...$items,
                    ...array_values(array_filter(array_map(
                        $this->movieItem(...),
                        $moviePage['data'],
                    ))),
                ];
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Radarr movie inventory is temporarily unavailable.';
            }
        }

        $items = $this->applyFilters($items, $filters);
        $total = count($items);

        return [
            'data' => array_map(
                static fn (array $item): array => new SubtitleItemResource($item)->resolve(),
                array_slice($items, ($page - 1) * $perPage, $perPage),
            ),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function missing(
        ServiceConnection $serviceConnection,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        $this->validatePagination($page, $perPage);

        $bazarrClient = $this->bazarrClient($serviceConnection);
        $start = ($page - 1) * $perPage;
        $items = [];
        $total = 0;
        $errors = [];
        $mediaTypeFilter = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        throw_unless(
            $mediaTypeFilter === null || in_array($mediaTypeFilter, ['episode', 'movie'], true),
            InvalidArgumentException::class,
            'Media type filter must be episode or movie.',
        );

        $sonarr = $mediaTypeFilter === 'movie'
            ? null
            : $this->activeMappedConnection($serviceConnection, BazarrServiceRole::Sonarr);

        if ($mediaTypeFilter !== 'movie' && !$sonarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Sonarr connection is missing or inactive.';
        } elseif ($sonarr instanceof ServiceConnection) {
            try {
                $sonarrClient = $this->serviceClientFactory->make($sonarr);
                throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

                $seriesById = collect($sonarrClient->getSeries())
                    ->filter(fn (mixed $series): bool => is_array($series) && $this->positiveInteger($series['id'] ?? null) !== null)
                    ->keyBy(fn (array $series): int => (int) $series['id']);
                $episodePage = $bazarrClient->getWantedEpisodes($start, $perPage);
                $total += $episodePage['total'];

                foreach ($episodePage['data'] as $episode) {
                    $seriesId = $this->positiveInteger($episode['sonarrSeriesId'] ?? null);
                    $series = $seriesId === null ? null : $seriesById->get($seriesId);

                    if (! is_array($series)) {
                        continue;
                    }

                    $item = $this->episodeItem($episode, $series, $sonarr);

                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Sonarr wanted subtitles are temporarily unavailable.';
            }
        }

        $radarr = $mediaTypeFilter === 'episode'
            ? null
            : $this->activeMappedConnection($serviceConnection, BazarrServiceRole::Radarr);

        if ($mediaTypeFilter !== 'episode' && !$radarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Radarr connection is missing or inactive.';
        } elseif ($radarr instanceof ServiceConnection) {
            try {
                $moviePage = $bazarrClient->getWantedMovies($start, $perPage);
                $total += $moviePage['total'];

                foreach ($moviePage['data'] as $movie) {
                    $item = $this->movieItem($movie);

                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Radarr wanted subtitles are temporarily unavailable.';
            }
        }

        $items = $this->applyFilters($items, $filters);

        return [
            'data' => array_map(
                static fn (array $item): array => new SubtitleItemResource($item)->resolve(),
                array_slice($items, 0, $perPage),
            ),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function history(
        ServiceConnection $serviceConnection,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        $this->validatePagination($page, $perPage);

        $bazarrClient = $this->bazarrClient($serviceConnection);
        $start = ($page - 1) * $perPage;
        $items = [];
        $total = 0;
        $errors = [];

        if (!$this->activeMappedConnection($serviceConnection, BazarrServiceRole::Sonarr) instanceof ServiceConnection) {
            $errors[] = 'The mapped Sonarr connection is missing or inactive.';
        } else {
            try {
                $episodePage = $bazarrClient->getEpisodeHistory($start, $perPage);
                $total += $episodePage['total'];
                $items = [
                    ...$items,
                    ...array_values(array_filter(array_map(
                        fn (array $history): ?array => $this->historyItem($history, 'episode'),
                        $episodePage['data'],
                    ))),
                ];
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Sonarr subtitle history is temporarily unavailable.';
            }
        }

        if (!$this->activeMappedConnection($serviceConnection, BazarrServiceRole::Radarr) instanceof ServiceConnection) {
            $errors[] = 'The mapped Radarr connection is missing or inactive.';
        } else {
            try {
                $moviePage = $bazarrClient->getMovieHistory($start, $perPage);
                $total += $moviePage['total'];
                $items = [
                    ...$items,
                    ...array_values(array_filter(array_map(
                        fn (array $history): ?array => $this->historyItem($history, 'movie'),
                        $moviePage['data'],
                    ))),
                ];
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Radarr subtitle history is temporarily unavailable.';
            }
        }

        $items = $this->applyHistoryFilters($items, $filters);

        return [
            'data' => array_map(
                static fn (array $item): array => new SubtitleHistoryResource($item)->resolve(),
                array_slice($items, 0, $perPage),
            ),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'partial' => $errors !== [],
            'errors' => $errors,
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
    public function inspect(
        ServiceConnection $serviceConnection,
        string $mediaType,
        int $mediaId,
    ): array {
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Media type must be episode or movie.');
        throw_if($mediaId <= 0, InvalidArgumentException::class, 'Media ID must be positive.');

        $bazarrClient = $this->bazarrClient($serviceConnection);

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
        $sonarr = $this->activeMappedConnection($serviceConnection, BazarrServiceRole::Sonarr);

        throw_if(!$sonarr instanceof ServiceConnection, ModelNotFoundException::class, 'The mapped Sonarr connection is missing or inactive.');

        $episode = collect($bazarrClient->getEpisodes(episodeIds: [$episodeId])['data'])
            ->first(fn (array $candidate): bool => $this->positiveInteger($candidate['sonarrEpisodeId'] ?? null) === $episodeId);

        throw_unless(is_array($episode), ModelNotFoundException::class, 'The requested Bazarr episode was not found.');

        $seriesId = $this->positiveInteger($episode['sonarrSeriesId'] ?? null);

        throw_if($seriesId === null, UnexpectedValueException::class, 'The requested Bazarr episode is missing its Sonarr series ID.');

        $sonarrClient = $this->serviceClientFactory->make($sonarr);
        throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

        $item = $this->episodeItem($episode, $sonarrClient->getSeriesById($seriesId), $sonarr);

        throw_if($item === null, UnexpectedValueException::class, 'The requested Bazarr episode could not be projected.');

        $history = array_values(array_filter(array_map(
            fn (array $history): ?array => $this->historyItem($history, 'episode'),
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
            !$this->activeMappedConnection($serviceConnection, BazarrServiceRole::Radarr) instanceof ServiceConnection,
            ModelNotFoundException::class,
            'The mapped Radarr connection is missing or inactive.',
        );

        $movie = collect($bazarrClient->getMovies(length: self::MAX_PER_PAGE)['data'])
            ->first(fn (array $candidate): bool => $this->positiveInteger($candidate['radarrId'] ?? null) === $radarrId);

        throw_unless(is_array($movie), ModelNotFoundException::class, 'The requested Bazarr movie was not found.');

        $item = $this->movieItem($movie);

        throw_if($item === null, UnexpectedValueException::class, 'The requested Bazarr movie could not be projected.');

        $history = array_values(array_filter(array_map(
            fn (array $history): ?array => $this->historyItem($history, 'movie'),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function episodeLibrary(BazarrClient $bazarrClient, ServiceConnection $serviceConnection): array
    {
        $sonarrClient = $this->serviceClientFactory->make($serviceConnection);

        throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

        $seriesById = collect($sonarrClient->getSeries())
            ->filter(fn (mixed $series): bool => is_array($series) && $this->positiveInteger($series['id'] ?? null) !== null)
            ->keyBy(fn (array $series): int => (int) $series['id']);
        $items = [];

        foreach (array_chunk($seriesById->keys()->all(), self::EPISODE_SERIES_BATCH_SIZE) as $seriesIds) {
            $episodes = $bazarrClient->getEpisodes(seriesIds: $seriesIds)['data'];

            foreach ($episodes as $episode) {
                $seriesId = $this->positiveInteger($episode['sonarrSeriesId'] ?? null);
                $series = $seriesId === null ? null : $seriesById->get($seriesId);

                if (! is_array($series)) {
                    continue;
                }

                $item = $this->episodeItem($episode, $series, $serviceConnection);

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $episode
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>|null
     */
    private function episodeItem(array $episode, array $series, ServiceConnection $serviceConnection): ?array
    {
        $mediaId = $this->positiveInteger($episode['sonarrEpisodeId'] ?? null);
        $seriesId = $this->positiveInteger($episode['sonarrSeriesId'] ?? null);
        $scope = $this->sonarrMediaScopeResolver->resolve($serviceConnection, $series);

        if ($mediaId === null || $seriesId === null || !$scope instanceof MediaReplacementScope) {
            return null;
        }

        $tracks = $this->subtitleTracks($episode['subtitles'] ?? null, 'episode', $mediaId);
        $requiredLanguages = $this->mediaReplacementSettings->effectiveLanguages($scope);

        return [
            'media_type' => 'episode',
            'media_id' => $mediaId,
            'series_id' => $seriesId,
            'scope' => $scope->value,
            'title' => $this->episodeTitle($series, $episode),
            'subtitle_tracks' => $tracks,
            'required_languages' => $requiredLanguages,
            'missing_languages' => $this->missingLanguages($requiredLanguages, $tracks),
            'monitored' => ($episode['monitored'] ?? true) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>|null
     */
    private function movieItem(array $movie): ?array
    {
        $mediaId = $this->positiveInteger($movie['radarrId'] ?? null);

        if ($mediaId === null) {
            return null;
        }

        $tracks = $this->subtitleTracks($movie['subtitles'] ?? null, 'movie', $mediaId);
        $requiredLanguages = $this->mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Movie);

        return [
            'media_type' => 'movie',
            'media_id' => $mediaId,
            'scope' => MediaReplacementScope::Movie->value,
            'title' => $this->safeText($movie['title'] ?? null, 'Movie '.$mediaId),
            'subtitle_tracks' => $tracks,
            'required_languages' => $requiredLanguages,
            'missing_languages' => $this->missingLanguages($requiredLanguages, $tracks),
            'monitored' => ($movie['monitored'] ?? true) === true,
        ];
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>|null
     */
    private function historyItem(array $history, string $mediaType): ?array
    {
        $mediaId = $this->positiveInteger(
            $mediaType === 'episode'
                ? ($history['sonarrEpisodeId'] ?? null)
                : ($history['radarrId'] ?? null),
        );

        if ($mediaId === null) {
            return null;
        }

        $languagePayload = $history['language'] ?? null;
        $language = $this->languageNormalizer->normalize(
            is_array($languagePayload)
                ? $this->firstString($languagePayload, ['code3', 'code2', 'name'])
                : (is_string($languagePayload) ? $languagePayload : null),
        );

        if ($language === null) {
            return null;
        }

        $title = $mediaType === 'episode'
            ? $this->safeText($history['seriesTitle'] ?? null, 'Series')
                .' — '.$this->safeText($history['episodeTitle'] ?? null, 'Episode')
            : $this->safeText($history['title'] ?? null, 'Movie '.$mediaId);

        return [
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'title' => $title,
            'language' => $language,
            'provider' => $this->safeProvider($history['provider'] ?? null),
            'action' => is_int($history['action'] ?? null) ? $history['action'] : null,
            'score' => $this->safeText($history['score'] ?? null, ''),
            'occurred_at' => $this->safeText($history['parsed_timestamp'] ?? $history['timestamp'] ?? null, ''),
        ];
    }

    /**
     * @return list<array{
     *     fingerprint: string,
     *     display_name: string,
     *     language: string,
     *     kind: 'embedded'|'external',
     *     forced: bool,
     *     hearing_impaired: bool
     * }>
     */
    private function subtitleTracks(mixed $tracks, string $mediaType, int $mediaId): array
    {
        if (! is_array($tracks)) {
            return [];
        }

        $normalizedTracks = [];

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            $language = $this->languageNormalizer->normalize(
                $this->firstString($track, ['code3', 'code2', 'language', 'name']),
            );

            if ($language === null) {
                continue;
            }

            $path = is_string($track['path'] ?? null) ? $track['path'] : null;
            $kind = $path === null || $this->positiveInteger($track['embedded_track_id'] ?? null) !== null
                ? 'embedded'
                : 'external';
            $displayName = $kind === 'external'
                ? $this->safeBasename($path, Str::upper($language).' subtitle')
                : Str::upper($language).' embedded track';

            $normalizedTracks[] = [
                'fingerprint' => $this->trackFingerprint($mediaType, $mediaId, $track),
                'display_name' => $displayName,
                'language' => $language,
                'kind' => $kind,
                'forced' => ($track['forced'] ?? false) === true,
                'hearing_impaired' => ($track['hi'] ?? $track['hearing_impaired'] ?? false) === true,
            ];
        }

        return $normalizedTracks;
    }

    /**
     * @param  list<string>  $requiredLanguages
     * @param  list<array{language: string}>  $tracks
     * @return list<string>
     */
    private function missingLanguages(array $requiredLanguages, array $tracks): array
    {
        $installedLanguages = array_column($tracks, 'language');

        return array_values(array_diff($requiredLanguages, $installedLanguages));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $items, array $filters): array
    {
        $mediaType = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        $scope = is_string($filters['scope'] ?? null) ? $filters['scope'] : null;
        $missingOnly = ($filters['missing_only'] ?? false) === true;

        return array_values(array_filter($items, static function (array $item) use ($mediaType, $scope, $missingOnly): bool {
            if ($mediaType !== null && ($item['media_type'] ?? null) !== $mediaType) {
                return false;
            }

            if ($scope !== null && ($item['scope'] ?? null) !== $scope) {
                return false;
            }

            return ! $missingOnly || ($item['missing_languages'] ?? []) !== [];
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyHistoryFilters(array $items, array $filters): array
    {
        $mediaType = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        $provider = is_string($filters['provider'] ?? null)
            ? Str::of($filters['provider'])->trim()->lower()->toString()
            : null;

        return array_values(array_filter($items, static function (array $item) use ($mediaType, $provider): bool {
            if ($mediaType !== null && ($item['media_type'] ?? null) !== $mediaType) {
                return false;
            }

            return $provider === null || Str::lower((string) ($item['provider'] ?? '')) === $provider;
        }));
    }

    private function bazarrClient(ServiceConnection $serviceConnection): BazarrClient
    {
        throw_unless($serviceConnection->type === ServiceType::Bazarr, InvalidArgumentException::class, 'Subtitle inventory requires a Bazarr connection.');

        $client = $this->serviceClientFactory->make($serviceConnection);

        throw_unless($client instanceof BazarrClient, InvalidArgumentException::class, 'Subtitle inventory requires a Bazarr client.');

        return $client;
    }

    private function activeMappedConnection(
        ServiceConnection $serviceConnection,
        BazarrServiceRole $bazarrServiceRole,
    ): ?ServiceConnection {
        $connection = $serviceConnection->mappedConnection($bazarrServiceRole);

        if (!$connection instanceof ServiceConnection
            || ! $connection->is_active
            || $connection->type !== $bazarrServiceRole->serviceType()) {
            return null;
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $episode
     */
    private function episodeTitle(array $series, array $episode): string
    {
        $seriesTitle = $this->safeText($series['title'] ?? null, 'Series');
        $episodeTitle = $this->safeText($episode['title'] ?? $episode['episodeTitle'] ?? null, 'Episode');

        return $seriesTitle.' — '.$episodeTitle;
    }

    private function safeText(mixed $value, string $fallback): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return $fallback;
        }

        $value = Str::of($value)->squish()->limit(250)->toString();

        return $value === '' ? $fallback : $value;
    }

    private function safeBasename(?string $path, string $fallback): string
    {
        if ($path === null || ! mb_check_encoding($path, 'UTF-8')) {
            return $fallback;
        }

        $basename = Str::afterLast(str_replace('\\', '/', $path), '/');

        return $this->safeText($basename, $fallback);
    }

    private function safeProvider(mixed $provider): ?string
    {
        if (! is_string($provider)) {
            return null;
        }

        $provider = $this->safeText($provider, '');

        if ($provider === '' || Str::startsWith($provider, ['http://', 'https://'])) {
            return null;
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($values, $key);

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $track
     *
     * @throws JsonException
     */
    private function trackFingerprint(string $mediaType, int $mediaId, array $track): string
    {
        $payload = json_encode([
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'path' => is_string($track['path'] ?? null) ? $track['path'] : null,
            'language' => $this->firstString($track, ['code3', 'code2', 'language', 'name']),
            'forced' => ($track['forced'] ?? false) === true,
            'hearing_impaired' => ($track['hi'] ?? $track['hearing_impaired'] ?? false) === true,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function validatePagination(int $page, int $perPage): void
    {
        throw_if($page <= 0, InvalidArgumentException::class, 'Page must be positive.');
        throw_if($perPage <= 0 || $perPage > self::MAX_PER_PAGE, InvalidArgumentException::class, 'Per page must be between 1 and 100.');
    }
}
