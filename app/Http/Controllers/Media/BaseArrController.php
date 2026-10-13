<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Arr\ArrConnections;
use App\Services\Arr\LibraryCacheSync;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

abstract class BaseArrController extends Controller
{
    public function __construct(protected readonly ArrConnections $arrConnections) {}

    abstract protected function serviceType(): ServiceType;

    abstract protected function buildClient(ServiceConnection $serviceConnection): SonarrClient|RadarrClient;

    abstract protected function connectionFailedMessage(): string;

    /**
     * Resolve the active service connection or short-circuit with the
     * standard no-connection redirect.
     */
    protected function resolveConnection(): ServiceConnection|RedirectResponse
    {
        return ServiceConnection::findActive($this->serviceType()) ?? $this->noActiveConnectionRedirect($this->serviceType());
    }

    /**
     * The index page's Sync button (Sonarr series, Radarr movies; every role
     * that can open the page).
     */
    public function sync(LibraryCacheSync $libraryCacheSync): RedirectResponse
    {
        $connection = ServiceConnection::findActive($this->serviceType());

        if (! $connection instanceof ServiceConnection) {
            return $this->noActiveConnectionRedirect($this->serviceType(), back());
        }

        return $this->syncLibraryCache($connection, $libraryCacheSync);
    }

    /**
     * The connection a title page was rendered from, for a write that must
     * act on exactly that instance (media ids overlap between instances). A
     * deleted, deactivated or other-service pin refuses with a "refresh and
     * try again" toast instead of falling back to the active connection.
     */
    protected function resolvePinnedConnection(int $serviceConnectionId): ServiceConnection|RedirectResponse
    {
        try {
            return ServiceConnection::resolvePinnedStrict(['service_connection_id' => $serviceConnectionId], $this->serviceType());
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return $this->flashAnd(
                'error',
                __('That :service connection is unavailable — refresh and try again.', ['service' => $this->serviceType()->label()]),
                back(),
            );
        }
    }

    /**
     * Run one deferred upstream read for a list prop. An outage becomes an
     * `error` the page shows in place of the list — never an empty list that
     * reads as an empty library. The upstream body is never echoed.
     *
     * @template TRow
     *
     * @param  callable(SonarrClient|RadarrClient): array<int, array<string, mixed>>  $fetch
     * @param  callable(array<string, mixed>): TRow  $map
     * @return array{items: list<TRow>, error: string|null}
     */
    protected function tryClientList(ServiceConnection $serviceConnection, ?User $user, callable $fetch, callable $map): array
    {
        try {
            $rows = $fetch($this->buildClient($serviceConnection));
        } catch (RequestException|ConnectionException $exception) {
            return ['items' => [], 'error' => $this->upstreamFailureMessage($exception, $user)];
        }

        return ['items' => array_values(array_map($map, $rows)), 'error' => null];
    }

    /**
     * A 4xx means the service answered but refused (a bad API key, a wrong
     * base URL), which only an admin can fix; anything else is an outage.
     */
    protected function upstreamFailureMessage(RequestException|ConnectionException $exception, ?User $user): string
    {
        return $exception instanceof RequestException && $exception->response->clientError()
            ? $this->upstreamRefusedMessage($this->serviceType(), $user)
            : __(':service is unreachable right now.', ['service' => $this->serviceType()->label()]);
    }

    /**
     * The name of one quality profile, for viewers: they get no profile
     * dropdown, so they never receive the whole list. Reads the same cached
     * profile list the dropdown uses. An outage, or an id the list no longer
     * has, reads as null (the page shows "—"): the name is a label, not
     * worth an error banner.
     */
    protected function qualityProfileName(ServiceConnection $serviceConnection, mixed $qualityProfileId): ?string
    {
        if (! is_int($qualityProfileId)) {
            return null;
        }

        try {
            $profiles = $this->buildClient($serviceConnection)->getQualityProfiles();
        } catch (RequestException|ConnectionException) {
            return null;
        }

        foreach ($profiles as $profile) {
            if (($profile['id'] ?? null) === $qualityProfileId && is_string($profile['name'] ?? null)) {
                return $profile['name'];
            }
        }

        return null;
    }

    /**
     * @return array{url: string}
     */
    protected function connectionUrl(ServiceConnection $serviceConnection): array
    {
        return ['url' => $serviceConnection->linkUrl()];
    }

    protected function connectionFailedRedirect(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $this->connectionFailedMessage()]);

        return to_route('dashboard');
    }

    protected function flashAnd(string $type, string $message, RedirectResponse $redirectResponse): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return $redirectResponse;
    }
}
