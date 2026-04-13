<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Jellyseerr\JellyseerrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RequestController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        try {
            $response = $this->client()->getRequests(['take' => 50, 'sort' => 'added']);
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        } catch (RequestException|ConnectionException) {
            return $this->connectionFailedRedirect();
        }

        $results = $response['results'] ?? $response;

        return Inertia::render('Jellyseerr/Requests', [
            'requests' => array_map(fn (array $req): array => [
                'id' => $req['id'] ?? null,
                'status' => $req['status'] ?? null,
                'media_type' => $req['type'] ?? ($req['media']['mediaType'] ?? null),
                'media_title' => $req['media']['title'] ?? ($req['media']['name'] ?? null),
                'tmdb_id' => $req['media']['tmdbId'] ?? null,
                'tvdb_id' => $req['media']['tvdbId'] ?? null,
                'requester' => $req['requestedBy']['displayName'] ?? ($req['requestedBy']['username'] ?? null),
                'created_at' => $req['createdAt'] ?? null,
                'updated_at' => $req['updatedAt'] ?? null,
            ], is_array($results) ? $results : []),
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

    private function client(): JellyseerrClient
    {
        return new JellyseerrClient(ServiceConnection::resolveActive(ServiceType::Jellyseerr));
    }

    private function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Jellyseerr connection configured.')]);

        return to_route('dashboard');
    }

    private function connectionFailedRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to connect to Jellyseerr.')]);

        return to_route('dashboard');
    }
}
