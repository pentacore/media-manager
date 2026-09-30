<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Cache\Services\SeerrCache;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrTitleResolver;
use App\Services\Seerr\SeerrUserResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyRequestController extends Controller
{
    private const int PER_PAGE = 20;

    /** Seerr MediaRequestStatus → label. */
    private const array STATUSES = [1 => 'pending', 2 => 'approved', 3 => 'declined', 4 => 'failed', 5 => 'completed'];

    private const int PENDING = 1;

    private const int MEDIA_AVAILABLE = 5;

    public function index(Request $request, SeerrUserResolver $seerrUserResolver, SeerrTitleResolver $seerrTitleResolver): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $connection = $this->seerrConnection();

        if (! $connection instanceof ServiceConnection) {
            return Inertia::render('Seerr/MyRequests', ['seerr' => ['connected' => false], 'filters' => ['page' => $page]]);
        }

        $user = $request->user();

        return Inertia::render('Seerr/MyRequests', [
            'seerr' => ['connected' => true],
            'filters' => ['page' => $page],
            'requests' => Inertia::defer(fn (): array => $this->loadRequests($connection, $user, $page, $seerrUserResolver, $seerrTitleResolver)),
        ]);
    }

    public function destroy(int $id, Request $request, SeerrUserResolver $seerrUserResolver): RedirectResponse
    {
        $connection = $this->seerrConnection();

        if (! $connection instanceof ServiceConnection) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Seerr connection configured.')]);

            return back();
        }

        $seerrClient = new SeerrClient($connection);

        try {
            $seerrUserId = $seerrUserResolver->resolve($connection, $request->user());
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Seerr is unreachable right now.')]);

            return back();
        }

        // No own Seerr match means nothing this user could have requested —
        // refuse before ever reading the request from Seerr.
        abort_if($seerrUserId === null, 403);

        try {
            $seerrRequest = $seerrClient->getRequestByIdUncached($id);
        } catch (RequestException $requestException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $requestException->response->status() === 404
                ? __('That request no longer exists.')
                : __('Seerr is unreachable right now.')]);

            return back();
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Seerr is unreachable right now.')]);

            return back();
        }

        // MediaManager talks to Seerr with the admin API key, so Seerr's own
        // "owner + pending" rule does not apply — enforce it here.
        abort_unless((int) ($seerrRequest['requestedBy']['id'] ?? 0) === $seerrUserId, 403);

        if ((int) ($seerrRequest['status'] ?? 0) !== self::PENDING) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only pending requests can be cancelled.')]);

            return back();
        }

        try {
            $seerrClient->deleteRequest($id);
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Seerr is unreachable right now.')]);

            return back();
        }

        new SeerrCache($connection)->bustAll();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request cancelled.')]);

        return back();
    }

    /**
     * @return array{linked: bool, results: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, error: string|null}
     */
    private function loadRequests(ServiceConnection $serviceConnection, User $user, int $page, SeerrUserResolver $seerrUserResolver, SeerrTitleResolver $seerrTitleResolver): array
    {
        $empty = ['current_page' => $page, 'last_page' => 1, 'total' => 0, 'per_page' => self::PER_PAGE];
        $seerrClient = new SeerrClient($serviceConnection);

        try {
            $seerrUserId = $seerrUserResolver->resolve($serviceConnection, $user);

            if ($seerrUserId === null) {
                return ['linked' => false, 'results' => [], 'meta' => $empty, 'error' => null];
            }

            $response = $seerrClient->getRequestsByUser($seerrUserId, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        } catch (RequestException|ConnectionException) {
            return ['linked' => true, 'results' => [], 'meta' => $empty, 'error' => __('Seerr is unreachable right now.')];
        }

        $rows = is_array($response['results'] ?? null) ? array_values(array_filter($response['results'], is_array(...))) : [];
        // Defence in depth: getRequestsByUser() is already scoped to
        // $seerrUserId, but never trust an upstream filter to be the only
        // guard against showing someone else's request.
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => (int) ($row['requestedBy']['id'] ?? 0) === $seerrUserId,
        ));
        $pageInfo = is_array($response['pageInfo'] ?? null) ? $response['pageInfo'] : [];
        $media = $seerrTitleResolver->resolve($serviceConnection, $seerrClient, $rows);

        return [
            'linked' => true,
            'results' => array_map(fn (array $row): array => $this->mapRequest($row, $media, $seerrTitleResolver), $rows),
            'meta' => [
                'current_page' => (int) ($pageInfo['page'] ?? $page),
                'last_page' => max(1, (int) ($pageInfo['pages'] ?? 1)),
                'total' => (int) ($pageInfo['results'] ?? count($rows)),
                'per_page' => self::PER_PAGE,
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array{title: string, poster_path: ?string}>  $media
     * @return array<string, mixed>
     */
    private function mapRequest(array $row, array $media, SeerrTitleResolver $seerrTitleResolver): array
    {
        $requestStatus = (int) ($row['status'] ?? 0);
        $status = (int) ($row['media']['status'] ?? 0) === self::MEDIA_AVAILABLE
            ? 'available'
            : (self::STATUSES[$requestStatus] ?? 'pending');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'media_type' => (string) ($row['type'] ?? $row['media']['mediaType'] ?? 'movie'),
            'title' => $seerrTitleResolver->titleFor($row, $media) ?? __('Unknown title'),
            'poster_path' => $seerrTitleResolver->posterPathFor($row, $media),
            'status' => $status,
            'requested_at' => is_string($row['createdAt'] ?? null) ? $row['createdAt'] : null,
            'can_cancel' => $requestStatus === self::PENDING,
        ];
    }

    private function seerrConnection(): ?ServiceConnection
    {
        try {
            return ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
