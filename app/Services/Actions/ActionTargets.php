<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrClient;
use App\Services\Arr\ArrConnections;
use App\Services\Seerr\SeerrClient;
use App\Services\Whisparr\WhisparrClient;
use App\Services\Whisparr\WhisparrItemIndex;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves the display name and identifying facts of an action's target
 * server-side, so an approval card never trusts a name an LLM wrote. Connection
 * selection mirrors the executors (ServiceConnection::resolvePinned over the
 * action payload). A failed lookup never blocks queuing: it falls back to the
 * caller's name — unverified unless the caller vouches for it — or to
 * "#id (not found)".
 */
final readonly class ActionTargets
{
    public function __construct(
        private WhisparrItemIndex $whisparrItemIndex,
        private ArrConnections $arrConnections,
    ) {}

    /**
     * @param  array<string, mixed>  $pinContext
     * @param  bool  $strictPin  When true, a pin naming a different-type,
     *                           deleted or deactivated connection aborts
     *                           instead of silently falling back to the
     *                           active connection — for action types (like
     *                           monitor_episodes/monitor_season/search_media/grab_release)
     *                           whose executor resolves the same way via
     *                           {@see ServiceConnection::resolvePinnedStrict()}.
     *                           Pre-existing callers leave this false and
     *                           keep the original resolvePinned() fallback.
     */
    public function sonarrSeries(int $sonarrId, array $pinContext = [], ?string $fallbackName = null, bool $fallbackVerified = false, bool $strictPin = false): ActionTarget
    {
        if ($strictPin) {
            $serviceConnection = $this->resolvePinnedConnection(ServiceType::Sonarr, $pinContext);

            return $this->attempt('series', $sonarrId, $fallbackName, $fallbackVerified, fn (): ?ActionTarget => $this->sonarrSeriesTarget($sonarrId, $serviceConnection));
        }

        return $this->attempt('series', $sonarrId, $fallbackName, $fallbackVerified, fn (): ?ActionTarget => $this->sonarrSeriesTarget($sonarrId, ServiceConnection::resolvePinned($pinContext, ServiceType::Sonarr)));
    }

    /**
     * @param  array<string, mixed>  $pinContext
     * @param  bool  $strictPin  See {@see sonarrSeries()}.
     */
    public function radarrMovie(int $radarrId, array $pinContext = [], ?string $fallbackName = null, bool $fallbackVerified = false, bool $strictPin = false): ActionTarget
    {
        if ($strictPin) {
            $serviceConnection = $this->resolvePinnedConnection(ServiceType::Radarr, $pinContext);

            return $this->attempt('movie', $radarrId, $fallbackName, $fallbackVerified, fn (): ?ActionTarget => $this->radarrMovieTarget($radarrId, $serviceConnection));
        }

        return $this->attempt('movie', $radarrId, $fallbackName, $fallbackVerified, fn (): ?ActionTarget => $this->radarrMovieTarget($radarrId, ServiceConnection::resolvePinned($pinContext, ServiceType::Radarr)));
    }

    /**
     * @param  array<string, mixed>  $pinContext
     */
    public function sonarrLookup(int $tvdbId, array $pinContext = [], ?string $fallbackName = null): ActionTarget
    {
        return $this->attempt('series', $tvdbId, $fallbackName, false, function () use ($tvdbId, $pinContext): ?ActionTarget {
            $serviceConnection = ServiceConnection::resolvePinned($pinContext, ServiceType::Sonarr);
            $name = $this->nameFrom($this->arrConnections->sonarr($serviceConnection)->searchSeries(sprintf('tvdb:%d', $tvdbId))[0] ?? []);

            return $name === null ? null : new ActionTarget('series', $name, [
                ['label' => 'Series', 'value' => $name],
                ['label' => 'TVDB ID', 'value' => (string) $tvdbId],
                ['label' => 'Connection', 'value' => $serviceConnection->name],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $pinContext
     */
    public function radarrLookup(int $tmdbId, array $pinContext = [], ?string $fallbackName = null): ActionTarget
    {
        return $this->attempt('movie', $tmdbId, $fallbackName, false, function () use ($tmdbId, $pinContext): ?ActionTarget {
            $serviceConnection = ServiceConnection::resolvePinned($pinContext, ServiceType::Radarr);
            $name = $this->nameFrom($this->arrConnections->radarr($serviceConnection)->searchMovies(sprintf('tmdb:%d', $tmdbId))[0] ?? []);

            return $name === null ? null : new ActionTarget('movie', $name, [
                ['label' => 'Movie', 'value' => $name],
                ['label' => 'TMDB ID', 'value' => (string) $tmdbId],
                ['label' => 'Connection', 'value' => $serviceConnection->name],
            ]);
        });
    }

    /**
     * Every `whisparr_*` executor resolves its connection with
     * {@see ServiceConnection::resolvePinnedStrict()}, so the describer
     * does too: a missing, wrong-type, deleted or deactivated pin aborts
     * (UNDESCRIBABLE) instead of describing the active instance's item
     * for an action that could never run.
     *
     * @param  array<string, mixed>  $pinContext
     *
     * @throws ModelNotFoundException|InvalidArgumentException
     */
    public function whisparrItem(int $itemId, array $pinContext = [], ?string $fallbackName = null): ActionTarget
    {
        $serviceConnection = ServiceConnection::resolvePinnedStrict($pinContext, ServiceType::Whisparr);

        return $this->attempt('item', $itemId, $fallbackName, false, fn (): ?ActionTarget => $this->whisparrItemTarget($itemId, $serviceConnection));
    }

    /**
     * Strict like {@see whisparrItem()}: the whisparr_add_item executor
     * resolves its pin strictly.
     *
     * @param  array<string, mixed>  $pinContext
     *
     * @throws ModelNotFoundException|InvalidArgumentException
     */
    public function whisparrLookup(int $tmdbId, array $pinContext = [], ?string $fallbackName = null): ActionTarget
    {
        $serviceConnection = ServiceConnection::resolvePinnedStrict($pinContext, ServiceType::Whisparr);

        return $this->attempt('item', $tmdbId, $fallbackName, false, fn (): ?ActionTarget => $this->whisparrLookupTarget($tmdbId, $serviceConnection));
    }

    /**
     * @param  array<string, mixed>  $pinContext
     */
    public function seerrRequest(int $requestId, array $pinContext = [], ?string $fallbackName = null): ActionTarget
    {
        return $this->attempt('request', $requestId, $fallbackName, false, function () use ($requestId, $pinContext): ?ActionTarget {
            $seerrClient = new SeerrClient(ServiceConnection::resolvePinned($pinContext, ServiceType::Seerr));
            $request = $seerrClient->getRequestById($requestId);
            $mediaType = (string) ($request['type'] ?? $request['media']['mediaType'] ?? '');
            $tmdbId = (int) ($request['media']['tmdbId'] ?? 0);

            if ($tmdbId <= 0 || ! in_array($mediaType, ['movie', 'tv'], true)) {
                return null;
            }

            $media = $mediaType === 'movie' ? $seerrClient->getMovieDetails($tmdbId) : $seerrClient->getTvDetails($tmdbId);
            $title = $media['title'] ?? $media['name'] ?? null;
            $date = $media['releaseDate'] ?? $media['firstAirDate'] ?? null;

            if (! is_string($title) || $title === '') {
                return null;
            }

            $name = $this->withYear($title, is_string($date) && strlen($date) >= 4 ? (int) substr($date, 0, 4) : null);
            $requester = $request['requestedBy']['displayName'] ?? null;

            return new ActionTarget('request', $name, array_values(array_filter([
                ['label' => 'Title', 'value' => $name],
                ['label' => 'Media type', 'value' => $mediaType === 'movie' ? 'Movie' : 'TV'],
                is_string($requester) ? ['label' => 'Requested by', 'value' => $requester] : null,
                ['label' => 'Request ID', 'value' => (string) $requestId],
            ])));
        });
    }

    /**
     * @param  array<string, mixed>  $pinContext
     */
    public function download(ServiceType $serviceType, string $downloadId, array $pinContext = [], ?string $fallbackName = null): ActionTarget
    {
        return $this->attempt('download', $downloadId, $fallbackName, false, function () use ($serviceType, $downloadId, $pinContext): ?ActionTarget {
            $serviceConnection = ServiceConnection::resolvePinned($pinContext, $serviceType);
            $params = $serviceType === ServiceType::Sonarr
                ? ['page' => 1, 'pageSize' => 200, 'includeUnknownSeriesItems' => 'true', 'includeSeries' => 'true']
                : ['page' => 1, 'pageSize' => 200, 'includeUnknownMovieItems' => 'true', 'includeMovie' => 'true'];
            $records = $this->arrClient($serviceType, $serviceConnection)->getQueue($params)['records'] ?? [];

            foreach (is_array($records) ? $records : [] as $record) {
                if (($record['downloadId'] ?? null) !== $downloadId || ! is_string($record['title'] ?? null)) {
                    continue;
                }

                $media = $record['series']['title'] ?? $record['movie']['title'] ?? null;
                $client = $record['downloadClient'] ?? null;

                return new ActionTarget('download', $record['title'], array_values(array_filter([
                    ['label' => 'Release', 'value' => $record['title']],
                    is_string($media) ? ['label' => 'Media', 'value' => $media] : null,
                    is_string($client) ? ['label' => 'Download client', 'value' => $client] : null,
                    ['label' => 'Download ID', 'value' => $downloadId],
                ])));
            }

            return null;
        });
    }

    /**
     * Resolves the active Emby connection, like EmbyActions does when the scan
     * runs; a pinned connection id is deliberately ignored here.
     */
    public function embyLibrary(): ActionTarget
    {
        return $this->attempt('library', 'emby', 'Emby', true, function (): ActionTarget {
            $serviceConnection = ServiceConnection::resolveActive(ServiceType::Emby);

            return new ActionTarget('library', $serviceConnection->name, [
                ['label' => 'Emby server', 'value' => $serviceConnection->name],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $pinContext
     */
    public function qualityProfileName(ServiceType $serviceType, int $profileId, array $pinContext = []): ?string
    {
        try {
            $connection = ServiceConnection::resolvePinned($pinContext, $serviceType);

            foreach ($this->arrClient($serviceType, $connection)->getQualityProfiles() as $profile) {
                if ((int) ($profile['id'] ?? 0) === $profileId && is_string($profile['name'] ?? null)) {
                    return $profile['name'];
                }
            }
        } catch (Throwable $throwable) {
            Log::info('ActionTargets: quality profile lookup failed', [
                'service' => $serviceType->value,
                'profile_id' => $profileId,
                'exception' => $throwable::class,
            ]);
        }

        return null;
    }

    /**
     * The Sonarr/Radarr server itself, for library-wide actions. Resolved
     * strictly when the payload pins a connection — a pin naming a
     * different-type or deleted/deactivated connection aborts rather than
     * silently falling back to another instance, matching the executor's
     * `resolvePinnedStrict()`. Without a pin, falls back to the active
     * connection like every other target here.
     *
     * @param  array<string, mixed>  $pinContext
     *
     * @throws ModelNotFoundException|InvalidArgumentException
     */
    public function arrConnection(ServiceType $serviceType, array $pinContext = []): ActionTarget
    {
        $serviceConnection = $this->resolvePinnedConnection($serviceType, $pinContext);

        return new ActionTarget(sprintf('%s server', ucfirst($serviceType->value)), $serviceConnection->name, [
            ['label' => 'Connection', 'value' => $serviceConnection->name],
        ]);
    }

    private function sonarrSeriesTarget(int $sonarrId, ServiceConnection $serviceConnection): ?ActionTarget
    {
        $indexed = IndexedSeries::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('sonarr_id', $sonarrId)
            ->first(['title', 'year']);

        $name = $indexed instanceof IndexedSeries
            ? $this->withYear($indexed->title, $indexed->year)
            : $this->nameFrom($this->arrConnections->sonarr($serviceConnection)->getSeriesById($sonarrId));

        return $name === null ? null : new ActionTarget('series', $name, [
            ['label' => 'Series', 'value' => $name],
            ['label' => 'Sonarr ID', 'value' => (string) $sonarrId],
            ['label' => 'Connection', 'value' => $serviceConnection->name],
        ]);
    }

    private function radarrMovieTarget(int $radarrId, ServiceConnection $serviceConnection): ?ActionTarget
    {
        $indexed = IndexedMovie::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('radarr_id', $radarrId)
            ->first(['title', 'year']);

        $name = $indexed instanceof IndexedMovie
            ? $this->withYear($indexed->title, $indexed->year)
            : $this->nameFrom($this->arrConnections->radarr($serviceConnection)->getMovieById($radarrId));

        return $name === null ? null : new ActionTarget('movie', $name, [
            ['label' => 'Movie', 'value' => $name],
            ['label' => 'Radarr ID', 'value' => (string) $radarrId],
            ['label' => 'Connection', 'value' => $serviceConnection->name],
        ]);
    }

    /**
     * Whisparr has no local index, so the name comes from the cached library
     * list the Whisparr page uses, read once per request through
     * WhisparrItemIndex: a 100-title bulk describes without 100 list reads or
     * 100 live lookups. Only an item missing from that list (added since it
     * was cached) is read by id.
     */
    private function whisparrItemTarget(int $itemId, ServiceConnection $serviceConnection): ?ActionTarget
    {
        $name = $this->nameFrom($this->whisparrItemIndex->find($serviceConnection, $itemId) ?? new WhisparrClient($serviceConnection)->getItemById($itemId));

        return $name === null ? null : new ActionTarget('item', $name, [
            ['label' => 'Item', 'value' => $name],
            ['label' => 'Whisparr ID', 'value' => (string) $itemId],
            ['label' => 'Connection', 'value' => $serviceConnection->name],
        ]);
    }

    private function whisparrLookupTarget(int $tmdbId, ServiceConnection $serviceConnection): ?ActionTarget
    {
        $name = $this->nameFrom(new WhisparrClient($serviceConnection)->searchItems(sprintf('tmdb:%d', $tmdbId))[0] ?? []);

        return $name === null ? null : new ActionTarget('item', $name, [
            ['label' => 'Item', 'value' => $name],
            ['label' => 'TMDB ID', 'value' => (string) $tmdbId],
            ['label' => 'Connection', 'value' => $serviceConnection->name],
        ]);
    }

    /**
     * The shared "pin present → resolvePinnedStrict(), otherwise
     * resolveActive()" policy used by arrConnection() and every
     * strict-pin target lookup: a pin naming a different-type, deleted
     * or deactivated connection aborts rather than silently falling
     * back to another instance.
     *
     * @param  array<string, mixed>  $pinContext
     *
     * @throws ModelNotFoundException|InvalidArgumentException
     */
    private function resolvePinnedConnection(ServiceType $serviceType, array $pinContext): ServiceConnection
    {
        $connectionId = (int) ($pinContext['service_connection_id'] ?? 0);

        return $connectionId > 0
            ? ServiceConnection::resolvePinnedStrict($pinContext, $serviceType)
            : ServiceConnection::resolveActive($serviceType);
    }

    /**
     * @param  Closure(): ?ActionTarget  $resolve
     */
    private function attempt(string $noun, int|string $identifier, ?string $fallbackName, bool $fallbackVerified, Closure $resolve): ActionTarget
    {
        try {
            $target = $resolve();
        } catch (Throwable $throwable) {
            Log::info('ActionTargets: target lookup failed', [
                'noun' => $noun,
                'identifier' => $identifier,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            $target = null;
        }

        if ($target instanceof ActionTarget) {
            return $target;
        }

        $fallbackName = trim((string) $fallbackName);
        $identity = [['label' => 'ID', 'value' => (string) $identifier]];

        return $fallbackName !== ''
            ? new ActionTarget($noun, mb_substr($fallbackName, 0, 200), $identity, $fallbackVerified)
            : new ActionTarget($noun, sprintf('#%s (not found)', $identifier), $identity, verified: false);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function nameFrom(array $item): ?string
    {
        $title = $item['title'] ?? null;

        return is_string($title) && $title !== ''
            ? $this->withYear($title, is_int($item['year'] ?? null) ? $item['year'] : null)
            : null;
    }

    private function withYear(string $title, ?int $year): string
    {
        return $year !== null && $year > 0 ? sprintf('%s (%d)', $title, $year) : $title;
    }

    private function arrClient(ServiceType $serviceType, ServiceConnection $serviceConnection): ArrClient
    {
        return match ($serviceType) {
            ServiceType::Sonarr, ServiceType::Radarr => $this->arrConnections->client($serviceConnection),
            ServiceType::Whisparr => new WhisparrClient($serviceConnection),
            default => throw new InvalidArgumentException(sprintf('No arr client for %s', $serviceType->value)),
        };
    }
}
