<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RequestController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        return Inertia::render('Seerr/Requests', [
            'connection' => ['url' => rtrim($connection->url, '/')],
            'filters' => ['page' => $page],
            'requests' => Inertia::defer(fn (): array => $this->loadRequests($connection, $page, $perPage)),
            'summary' => Inertia::defer(fn (): array => $this->loadSummary($connection)),
        ]);
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->client()->deleteRequest($id);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to delete request.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request deleted.')]);

        return to_route('media.requests.index');
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->updateStatus($id, 'approve', __('Request approved.'), __('Failed to approve request.'));
    }

    public function decline(int $id): RedirectResponse
    {
        return $this->updateStatus($id, 'decline', __('Request declined.'), __('Failed to decline request.'));
    }

    public function retry(int $id): RedirectResponse
    {
        try {
            $this->client()->retryRequest($id);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to retry request.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request retry triggered.')]);

        return back();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function loadRequests(ServiceConnection $serviceConnection, int $page, int $perPage): array
    {
        $seerrClient = new SeerrClient($serviceConnection);

        try {
            $response = $seerrClient->getRequests([
                'take' => $perPage,
                'skip' => ($page - 1) * $perPage,
                'sort' => 'added',
            ]);
        } catch (RequestException|ConnectionException) {
            return [
                'data' => [],
                'meta' => ['current_page' => $page, 'last_page' => 1, 'total' => 0, 'per_page' => $perPage],
            ];
        }

        $results = is_array($response['results'] ?? null) ? $response['results'] : [];
        $pageInfo = is_array($response['pageInfo'] ?? null) ? $response['pageInfo'] : [];

        $titles = $this->resolveTitles($serviceConnection, $seerrClient, $results);

        return [
            'data' => array_map(fn (array $req): array => $this->mapRequest($req, $titles), $results),
            'meta' => [
                'current_page' => (int) ($pageInfo['page'] ?? $page),
                'last_page' => (int) ($pageInfo['pages'] ?? 1),
                'total' => (int) ($pageInfo['results'] ?? count($results)),
                'per_page' => (int) ($pageInfo['pageSize'] ?? $perPage),
            ],
        ];
    }

    /**
     * @return array{total: int, pending: int, approved: int, declined: int}
     */
    private function loadSummary(ServiceConnection $serviceConnection): array
    {
        try {
            $counts = new SeerrClient($serviceConnection)->getRequestCount();
        } catch (RequestException|ConnectionException) {
            return ['total' => 0, 'pending' => 0, 'approved' => 0, 'declined' => 0];
        }

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'declined' => (int) ($counts['declined'] ?? 0),
        ];
    }

    /**
     * Fetch titles for every distinct (media_type, tmdb_id) in the result set.
     * Uses Cache::remember for a 5-minute TTL so repeat visits are cheap.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, string> keyed by "{media_type}:{tmdb_id}"
     */
    private function resolveTitles(ServiceConnection $serviceConnection, SeerrClient $seerrClient, array $requests): array
    {
        $pairs = [];
        foreach ($requests as $request) {
            $mediaType = $request['type'] ?? ($request['media']['mediaType'] ?? null);
            $tmdbId = $request['media']['tmdbId'] ?? null;
            if ($mediaType === null) {
                continue;
            }

            if ($tmdbId === null) {
                continue;
            }

            $pairs[sprintf('%s:%d', $mediaType, (int) $tmdbId)] = [
                'type' => (string) $mediaType,
                'id' => (int) $tmdbId,
            ];
        }

        $titles = [];
        foreach ($pairs as $key => $pair) {
            $cacheKey = sprintf('seerr:title:%d:%s:%d', $serviceConnection->id, $pair['type'], $pair['id']);
            $titles[$key] = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($seerrClient, $pair): string {
                try {
                    $detail = $pair['type'] === 'movie'
                        ? $seerrClient->getMovieDetails($pair['id'])
                        : $seerrClient->getTvDetails($pair['id']);
                } catch (RequestException|ConnectionException) {
                    return sprintf('%s #%d', ucfirst($pair['type']), $pair['id']);
                }

                return (string) ($detail['title'] ?? $detail['name'] ?? $detail['originalTitle'] ?? $detail['originalName'] ?? sprintf('%s #%d', ucfirst($pair['type']), $pair['id']));
            });
        }

        return $titles;
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  array<string, string>  $titles
     * @return array<string, mixed>
     */
    private function mapRequest(array $req, array $titles): array
    {
        $mediaType = $req['type'] ?? ($req['media']['mediaType'] ?? null);
        $tmdbId = $req['media']['tmdbId'] ?? null;
        $titleKey = ($mediaType !== null && $tmdbId !== null) ? sprintf('%s:%d', $mediaType, (int) $tmdbId) : null;

        return [
            'id' => $req['id'] ?? null,
            'status' => $req['status'] ?? null,
            'media_type' => $mediaType,
            'media_title' => $titleKey !== null ? ($titles[$titleKey] ?? null) : null,
            'tmdb_id' => $tmdbId,
            'tvdb_id' => $req['media']['tvdbId'] ?? null,
            'requester' => $req['requestedBy']['displayName'] ?? ($req['requestedBy']['username'] ?? null),
            'created_at' => $req['createdAt'] ?? null,
            'updated_at' => $req['updatedAt'] ?? null,
        ];
    }

    private function updateStatus(int $id, string $status, string $successMessage, string $failureMessage): RedirectResponse
    {
        try {
            $this->client()->updateRequestStatus($id, $status);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $failureMessage]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $successMessage]);

        return back();
    }

    private function client(): SeerrClient
    {
        return new SeerrClient(ServiceConnection::resolveActive(ServiceType::Seerr));
    }

    private function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Seerr connection configured.')]);

        return to_route('dashboard');
    }
}
