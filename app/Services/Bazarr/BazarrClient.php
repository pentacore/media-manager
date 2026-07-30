<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Cache\Services\BazarrCache;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * Client for Bazarr's authenticated HTTP API.
 *
 * @see https://github.com/morpheus65535/bazarr/tree/v1.6.0/bazarr/api
 */
final class BazarrClient
{
    private ?BazarrCache $bazarrCache = null;

    public function __construct(private readonly ServiceConnection $serviceConnection)
    {
        throw_if($this->serviceConnection->type !== ServiceType::Bazarr, InvalidArgumentException::class, 'BazarrClient requires a Bazarr service connection.');
        throw_if(! is_int($this->serviceConnection->id) || $this->serviceConnection->id <= 0, InvalidArgumentException::class, 'BazarrClient requires a persisted service connection with a positive ID.');
    }

    /**
     * @return array{data: array<string, mixed>, version: string}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getSystemStatus(): array
    {
        return $this->cache()->rememberMetadata('system-status', fn (): array => $this->fetchSystemStatus());
    }

    /**
     * @return array{data: array<string, mixed>, version: string}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getFreshSystemStatus(): array
    {
        return $this->fetchSystemStatus();
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getHealth(): array
    {
        return $this->cache()->rememberList(
            'health',
            fn (): array => $this->dataListEnvelope($this->buildClient()->get('/api/system/health')),
        );
    }

    /**
     * @param  list<int>  $seriesIds
     * @param  list<int>  $episodeIds
     * @return array{data: list<array<string, mixed>>}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getEpisodes(array $seriesIds = [], array $episodeIds = []): array
    {
        $normalizedSeriesIds = $this->normalizeIds($seriesIds);
        $normalizedEpisodeIds = $this->normalizeIds($episodeIds);

        throw_if(($normalizedSeriesIds === []) === ($normalizedEpisodeIds === []), InvalidArgumentException::class, 'Bazarr episodes require exactly one non-empty series or episode ID set.');

        $identifier = $normalizedSeriesIds !== [] ? 'seriesid' : 'episodeid';
        $identifiers = $normalizedSeriesIds !== [] ? $normalizedSeriesIds : $normalizedEpisodeIds;
        $cacheKey = sprintf('episodes:%s:%s', $identifier, implode(',', $identifiers));

        return $this->cache()->rememberList(
            $cacheKey,
            fn (): array => $this->dataListEnvelope(
                $this->buildClient()->get('/api/episodes?'.$this->repeatableQuery($identifier, $identifiers)),
            ),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getMovies(int $start = 0, int $length = 50): array
    {
        $this->validatePagination($start, $length);

        return $this->cache()->rememberList(
            sprintf('movies:start:%d:length:%d', $start, $length),
            fn (): array => $this->pageEnvelope(
                $this->buildClient()->get('/api/movies', ['start' => $start, 'length' => $length]),
            ),
        );
    }

    /**
     * Locate a single movie by its Radarr ID, walking the paginated movie
     * library ({data, total} envelope, length 100) until it is found or the
     * catalogue is exhausted. A bounded single-page lookup would make any movie
     * past offset 99 permanently un-actionable.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function findMovieByRadarrId(int $radarrId): ?array
    {
        $this->validatePositiveId($radarrId, 'Radarr ID');

        $start = 0;

        do {
            $page = $this->getMovies(start: $start, length: 100);
            $batch = $page['data'];

            foreach ($batch as $movie) {
                if (($movie['radarrId'] ?? null) === $radarrId) {
                    return $movie;
                }
            }

            $start += count($batch);
        } while ($batch !== [] && $start < $page['total']);

        return null;
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getWantedEpisodes(int $start = 0, int $length = 50): array
    {
        $this->validatePagination($start, $length);

        return $this->cache()->rememberList(
            sprintf('wanted-episodes:start:%d:length:%d', $start, $length),
            fn (): array => $this->pageEnvelope(
                $this->buildClient()->get('/api/episodes/wanted', ['start' => $start, 'length' => $length]),
            ),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getWantedMovies(int $start = 0, int $length = 50): array
    {
        $this->validatePagination($start, $length);

        return $this->cache()->rememberList(
            sprintf('wanted-movies:start:%d:length:%d', $start, $length),
            fn (): array => $this->pageEnvelope(
                $this->buildClient()->get('/api/movies/wanted', ['start' => $start, 'length' => $length]),
            ),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getEpisodeHistory(int $start = 0, int $length = 50, ?int $episodeId = null): array
    {
        $this->validatePagination($start, $length);
        $this->validateOptionalId($episodeId, 'episode ID');

        $query = ['start' => $start, 'length' => $length];

        if ($episodeId !== null) {
            $query['episodeid'] = $episodeId;
        }

        return $this->cache()->rememberList(
            sprintf('episode-history:start:%d:length:%d:episode:%s', $start, $length, $episodeId ?? 'all'),
            fn (): array => $this->pageEnvelope(
                $this->buildClient()->get('/api/episodes/history', $query),
            ),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getMovieHistory(int $start = 0, int $length = 50, ?int $radarrId = null): array
    {
        $this->validatePagination($start, $length);
        $this->validateOptionalId($radarrId, 'Radarr ID');

        $query = ['start' => $start, 'length' => $length];

        if ($radarrId !== null) {
            $query['radarrid'] = $radarrId;
        }

        return $this->cache()->rememberList(
            sprintf('movie-history:start:%d:length:%d:movie:%s', $start, $length, $radarrId ?? 'all'),
            fn (): array => $this->pageEnvelope(
                $this->buildClient()->get('/api/movies/history', $query),
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchEpisode(int $episodeId): array
    {
        $this->validatePositiveId($episodeId, 'episode ID');

        return $this->sanitizedCandidates('episode', $episodeId, $this->rawEpisodeCandidates($episodeId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchMovie(int $radarrId): array
    {
        $this->validatePositiveId($radarrId, 'Radarr ID');

        return $this->sanitizedCandidates('movie', $radarrId, $this->rawMovieCandidates($radarrId));
    }

    public function downloadBestEpisode(
        int $episodeId,
        string $language,
        bool $forced,
        bool $hearingImpaired,
    ): void {
        $this->validatePositiveId($episodeId, 'episode ID');
        $language = $this->validateLanguage($language);
        $episode = $this->getEpisodes(episodeIds: [$episodeId])['data'][0] ?? null;
        $seriesId = is_array($episode) ? ($episode['sonarrSeriesId'] ?? null) : null;

        throw_unless(is_int($seriesId) && $seriesId > 0, UnexpectedValueException::class, 'Bazarr episode is missing its Sonarr series ID.');

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->patch('/api/episodes/subtitles', [
                    'seriesid' => $seriesId,
                    'episodeid' => $episodeId,
                    'language' => $language,
                    'forced' => $this->lowerBoolean($forced),
                    'hi' => $this->lowerBoolean($hearingImpaired),
                ]),
        );
    }

    public function downloadBestMovie(
        int $radarrId,
        string $language,
        bool $forced,
        bool $hearingImpaired,
    ): void {
        $this->validatePositiveId($radarrId, 'Radarr ID');
        $language = $this->validateLanguage($language);

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->patch('/api/movies/subtitles', [
                    'radarrid' => $radarrId,
                    'language' => $language,
                    'forced' => $this->lowerBoolean($forced),
                    'hi' => $this->lowerBoolean($hearingImpaired),
                ]),
        );
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function downloadExactEpisode(array $selection): void
    {
        $seriesId = $this->positiveSelectionId($selection, 'series_id');
        $episodeId = $this->positiveSelectionId($selection, 'episode_id');
        $candidate = $this->resolveExactCandidate(
            'episode',
            $episodeId,
            $this->selectionString($selection, 'fingerprint'),
            $this->rawEpisodeCandidates($episodeId),
        );

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->post('/api/providers/episodes', [
                    'seriesid' => $seriesId,
                    'episodeid' => $episodeId,
                    'hi' => $this->titleBoolean($this->candidateFlag($candidate['hearing_impaired'] ?? false)),
                    'forced' => $this->titleBoolean($this->candidateFlag($candidate['forced'] ?? false)),
                    'original_format' => $this->titleBoolean($this->candidateFlag($candidate['original_format'] ?? false)),
                    'provider' => $this->candidateString($candidate, 'provider'),
                    'subtitle' => $this->candidateString($candidate, 'subtitle'),
                ]),
        );
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function downloadExactMovie(array $selection): void
    {
        $radarrId = $this->positiveSelectionId($selection, 'radarr_id');
        $candidate = $this->resolveExactCandidate(
            'movie',
            $radarrId,
            $this->selectionString($selection, 'fingerprint'),
            $this->rawMovieCandidates($radarrId),
        );

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->post('/api/providers/movies', [
                    'radarrid' => $radarrId,
                    'hi' => $this->titleBoolean($this->candidateFlag($candidate['hearing_impaired'] ?? false)),
                    'forced' => $this->titleBoolean($this->candidateFlag($candidate['forced'] ?? false)),
                    'original_format' => $this->titleBoolean($this->candidateFlag($candidate['original_format'] ?? false)),
                    'provider' => $this->candidateString($candidate, 'provider'),
                    'subtitle' => $this->candidateString($candidate, 'subtitle'),
                ]),
        );
    }

    public function uploadEpisode(
        int $seriesId,
        int $episodeId,
        string $language,
        bool $forced,
        bool $hearingImpaired,
        UploadedFile $uploadedFile,
    ): void {
        $this->validatePositiveId($seriesId, 'series ID');
        $this->validatePositiveId($episodeId, 'episode ID');
        $language = $this->validateLanguage($language);

        $this->write(
            $this->buildClient(withRetry: false)
                ->attach('file', $uploadedFile->get(), $uploadedFile->getClientOriginalName())
                ->post('/api/episodes/subtitles', [
                    'seriesid' => $seriesId,
                    'episodeid' => $episodeId,
                    'language' => $language,
                    'forced' => $this->lowerBoolean($forced),
                    'hi' => $this->lowerBoolean($hearingImpaired),
                ]),
        );
    }

    public function uploadMovie(
        int $radarrId,
        string $language,
        bool $forced,
        bool $hearingImpaired,
        UploadedFile $uploadedFile,
    ): void {
        $this->validatePositiveId($radarrId, 'Radarr ID');
        $language = $this->validateLanguage($language);

        $this->write(
            $this->buildClient(withRetry: false)
                ->attach('file', $uploadedFile->get(), $uploadedFile->getClientOriginalName())
                ->post('/api/movies/subtitles', [
                    'radarrid' => $radarrId,
                    'language' => $language,
                    'forced' => $this->lowerBoolean($forced),
                    'hi' => $this->lowerBoolean($hearingImpaired),
                ]),
        );
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function deleteEpisodeSubtitle(array $selection): void
    {
        $this->deleteSubtitle('/api/episodes/subtitles', [
            'seriesid' => $this->positiveSelectionId($selection, 'series_id'),
            'episodeid' => $this->positiveSelectionId($selection, 'episode_id'),
            ...$this->subtitleSelection($selection),
        ]);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function deleteMovieSubtitle(array $selection): void
    {
        $this->deleteSubtitle('/api/movies/subtitles', [
            'radarrid' => $this->positiveSelectionId($selection, 'radarr_id'),
            ...$this->subtitleSelection($selection),
        ]);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function applySubtitleTool(array $selection): void
    {
        $action = $this->selectionString($selection, 'action');
        throw_unless(preg_match('/^[a-z0-9_-]{1,64}$/D', $action) === 1, InvalidArgumentException::class, 'Bazarr subtitle action is invalid.');

        $mediaType = $this->selectionString($selection, 'media_type');
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Bazarr media type must be episode or movie.');

        $payload = [
            'action' => $action,
            'language' => $this->validateLanguage($this->selectionString($selection, 'language')),
            'path' => $this->selectionString($selection, 'path'),
            'type' => $mediaType,
            'id' => $this->positiveSelectionId($selection, 'media_id'),
            'forced' => $this->titleBoolean(($selection['forced'] ?? false) === true),
            'hi' => $this->titleBoolean(($selection['hearing_impaired'] ?? false) === true),
        ];

        foreach (['reference', 'max_offset_seconds', 'no_fix_framerate', 'gss', 'original_format'] as $optionalField) {
            if (isset($selection[$optionalField]) && is_scalar($selection[$optionalField])) {
                $payload[$optionalField] = (string) $selection[$optionalField];
            }
        }

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->patch('/api/subtitles', $payload),
        );
    }

    public function runTask(string $taskId): void
    {
        throw_unless(preg_match('/^[a-zA-Z0-9_.:-]{1,150}$/D', $taskId) === 1, InvalidArgumentException::class, 'Bazarr task ID is invalid.');

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->post('/api/system/tasks', ['taskid' => $taskId]),
        );
    }

    public function runMediaAction(string $mediaType, int $id, string $action): void
    {
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Bazarr media type must be episode or movie.');
        throw_unless(in_array($action, ['scan-disk', 'search-missing', 'search-wanted', 'sync'], true), InvalidArgumentException::class, 'Bazarr media action is invalid.');
        $this->validatePositiveId($id, 'media ID');
        $isEpisode = $mediaType === 'episode';

        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->patch($isEpisode ? '/api/series' : '/api/movies', [
                    $isEpisode ? 'seriesid' : 'radarrid' => $id,
                    'action' => $action,
                ]),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getProviders(): array
    {
        return $this->cache()->rememberList(
            'providers',
            fn (): array => $this->dataListEnvelope($this->buildClient()->get('/api/providers')),
        );
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getTasks(): array
    {
        return $this->cache()->rememberList(
            'tasks',
            fn (): array => $this->dataListEnvelope($this->buildClient()->get('/api/system/tasks')),
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getLanguageProfiles(): array
    {
        return $this->cache()->rememberMetadata('language-profiles', function (): array {
            $payload = $this->decodedJson($this->buildClient()->get('/api/system/languages/profiles'));

            throw_if(! is_array($payload) || ! $this->containsOnlyObjects($payload), UnexpectedValueException::class, 'Bazarr language profiles response must be a list of objects.');

            return $this->normalizeJson($payload);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->cache()->rememberMetadata(
            'settings',
            fn (): array => $this->dataEnvelope($this->buildClient()->get('/api/system/settings'))['data'],
        );
    }

    public function effectiveMinimumScore(string $mediaType): ?int
    {
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Bazarr score media type is invalid.');

        $value = $this->getSettings()[$mediaType === 'episode' ? 'minimum_score' : 'minimum_score_movie'] ?? null;

        return is_int($value) && $value >= 0 && $value <= 100 ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getNotifications(): array
    {
        return $this->cache()->rememberMetadata(
            'notifications',
            fn (): array => $this->dataListEnvelope($this->buildClient()->get('/api/system/notifications'))['data'],
        );
    }

    /**
     * @param  array<string, bool|int|string>  $settings
     */
    public function updateSettings(array $settings): void
    {
        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->post('/api/system/settings', $settings),
        );

        $this->cache()->bustAll();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    public function getOpenApi(): array
    {
        return $this->cache()->rememberMetadata('openapi', function (): array {
            $payload = $this->decodedJson($this->buildClient()->get('/api/swagger.json'));

            throw_if(! $payload instanceof stdClass
                || ! is_string($payload->swagger ?? null)
                || ! ($payload->info ?? null) instanceof stdClass
                || ! ($payload->paths ?? null) instanceof stdClass, UnexpectedValueException::class, 'Bazarr OpenAPI response is not a valid Swagger object.');

            return $this->normalizeJson($payload);
        });
    }

    /**
     * @return array<string, bool>
     */
    public function getCapabilities(): array
    {
        return $this->cache()->rememberMetadata('capabilities', function (): array {
            $bazarrCapabilityRegistry = new BazarrCapabilityRegistry;

            try {
                return $bazarrCapabilityRegistry->detect($this->getOpenApi());
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                return $this->fallbackCapabilities($bazarrCapabilityRegistry);
            }
        });
    }

    private function buildClient(bool $withRetry = true): PendingRequest
    {
        $pendingRequest = Http::baseUrl(rtrim((string) $this->serviceConnection->url, '/'))
            ->withHeaders(['X-API-KEY' => $this->serviceConnection->api_key])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(15)
            ->withUserAgent('MediaManager/'.config('app.version').' BazarrClient');

        if (! $withRetry) {
            return $pendingRequest;
        }

        return $pendingRequest->retry(
            [250, 750],
            when: fn (Throwable $throwable): bool => $throwable instanceof ConnectionException
                || ($throwable instanceof RequestException && $throwable->response->serverError()),
            throw: false,
        );
    }

    /**
     * @return array{data: array<string, mixed>, version: string}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function fetchSystemStatus(): array
    {
        $payload = $this->dataEnvelope(
            $this->buildClient()->get('/api/system/status'),
        );
        $version = $payload['data']['bazarr_version'] ?? null;

        throw_if(! is_string($version) || $version === '', UnexpectedValueException::class, 'Bazarr system status response is missing a valid version.');

        $payload['version'] = $version;

        return $payload;
    }

    /**
     * @return array{data: array<array-key, mixed>}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function dataEnvelope(Response $response): array
    {
        $payload = $this->decodedJson($response);

        throw_if(! $payload instanceof stdClass || ! ($payload->data ?? null) instanceof stdClass, UnexpectedValueException::class, 'Bazarr response must contain an object data envelope.');

        return $this->normalizeJson($payload);
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function dataListEnvelope(Response $response): array
    {
        $payload = $this->decodedJson($response);

        throw_if(! $payload instanceof stdClass
            || ! is_array($payload->data ?? null)
            || ! $this->containsOnlyObjects($payload->data), UnexpectedValueException::class, 'Bazarr data envelope must contain a list of objects.');

        return $this->normalizeJson($payload);
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function pageEnvelope(Response $response): array
    {
        $payload = $this->decodedJson($response);

        throw_if(! $payload instanceof stdClass
            || ! is_array($payload->data ?? null)
            || ! $this->containsOnlyObjects($payload->data)
            || ! is_int($payload->total ?? null)
            || $payload->total < 0, UnexpectedValueException::class, 'Bazarr page response must contain an object list and non-negative integer total.');

        return $this->normalizeJson($payload);
    }

    /**
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function decodedJson(Response $response): stdClass|array
    {
        $response->throw();

        try {
            $payload = json_decode($response->body(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnexpectedValueException('Bazarr returned malformed JSON.', $jsonException->getCode(), previous: $jsonException);
        }

        throw_unless($payload instanceof stdClass || is_array($payload), UnexpectedValueException::class, 'Bazarr JSON response must be an object or array.');

        return $payload;
    }

    private function validatePagination(int $start, int $length): void
    {
        throw_if($start < 0, InvalidArgumentException::class, 'Bazarr pagination start must be zero or greater.');
        throw_if($length <= 0, InvalidArgumentException::class, 'Bazarr pagination length must be greater than zero.');
    }

    private function validateOptionalId(?int $id, string $name): void
    {
        throw_if($id !== null && $id <= 0, InvalidArgumentException::class, sprintf('Bazarr %s must be positive when provided.', $name));
    }

    private function validatePositiveId(int $id, string $name): void
    {
        throw_if($id <= 0, InvalidArgumentException::class, sprintf('Bazarr %s must be positive.', $name));
    }

    private function validateLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        throw_unless(preg_match('/^[a-z]{2,3}(?:-[a-z0-9]+)?$/D', $language) === 1, InvalidArgumentException::class, 'Bazarr language is invalid.');

        return $language;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawEpisodeCandidates(int $episodeId): array
    {
        return $this->dataListEnvelope(
            $this->buildClient()->get('/api/providers/episodes', ['episodeid' => $episodeId]),
        )['data'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawMovieCandidates(int $radarrId): array
    {
        return $this->dataListEnvelope(
            $this->buildClient()->get('/api/providers/movies', ['radarrid' => $radarrId]),
        )['data'];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function sanitizedCandidates(string $mediaType, int $mediaId, array $candidates): array
    {
        $sanitized = [];

        foreach ($candidates as $candidate) {
            $identity = $this->candidateIdentity($mediaType, $mediaId, $candidate);

            if ($identity === null) {
                continue;
            }

            $sanitized[] = [
                'fingerprint' => new BazarrCandidateFingerprint()->make($identity),
                'provider' => $identity['provider'],
                'language' => $identity['language'],
                'forced' => $identity['forced'],
                'hearing_impaired' => $identity['hearing_impaired'],
                'score' => $identity['score'],
                'release_info' => $identity['release_info'],
                'original_format' => $this->candidateFlag($candidate['original_format'] ?? false),
                'uploader' => $this->safeCandidateText($candidate['uploader'] ?? null),
            ];

            if (count($sanitized) === 25) {
                break;
            }
        }

        return $sanitized;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function resolveExactCandidate(
        string $mediaType,
        int $mediaId,
        string $fingerprint,
        array $candidates,
    ): array {
        foreach ($candidates as $candidate) {
            $identity = $this->candidateIdentity($mediaType, $mediaId, $candidate);

            if ($identity !== null && new BazarrCandidateFingerprint()->verify($identity, $fingerprint)) {
                return $candidate;
            }
        }

        throw new UnexpectedValueException('The selected Bazarr subtitle candidate is no longer available.');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function candidateIdentity(string $mediaType, int $mediaId, array $candidate): ?array
    {
        $provider = $this->safeCandidateText($candidate['provider'] ?? null);
        $subtitle = $this->safeCandidateText($candidate['subtitle'] ?? null, 2_000);
        $language = $this->safeCandidateText($candidate['language'] ?? null, 20);

        if ($provider === null || $subtitle === null || $language === null) {
            return null;
        }

        $releaseInfo = $candidate['release_info'] ?? [];
        if (is_string($releaseInfo)) {
            $releaseInfo = [$releaseInfo];
        }

        $releaseInfo = is_array($releaseInfo)
            ? array_values(array_filter(array_map(
                fn (mixed $release): ?string => $this->safeCandidateText($release, 250),
                array_slice($releaseInfo, 0, 10),
            )))
            : [];

        return [
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'provider' => $provider,
            'subtitle' => $subtitle,
            'language' => strtolower($language),
            'forced' => $this->candidateFlag($candidate['forced'] ?? false),
            'hearing_impaired' => $this->candidateFlag($candidate['hearing_impaired'] ?? false),
            'score' => is_numeric($candidate['score'] ?? null) ? (float) $candidate['score'] : null,
            'release_info' => $releaseInfo,
        ];
    }

    private function safeCandidateText(mixed $value, int $limit = 100): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function candidateFlag(mixed $value): bool
    {
        return $value === true
            || (is_string($value) && in_array(strtolower($value), ['true', '1', 'yes'], true))
            || (is_int($value) && $value === 1);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateString(array $candidate, string $field): string
    {
        $value = $this->safeCandidateText($candidate[$field] ?? null, 2_000);
        throw_if($value === null, UnexpectedValueException::class, sprintf('Bazarr candidate %s is missing.', $field));

        return $value;
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function positiveSelectionId(array $selection, string $field): int
    {
        $value = $selection[$field] ?? null;
        throw_unless(is_int($value) && $value > 0, InvalidArgumentException::class, sprintf('Bazarr selection %s must be a positive integer.', $field));

        return $value;
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function selectionString(array $selection, string $field): string
    {
        $value = $selection[$field] ?? null;
        throw_unless(is_string($value) && trim($value) !== '', InvalidArgumentException::class, sprintf('Bazarr selection %s must be a non-empty string.', $field));

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array{language: string, forced: string, hi: string, path: string}
     */
    private function subtitleSelection(array $selection): array
    {
        return [
            'language' => $this->validateLanguage($this->selectionString($selection, 'language')),
            'forced' => $this->lowerBoolean(($selection['forced'] ?? false) === true),
            'hi' => $this->lowerBoolean(($selection['hearing_impaired'] ?? false) === true),
            'path' => $this->selectionString($selection, 'path'),
        ];
    }

    /**
     * @param  array<string, int|string>  $payload
     */
    private function deleteSubtitle(string $endpoint, array $payload): void
    {
        $this->write(
            $this->buildClient(withRetry: false)
                ->asForm()
                ->delete($endpoint, $payload),
        );
    }

    private function write(Response $response): void
    {
        $response->throw();
    }

    private function lowerBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function titleBoolean(bool $value): string
    {
        return $value ? 'True' : 'False';
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => is_int($id) && $id > 0,
        )));
        sort($normalizedIds, SORT_NUMERIC);

        return $normalizedIds;
    }

    /**
     * @param  list<int>  $ids
     */
    private function repeatableQuery(string $identifier, array $ids): string
    {
        $key = rawurlencode($identifier.'[]');

        return implode('&', array_map(
            static fn (int $id): string => $key.'='.$id,
            $ids,
        ));
    }

    /**
     * @param  list<mixed>  $values
     */
    private function containsOnlyObjects(array $values): bool
    {
        return array_all($values, fn (mixed $value): bool => $value instanceof stdClass);
    }

    private function normalizeJson(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map($this->normalizeJson(...), $value);
    }

    private function cache(): BazarrCache
    {
        return $this->bazarrCache ??= new BazarrCache($this->serviceConnection);
    }

    /**
     * @return array<string, bool>
     */
    private function fallbackCapabilities(BazarrCapabilityRegistry $bazarrCapabilityRegistry): array
    {
        $capabilities = $bazarrCapabilityRegistry->unavailable();
        $wantedEpisodes = $this->safeReadProbe(fn (): array => $this->getWantedEpisodes(0, 1));
        $wantedMovies = $this->safeReadProbe(fn (): array => $this->getWantedMovies(0, 1));
        $episodeHistory = $this->safeReadProbe(fn (): array => $this->getEpisodeHistory(0, 1));
        $movieHistory = $this->safeReadProbe(fn (): array => $this->getMovieHistory(0, 1));

        $capabilities['wanted'] = $wantedEpisodes && $wantedMovies;
        $capabilities['history'] = $episodeHistory && $movieHistory;
        $capabilities['language_profiles'] = $this->safeReadProbe(fn (): array => $this->getLanguageProfiles());

        return $capabilities;
    }

    private function safeReadProbe(Closure $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            return false;
        }
    }
}
