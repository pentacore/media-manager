<?php

declare(strict_types=1);

namespace App\Http\Controllers\Whisparr;

use App\Enums\ServiceType;
use App\Enums\WhisparrVersion;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Whisparr\WhisparrClient;
use App\Services\Whisparr\WhisparrItemPresenter;
use App\Services\Whisparr\WhisparrUnexpectedResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin-only Whisparr library: browse and manage, never add. Both API
 * versions render through WhisparrItemPresenter; an outage renders as an
 * error state, never as an empty library.
 */
class WhisparrController extends Controller
{
    public function index(WhisparrItemPresenter $whisparrItemPresenter): Response
    {
        $connection = ServiceConnection::findActive(ServiceType::Whisparr);

        if (! $connection instanceof ServiceConnection) {
            return Inertia::render('Whisparr/Index', ['connection' => null]);
        }

        return Inertia::render('Whisparr/Index', [
            'connection' => $this->connectionProps($connection),
            'library' => Inertia::defer(fn (): array => $this->library($connection, $whisparrItemPresenter)),
            'qualityProfiles' => Inertia::defer(fn (): array => $this->qualityProfiles($connection), 'qualityProfiles'),
        ]);
    }

    public function show(int $id, WhisparrItemPresenter $whisparrItemPresenter): Response|RedirectResponse
    {
        $connection = ServiceConnection::findActive(ServiceType::Whisparr);

        if (! $connection instanceof ServiceConnection) {
            return $this->noActiveConnectionRedirect(ServiceType::Whisparr, to_route('media.whisparr.index'));
        }

        $whisparrVersion = $connection->whisparrVersion();
        $whisparrClient = new WhisparrClient($connection);

        try {
            $item = $whisparrItemPresenter->detail($whisparrVersion, $whisparrClient->getItemById($id));
        } catch (RequestException|ConnectionException|WhisparrUnexpectedResponse $exception) {
            return $this->backToLibrary($this->failureMessage($exception, __('That title is no longer in Whisparr.')));
        }

        if ($item === null) {
            return $this->backToLibrary(__('That title is no longer in Whisparr.'));
        }

        return Inertia::render('Whisparr/Show', [
            'connection' => $this->connectionProps($connection),
            'item' => $item,
            'scenes' => $whisparrVersion === WhisparrVersion::V2
                ? Inertia::defer(fn (): array => $this->scenes($whisparrClient, $id, $whisparrItemPresenter), 'scenes')
                : ['groups' => [], 'error' => null],
            'qualityProfiles' => Inertia::defer(fn (): array => $this->qualityProfiles($connection), 'qualityProfiles'),
        ]);
    }

    /**
     * @return array{id: int, name: string, version: string, url: string}
     */
    private function connectionProps(ServiceConnection $serviceConnection): array
    {
        return [
            'id' => $serviceConnection->id,
            'name' => $serviceConnection->name,
            'version' => $serviceConnection->whisparrVersion()->value,
            'url' => $serviceConnection->linkUrl(),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, error: string|null}
     */
    private function library(ServiceConnection $serviceConnection, WhisparrItemPresenter $whisparrItemPresenter): array
    {
        try {
            $items = new WhisparrClient($serviceConnection)->getItems();
        } catch (RequestException|ConnectionException|WhisparrUnexpectedResponse $exception) {
            return ['items' => [], 'error' => $this->failureMessage($exception)];
        }

        return ['items' => $whisparrItemPresenter->rows($serviceConnection->whisparrVersion(), $items), 'error' => null];
    }

    /**
     * @return array{groups: list<array{year: int, scenes: list<array<string, mixed>>}>, error: string|null}
     */
    private function scenes(WhisparrClient $whisparrClient, int $id, WhisparrItemPresenter $whisparrItemPresenter): array
    {
        try {
            $episodes = $whisparrClient->getEpisodes($id);
        } catch (RequestException|ConnectionException|WhisparrUnexpectedResponse $exception) {
            return ['groups' => [], 'error' => $this->failureMessage($exception)];
        }

        return ['groups' => $whisparrItemPresenter->sceneGroups($episodes), 'error' => null];
    }

    /**
     * Like library(), an outage is an error the pages show next to the
     * profile select, never a silently empty list.
     *
     * @return array{items: list<array{id: int, name: string}>, error: string|null}
     */
    private function qualityProfiles(ServiceConnection $serviceConnection): array
    {
        try {
            $profiles = new WhisparrClient($serviceConnection)->getQualityProfiles();
        } catch (RequestException|ConnectionException|WhisparrUnexpectedResponse $exception) {
            return ['items' => [], 'error' => $this->failureMessage($exception)];
        }

        $rows = [];

        foreach ($profiles as $profile) {
            if (is_int($profile['id'] ?? null) && is_string($profile['name'] ?? null)) {
                $rows[] = ['id' => $profile['id'], 'name' => $profile['name']];
            }
        }

        return ['items' => $rows, 'error' => null];
    }

    /**
     * Transport errors, 5xx and a 200 that is not JSON data read as an
     * outage, 4xx as a refusal; the upstream body is never echoed.
     */
    private function failureMessage(RequestException|ConnectionException|WhisparrUnexpectedResponse $exception, ?string $notFoundMessage = null): string
    {
        if ($notFoundMessage !== null && $exception instanceof RequestException && $exception->response->notFound()) {
            return $notFoundMessage;
        }

        return $exception instanceof RequestException && $exception->response->clientError()
            ? __('Whisparr refused the request — check the connection settings.')
            : __('Whisparr is unreachable right now.');
    }

    private function backToLibrary(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('media.whisparr.index');
    }
}
