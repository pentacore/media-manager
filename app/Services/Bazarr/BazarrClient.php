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
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * Read-only client for Bazarr's authenticated HTTP API.
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
        return $this->cache()->rememberMetadata('system-status', function (): array {
            $payload = $this->dataEnvelope(
                $this->buildClient()->get('/api/system/status'),
            );
            $version = $payload['data']['bazarr_version'] ?? null;

            throw_if(! is_string($version) || $version === '', UnexpectedValueException::class, 'Bazarr system status response is missing a valid version.');

            $payload['version'] = $version;

            return $payload;
        });
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
            $registry = new BazarrCapabilityRegistry;

            try {
                return $registry->detect($this->getOpenApi());
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                return $this->fallbackCapabilities($registry);
            }
        });
    }

    private function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->serviceConnection->url, '/'))
            ->withHeaders(['X-API-KEY' => $this->serviceConnection->api_key])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(15)
            ->withUserAgent('MediaManager/'.config('app.version').' BazarrClient')
            ->retry(
                [250, 750],
                when: fn (Throwable $throwable): bool => $throwable instanceof ConnectionException
                    || ($throwable instanceof RequestException && $throwable->response->serverError()),
                throw: false,
            );
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

        return array_map(fn (mixed $item): mixed => $this->normalizeJson($item), $value);
    }

    private function cache(): BazarrCache
    {
        return $this->bazarrCache ??= new BazarrCache($this->serviceConnection);
    }

    /**
     * @return array<string, bool>
     */
    private function fallbackCapabilities(BazarrCapabilityRegistry $registry): array
    {
        $capabilities = $registry->unavailable();
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
