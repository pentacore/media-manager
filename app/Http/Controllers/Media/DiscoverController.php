<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\DiscoverMediaRequest;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrTitlePresenter;
use App\Services\Seerr\SeerrUserResolver;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DiscoverController extends Controller
{
    private const int UPCOMING_LIMIT = 20;

    private const string UNREACHABLE = 'Seerr is unreachable right now.';

    private const string NO_SEERR_ACCOUNT = 'No Seerr account is linked to you — ask an admin.';

    public function index(Request $request, SeerrTitlePresenter $seerrTitlePresenter, SeerrUserResolver $seerrUserResolver): Response
    {
        $connection = $this->seerrConnection();

        if (! $connection instanceof ServiceConnection) {
            return Inertia::render('Discover/Index', ['seerr' => ['connected' => false]]);
        }

        $seerrClient = new SeerrClient($connection);
        $user = $request->user();

        return Inertia::render('Discover/Index', [
            'seerr' => ['connected' => true],
            'trending' => Inertia::defer(fn (): array => $this->row(fn (): array => $seerrTitlePresenter->results($seerrClient->discoverTrending())), 'trending'),
            'popularMovies' => Inertia::defer(fn (): array => $this->row(fn (): array => $seerrTitlePresenter->results($seerrClient->discoverMovies(), 'movie')), 'popularMovies'),
            'popularTv' => Inertia::defer(fn (): array => $this->row(fn (): array => $seerrTitlePresenter->results($seerrClient->discoverTv(), 'tv')), 'popularTv'),
            'upcoming' => Inertia::defer(fn (): array => $this->row(fn (): array => $this->upcoming($seerrClient, $seerrTitlePresenter)), 'upcoming'),
            'requesting' => Inertia::defer(fn (): array => $seerrUserResolver->requestingContext($connection, $user), 'requesting'),
        ]);
    }

    public function title(string $mediaType, int $tmdbId, SeerrTitlePresenter $seerrTitlePresenter): JsonResponse
    {
        $connection = $this->seerrConnection();

        if (! $connection instanceof ServiceConnection) {
            return response()->json(['message' => __('No active Seerr connection configured.')], 422);
        }

        $seerrClient = new SeerrClient($connection);

        try {
            $detail = $mediaType === 'movie' ? $seerrClient->getMovieDetails($tmdbId) : $seerrClient->getTvDetails($tmdbId);
        } catch (RequestException $requestException) {
            return $requestException->response->status() === 404
                ? response()->json(['message' => __('Seerr does not know this title.')], 404)
                : response()->json(['message' => __(self::UNREACHABLE)], 502);
        } catch (ConnectionException) {
            return response()->json(['message' => __(self::UNREACHABLE)], 502);
        }

        $presented = $seerrTitlePresenter->detail($mediaType, $detail);

        abort_if($presented === null, 404);

        return response()->json($presented);
    }

    public function request(DiscoverMediaRequest $discoverMediaRequest, SeerrUserResolver $seerrUserResolver): RedirectResponse
    {
        $validated = $discoverMediaRequest->validated();
        $tmdbId = (int) $validated['tmdbId'];
        $mediaType = (string) $validated['mediaType'];
        $user = $discoverMediaRequest->user();
        $connection = $this->seerrConnection();

        if (! $connection instanceof ServiceConnection) {
            return $this->outcome(false, $tmdbId, $mediaType, 'error', __('No active Seerr connection configured.'));
        }

        // requestingContext() never throws: it swallows RequestException /
        // ConnectionException itself and reports them via `error`.
        $context = $seerrUserResolver->requestingContext($connection, $user);

        if ($context['error'] !== null) {
            return $this->outcome(false, $tmdbId, $mediaType, 'error', __(self::UNREACHABLE));
        }

        $userId = $context['userId'];

        // Only a manage-requests user may choose someone else, and only a
        // Seerr id that came back from pickerOptions() (i.e. is a real
        // Seerr user) — never trust the posted id at face value. A viewer's
        // posted userId is ignored outright: canChooseUser is false for
        // them, so this block never runs and $userId stays their own
        // resolve()d id.
        if ($context['canChooseUser'] && isset($validated['userId'])) {
            $chosenUserId = (int) $validated['userId'];

            if (! in_array($chosenUserId, array_column($context['users'], 'id'), true)) {
                return $this->outcome(false, $tmdbId, $mediaType, 'error', __(self::NO_SEERR_ACCOUNT));
            }

            $userId = $chosenUserId;
        }

        if ($userId === null) {
            return $this->outcome(false, $tmdbId, $mediaType, 'error', __(self::NO_SEERR_ACCOUNT));
        }

        $seasons = array_values(array_map(intval(...), $validated['seasons'] ?? []));

        try {
            $created = new SeerrClient($connection)->createRequest(
                $tmdbId,
                $mediaType,
                $mediaType === 'tv' && $seasons !== [] ? $seasons : 'all',
                $userId,
            );
        } catch (RequestException $requestException) {
            return $this->outcome(false, $tmdbId, $mediaType, 'error', $this->refusal($requestException));
        } catch (ConnectionException) {
            // createRequest never retries: Seerr may have filed it already.
            return $this->outcome(false, $tmdbId, $mediaType, 'error', __('Seerr did not answer — check My requests before trying again.'));
        }

        // Seerr answers 202 {message} when every chosen season is already
        // requested or available (NoSeasonsAvailableError).
        if (! isset($created['id'])) {
            return $this->outcome(true, $tmdbId, $mediaType, 'info', __('Everything in this title is already requested or available.'));
        }

        return $this->outcome(true, $tmdbId, $mediaType, 'success', __('Request submitted.'));
    }

    private function seerrConnection(): ?ServiceConnection
    {
        try {
            return ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * @param  Closure(): list<array<string, mixed>>  $load
     * @return array{results: list<array<string, mixed>>, error: string|null}
     */
    private function row(Closure $load): array
    {
        try {
            return ['results' => $load(), 'error' => null];
        } catch (RequestException|ConnectionException) {
            return ['results' => [], 'error' => __(self::UNREACHABLE)];
        }
    }

    /**
     * Upcoming movies and TV in one row, soonest first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function upcoming(SeerrClient $seerrClient, SeerrTitlePresenter $seerrTitlePresenter): array
    {
        $rows = [
            ...$seerrTitlePresenter->results($seerrClient->discoverUpcoming('movie'), 'movie'),
            ...$seerrTitlePresenter->results($seerrClient->discoverUpcoming('tv'), 'tv'),
        ];

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['release_date'] ?? '9999'), (string) ($b['release_date'] ?? '9999')));

        return array_slice($rows, 0, self::UPCOMING_LIMIT);
    }

    private function refusal(RequestException $requestException): string
    {
        $status = $requestException->response->status();
        $message = (string) ($requestException->response->json('message') ?? '');

        return match (true) {
            $status === 409 => __('This title has already been requested.'),
            $status === 403 && Str::contains($message, 'quota', ignoreCase: true) => __('Seerr quota reached — try again later.'),
            $status === 403 && $message !== '' => __('Seerr refused the request: :message', ['message' => $message]),
            default => __(self::UNREACHABLE),
        };
    }

    private function outcome(bool $ok, int $tmdbId, string $mediaType, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
        Inertia::flash('requestOutcome', ['ok' => $ok, 'tmdbId' => $tmdbId, 'mediaType' => $mediaType]);

        return back();
    }
}
