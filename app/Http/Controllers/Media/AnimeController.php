<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Cache\Services\AnimeCache;
use App\Enums\AnimeSeason;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Jobs\SyncAnimeMappingJob;
use App\Models\AnimeIdMap;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Anime\AniListClient;
use App\Services\Anime\AnimeIdMapper;
use App\Services\Anime\AnimeMapping;
use App\Services\Anime\JikanClient;
use App\Services\Anime\SeasonalAnimeEntry;
use App\Services\Anime\SeasonalAnimeSource;
use App\Services\Seerr\SeerrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class AnimeController extends Controller
{
    public function __construct(private readonly AnimeIdMapper $animeIdMapper) {}

    /**
     * Seasonal anime discovery grid. Season list is fetched live (cached) and
     * mapped to TMDB/TVDB ids; owned/requested status is overlaid fresh.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        // Self-bootstrap on a fresh deployment: the table is otherwise only
        // filled by the weekly schedule. ShouldBeUnique keeps this to one run.
        if (AnimeIdMap::query()->doesntExist()) {
            dispatch(new SyncAnimeMappingJob);
        }

        [$year, $season] = $this->resolveSeason($request);
        $seasonalAnimeSource = $this->resolveSource($request);

        return Inertia::render('Anime/Season', [
            'filters' => [
                'year' => $year,
                'season' => $season->value,
                'source' => $seasonalAnimeSource->slug(),
            ],
            'navigation' => $this->navigation($year, $season),
            'requestingUsers' => Inertia::defer(fn (): array => $this->requestingUsers($connection)),
            'entries' => Inertia::defer(fn (): array => $this->loadSeason($connection, $seasonalAnimeSource, $year, $season)),
        ]);
    }

    /**
     * File a Seerr request for a mapped seasonal anime entry.
     */
    public function request(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tmdbId' => ['required', 'integer'],
            'mediaType' => ['required', 'in:tv,movie'],
            'tmdbSeason' => ['nullable', 'integer'],
            'startDate' => ['nullable', 'date'],
            'userId' => ['nullable', 'integer'],
        ]);

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
            $seerrClient = new SeerrClient($connection);

            $seasons = $validated['mediaType'] === 'tv'
                ? $this->resolveSeasons($seerrClient, $validated)
                : 'all';

            $seerrClient->createRequest(
                (int) $validated['tmdbId'],
                $validated['mediaType'],
                $seasons,
                isset($validated['userId']) ? (int) $validated['userId'] : null,
            );
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            // A failure still redirects (a successful Inertia visit), so signal
            // the outcome explicitly rather than letting the client assume the
            // card is now requested.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to submit request.')]);
            Inertia::flash('requestOutcome', ['ok' => false, 'tmdbId' => (int) $validated['tmdbId'], 'mediaType' => $validated['mediaType']]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request submitted.')]);
        Inertia::flash('requestOutcome', ['ok' => true, 'tmdbId' => (int) $validated['tmdbId'], 'mediaType' => $validated['mediaType']]);

        return back();
    }

    /**
     * Find TMDB candidates for an unmapped title (fuzzy fallback, step B).
     */
    public function findMatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
            $results = new SeerrClient($connection)->search($validated['title']);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Search failed.')]);

            return back();
        }

        Inertia::flash('matchCandidates', $this->mapCandidates($results));

        return back();
    }

    /**
     * Persist a user-confirmed match, then request it.
     */
    public function confirmMatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'anilistId' => ['nullable', 'integer'],
            'malId' => ['nullable', 'integer'],
            'tmdbId' => ['required', 'integer'],
            // The chosen candidate's own media type is authoritative — it may
            // differ from the anime's format, and it decides which TMDB
            // namespace we persist + request against.
            'mediaType' => ['required', 'in:tv,movie'],
            'tmdbSeason' => ['nullable', 'integer'],
            'startDate' => ['nullable', 'date'],
            'userId' => ['nullable', 'integer'],
        ]);

        if (empty($validated['anilistId']) && empty($validated['malId'])) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Cannot confirm a match without an anime id.')]);

            return back();
        }

        $this->animeIdMapper->persistConfirmedMatch(
            isset($validated['anilistId']) ? (int) $validated['anilistId'] : null,
            isset($validated['malId']) ? (int) $validated['malId'] : null,
            (int) $validated['tmdbId'],
            $validated['mediaType'],
        );

        // The freshly confirmed row changes what the cached season shows as
        // mapped, so drop the season cache before re-rendering.
        new AnimeCache()->bustAll();

        return $this->request($request);
    }

    /**
     * Assemble + map a season, then overlay live library/request status. The
     * list portion is cached by AnimeCache; the status overlay stays live.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadSeason(ServiceConnection $serviceConnection, SeasonalAnimeSource $seasonalAnimeSource, int $year, AnimeSeason $animeSeason): array
    {
        $animeCache = new AnimeCache;
        $suffix = sprintf('%s:%d:%s', $seasonalAnimeSource->slug(), $year, $animeSeason->value);

        /** @var array<int, array<string, mixed>> $mapped */
        $mapped = $this->isPastSeason($year, $animeSeason)
            ? $animeCache->rememberMetadata($suffix, fn (): array => $this->fetchAndMap($seasonalAnimeSource, $year, $animeSeason))
            : $animeCache->rememberList($suffix, fn (): array => $this->fetchAndMap($seasonalAnimeSource, $year, $animeSeason));

        return $this->overlayStatus($serviceConnection, $mapped);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAndMap(SeasonalAnimeSource $seasonalAnimeSource, int $year, AnimeSeason $animeSeason): array
    {
        $entries = $seasonalAnimeSource->fetchSeason($year, $animeSeason);
        $mappings = $this->animeIdMapper->resolveMany($entries);

        return $entries->map(function (SeasonalAnimeEntry $seasonalAnimeEntry) use ($mappings): array {
            $key = $this->animeIdMapper->entryKey($seasonalAnimeEntry);
            $mapping = $mappings[$key] ?? AnimeMapping::unmapped($seasonalAnimeEntry->format);

            return [
                'key' => $key,
                ...$seasonalAnimeEntry->toArray(),
                'mapping' => $mapping->toArray(),
            ];
        })->all();
    }

    /**
     * Overlay volatile owned/requested flags (batch queries, not per-card).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function overlayStatus(ServiceConnection $serviceConnection, array $rows): array
    {
        $tvdbIds = collect($rows)->pluck('mapping.tvdbId')->filter()->unique();
        $movieTmdbIds = collect($rows)
            ->filter(fn (array $r): bool => $r['mapping']['mediaType'] === 'movie')
            ->pluck('mapping.tmdbId')->filter()->unique();

        $ownedTvdb = IndexedSeries::query()->whereIn('tvdb_id', $tvdbIds)->pluck('tvdb_id')->flip();
        $ownedMovie = IndexedMovie::query()->whereIn('tmdb_id', $movieTmdbIds)->pluck('tmdb_id')->flip();
        $requestedKeys = $this->requestedMediaKeys($serviceConnection);

        return collect($rows)->map(function (array $row) use ($ownedTvdb, $ownedMovie, $requestedKeys): array {
            $mapping = $row['mapping'];
            $row['status'] = match (true) {
                ! $mapping['mapped'] => 'unmapped',
                $mapping['mediaType'] === 'movie' && $ownedMovie->has($mapping['tmdbId']) => 'in_library',
                $mapping['mediaType'] === 'tv' && $mapping['tvdbId'] !== null && $ownedTvdb->has($mapping['tvdbId']) => 'in_library',
                $requestedKeys->has($mapping['mediaType'].':'.$mapping['tmdbId']) => 'requested',
                default => 'requestable',
            };

            return $row;
        })->all();
    }

    /**
     * Media already present in Seerr as requests, keyed by "mediaType:tmdbId".
     * TMDB tv/movie ids overlap numerically, so the media type is part of the
     * key. All request pages are walked (bounded) so older requests are seen.
     *
     * @return Collection<string, int>
     */
    private function requestedMediaKeys(ServiceConnection $serviceConnection): Collection
    {
        $seerrClient = new SeerrClient($serviceConnection);
        $keys = collect();
        $take = 100;
        $skip = 0;
        $maxPages = 20;

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $payload = $seerrClient->getRequests(['take' => $take, 'skip' => $skip, 'filter' => 'all']);
                $results = $payload['results'] ?? [];

                foreach ($results as $result) {
                    $media = $result['media'] ?? [];
                    $tmdbId = $media['tmdbId'] ?? null;
                    $mediaType = $media['mediaType'] ?? null;

                    if ($tmdbId !== null && $mediaType !== null) {
                        $keys->put($mediaType.':'.(int) $tmdbId, (int) $tmdbId);
                    }
                }

                $total = (int) ($payload['pageInfo']['results'] ?? count($results));
                $skip += $take;

                if (count($results) < $take || $skip >= $total) {
                    break;
                }
            }
        } catch (RequestException|ConnectionException) {
            return $keys;
        }

        return $keys;
    }

    /**
     * Resolve the season number(s) to request. Prefer the dataset's TMDB
     * season; else air-date match against the show's TMDB seasons; else 'all'.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, int>|string
     */
    private function resolveSeasons(SeerrClient $seerrClient, array $validated): array|string
    {
        // Season 0 (specials) is a valid mapping — guard on null, not empty().
        if (($validated['tmdbSeason'] ?? null) !== null) {
            return [(int) $validated['tmdbSeason']];
        }

        if (empty($validated['startDate'])) {
            return 'all';
        }

        try {
            $details = $seerrClient->getTvDetails((int) $validated['tmdbId']);
        } catch (RequestException|ConnectionException) {
            return 'all';
        }

        $seasons = collect($details['seasons'] ?? [])
            ->filter(fn (array $s): bool => (int) ($s['seasonNumber'] ?? $s['season_number'] ?? 0) > 0
                && ! empty($s['airDate'] ?? $s['air_date'] ?? null));

        if ($seasons->count() <= 1) {
            return 'all';
        }

        $target = Date::parse($validated['startDate']);
        $closest = $seasons->sortBy(fn (array $s): int => (int) abs(
            Date::parse($s['airDate'] ?? $s['air_date'])->diffInDays($target, false)
        ))->first();

        $number = (int) ($closest['seasonNumber'] ?? $closest['season_number'] ?? 0);

        return $number > 0 ? [$number] : 'all';
    }

    /**
     * Seerr users for the "Requesting as" picker, with an email-matched
     * default for the current app user.
     *
     * @return array{users: array<int, array{id: int, label: string}>, defaultId: int|null}
     */
    private function requestingUsers(ServiceConnection $serviceConnection): array
    {
        $seerrClient = new SeerrClient($serviceConnection);
        $email = strtolower((string) request()->user()?->email);

        $users = collect();
        $defaultId = null;
        $take = 100;
        $skip = 0;
        $maxPages = 20;

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $payload = $seerrClient->getUsers(['take' => $take, 'skip' => $skip]);
                $results = $payload['results'] ?? [];

                foreach ($results as $result) {
                    $id = (int) $result['id'];
                    $userEmail = strtolower((string) ($result['email'] ?? ''));

                    if ($defaultId === null && $email !== '' && $userEmail === $email) {
                        $defaultId = $id;
                    }

                    $users->push([
                        'id' => $id,
                        'label' => $result['displayName'] ?? $result['email'] ?? ('User #'.$id),
                    ]);
                }

                $total = (int) ($payload['pageInfo']['results'] ?? count($results));
                $skip += $take;

                // Stop early once the current user is found, or the list ends.
                if ($defaultId !== null || count($results) < $take || $skip >= $total) {
                    break;
                }
            }
        } catch (RequestException|ConnectionException) {
            return ['users' => $users->all(), 'defaultId' => $defaultId ?? ($users->first()['id'] ?? null)];
        }

        return [
            'users' => $users->all(),
            'defaultId' => $defaultId ?? ($users->first()['id'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $results
     * @return array<int, array<string, mixed>>
     */
    private function mapCandidates(array $results): array
    {
        return collect($results['results'] ?? [])
            ->filter(fn (array $r): bool => in_array($r['mediaType'] ?? null, ['tv', 'movie'], true))
            ->take(3)
            ->map(fn (array $r): array => [
                'tmdbId' => (int) ($r['id'] ?? 0),
                'mediaType' => $r['mediaType'],
                'title' => $r['name'] ?? $r['title'] ?? 'Unknown',
                'year' => substr((string) ($r['firstAirDate'] ?? $r['releaseDate'] ?? ''), 0, 4),
                'posterPath' => $r['posterPath'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function resolveSource(Request $request): SeasonalAnimeSource
    {
        $slug = (string) $request->query('source', config('mediamanager.anime.source'));

        return $slug === 'jikan' ? new JikanClient : new AniListClient;
    }

    /**
     * @return array{0: int, 1: AnimeSeason}
     */
    private function resolveSeason(Request $request): array
    {
        $now = Date::now();
        $year = $request->integer('year', $now->year);
        $year = max(1960, min($now->year + 1, $year));

        $seasonValue = (string) $request->query('season', AnimeSeason::forMonth($now->month)->value);
        $season = AnimeSeason::tryFrom($seasonValue) ?? AnimeSeason::forMonth($now->month);

        return [$year, $season];
    }

    /**
     * @return array{current: array{year: int, season: string, label: string}, previous: array{year: int, season: string}, next: array{year: int, season: string}}
     */
    private function navigation(int $year, AnimeSeason $animeSeason): array
    {
        $prev = $animeSeason->previous($year);
        $next = $animeSeason->next($year);

        return [
            'current' => ['year' => $year, 'season' => $animeSeason->value, 'label' => $animeSeason->label().' '.$year],
            'previous' => ['year' => $prev['year'], 'season' => $prev['season']->value],
            'next' => ['year' => $next['year'], 'season' => $next['season']->value],
        ];
    }

    private function isPastSeason(int $year, AnimeSeason $animeSeason): bool
    {
        $now = Date::now();
        $current = AnimeSeason::forMonth($now->month);

        return $year < $now->year || ($year === $now->year && $animeSeason->startMonth() < $current->startMonth());
    }

    private function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Seerr connection configured.')]);

        return to_route('dashboard');
    }
}
