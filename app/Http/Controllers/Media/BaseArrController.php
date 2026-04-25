<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

abstract class BaseArrController extends Controller
{
    abstract protected function serviceType(): ServiceType;

    abstract protected function buildClient(ServiceConnection $serviceConnection): SonarrClient|RadarrClient;

    abstract protected function noConnectionMessage(): string;

    abstract protected function connectionFailedMessage(): string;

    /**
     * Resolve the active service connection or short-circuit with a redirect.
     */
    protected function resolveConnection(): ServiceConnection|RedirectResponse
    {
        try {
            return ServiceConnection::resolveActive($this->serviceType());
        } catch (ModelNotFoundException) {
            return $this->noConnectionRedirect();
        }
    }

    /**
     * Wrap a closure that performs an *arr HTTP call. Returns the closure's value, OR an
     * empty array when the call failed — for use inside Inertia::defer where we don't
     * want to redirect.
     *
     * @template T
     *
     * @param  callable(SonarrClient|RadarrClient): T  $fn
     * @return T|array<empty>
     */
    protected function tryClientCall(ServiceConnection $serviceConnection, callable $fn): mixed
    {
        try {
            return $fn($this->buildClient($serviceConnection));
        } catch (RequestException|ConnectionException) {
            return [];
        }
    }

    /**
     * @return array{url: string}
     */
    protected function connectionUrl(ServiceConnection $serviceConnection): array
    {
        return ['url' => rtrim($serviceConnection->url, '/')];
    }

    /**
     * Build a fresh client backed by the active connection (used for write operations
     * where we want the ModelNotFoundException to propagate to the caller).
     */
    protected function client(): SonarrClient|RadarrClient
    {
        return $this->buildClient(ServiceConnection::resolveActive($this->serviceType()));
    }

    protected function noConnectionRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $this->noConnectionMessage()]);

        return to_route('dashboard');
    }

    protected function connectionFailedRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $this->connectionFailedMessage()]);

        return to_route('dashboard');
    }
}
