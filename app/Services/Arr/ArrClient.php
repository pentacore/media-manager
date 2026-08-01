<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class ArrClient
{
    protected string $apiVersion = 'v3';

    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(bool $withRetry = true): PendingRequest
    {
        // Arr endpoints routinely do real work before answering (folder scans,
        // import matching, blocklist-and-search), so the former 10s budget was
        // too tight for anything but plain reads. Note this is a per-attempt
        // budget: a retried call can still spend roughly three times this long
        // before failing, so raising it also lengthens the worst case.
        $pendingRequest = Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Api-Key' => $this->connection->api_key])
            ->timeout((int) config('mediamanager.arr.request_timeout'))
            ->connectTimeout(3)
            ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this));

        // Non-idempotent writes (e.g. grabbing a release) must opt out of the
        // generic retry: a server error could mean the request was already
        // accepted, and retrying would issue the side effect multiple times.
        if (! $withRetry) {
            return $pendingRequest;
        }

        return $pendingRequest->retry(
            times: 3,
            sleepMilliseconds: fn (int $attempt): int => $attempt * 500,
            when: fn (Throwable $throwable): bool => $throwable instanceof ConnectionException
                || ($throwable instanceof RequestException && $throwable->response->serverError()),
            throw: false,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSystemStatus(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/system/status', $this->apiVersion))->throw()->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getQualityProfiles(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/qualityprofile', $this->apiVersion))->throw()->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getRootFolders(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/rootfolder', $this->apiVersion))->throw()->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getDiskSpace(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/diskspace', $this->apiVersion))->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function runCommand(string $name, array $params = []): array
    {
        return $this->buildClient()->post(sprintf('/api/%s/command', $this->apiVersion), [
            'name' => $name,
            ...$params,
        ])->throw()->json() ?? [];
    }

    /**
     * Sonarr/Radarr both expose a paginated download queue at this path.
     * Subclasses choose what to inline-include via $params (e.g.
     * `includeSeries=true&includeEpisode=true` for Sonarr,
     * `includeMovie=true` for Radarr) so we don't make a second call per row.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getQueue(array $params = []): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/queue', $this->apiVersion), $params)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Paginated history feed (grabs, imports, failures, deletions).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getHistory(array $params = []): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/history', $this->apiVersion), $params)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Discover candidate files for a manual import. `downloadId` ties the
     * candidates back to a stuck queue item; both Sonarr and Radarr also
     * accept `folder` for ad-hoc imports. The response is the raw
     * ManualImportResource[] from upstream — caller maps it.
     *
     * Upstream scans the download folder and re-runs its import matching
     * before answering, so a season pack is slow by design. Retrying that scan
     * only multiplies the work upstream and multiplies the wait before the
     * caller learns it failed, so this opts out of the transparent retry and
     * relies on the (generous) generic per-attempt timeout.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getManualImport(array $params): array
    {
        return $this->buildClient(withRetry: false)
            ->get(sprintf('/api/%s/manualimport', $this->apiVersion), $params)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Force-grab a queued release immediately, bypassing any RSS sync
     * delay or scheduled retry. Sonarr/Radarr both expose this as
     * POST /api/v3/queue/grab/{id} with no body.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function grabQueueItem(int $id): array
    {
        return $this->buildClient()
            ->post(sprintf('/api/%s/queue/grab/%d', $this->apiVersion, $id))
            ->throw()
            ->json() ?? [];
    }

    /**
     * Remove a row from the download queue. Caller controls whether to
     * also evict it from the download client (`removeFromClient`),
     * remember it so it never returns (`blocklist`), and whether the
     * post-removal re-search is skipped (`skipRedownload`).
     *
     * @throws RequestException|ConnectionException
     */
    public function removeQueueItem(
        int $id,
        bool $removeFromClient = true,
        bool $blocklist = false,
        bool $skipRedownload = true,
    ): void {
        $query = http_build_query([
            'removeFromClient' => $removeFromClient ? 'true' : 'false',
            'blocklist' => $blocklist ? 'true' : 'false',
            'skipRedownload' => $skipRedownload ? 'true' : 'false',
        ]);

        $this->buildClient()
            ->delete(sprintf('/api/%s/queue/%d?%s', $this->apiVersion, $id, $query))
            ->throw();
    }

    /**
     * Run the service's native interactive release search. Sonarr accepts
     * `seriesId`/`episodeId`/`seasonNumber`; Radarr accepts `movieId`. The
     * response is the raw ReleaseResource[] from upstream, including mapping,
     * rejections, custom-format scores, and the fields required for a later grab.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    /**
     * Interactive release search sweeps every configured indexer and
     * routinely takes 30-60+ seconds — far past the generic 10s timeout,
     * whose 3 transparent retries then multiplied into repeated full indexer
     * sweeps per attempt. Dedicated long timeout, no transparent retry.
     */
    public function getReleases(array $params): array
    {
        return $this->buildClient(withRetry: false)
            ->timeout(120)
            ->get(sprintf('/api/%s/release', $this->apiVersion), $params)
            ->throw()
            ->json() ?? [];
    }

    /**
     * Grab a release by posting the full ReleaseResource returned by a fresh
     * native search. Callers must never reconstruct this payload from AI output.
     *
     * This POST is non-idempotent and deliberately opts out of the generic
     * retry: a server error may mean the grab was already accepted, so retrying
     * could start duplicate downloads. Callers must treat a server error /
     * connection loss as an indeterminate outcome, not a definitive rejection.
     *
     * @param  array<string, mixed>  $release
     *
     * @throws RequestException|ConnectionException
     */
    public function grabRelease(array $release): void
    {
        $this->buildClient(withRetry: false)
            ->post(sprintf('/api/%s/release', $this->apiVersion), $release)
            ->throw();
    }

    /**
     * Mark a history record as failed so the managing service blocklists the
     * bad release and does not re-grab it.
     *
     * The service blocklists AND kicks off an AutoRedownloadFailed indexer
     * search before answering, so like the interactive release search it can
     * outlive even the generous generic timeout. It is also a non-idempotent
     * write: under the generic retry a slow response became three
     * blocklist-and-search side effects. Dedicated long timeout, no retry.
     *
     * @throws RequestException|ConnectionException
     */
    public function markHistoryFailed(int $historyId): void
    {
        $this->buildClient(withRetry: false)
            ->timeout(120)
            ->post(sprintf('/api/%s/history/failed/%d', $this->apiVersion, $historyId))
            ->throw();
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getNotifications(): array
    {
        return $this->buildClient()
            ->get(sprintf('/api/%s/notification', $this->apiVersion))
            ->throw()
            ->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function createNotification(array $payload): array
    {
        return $this->buildClient()
            ->post(sprintf('/api/%s/notification', $this->apiVersion), $payload)
            ->throw()
            ->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function updateNotification(int $id, array $payload): array
    {
        return $this->buildClient()
            ->put(sprintf('/api/%s/notification/%d', $this->apiVersion, $id), $payload)
            ->throw()
            ->json() ?? [];
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteNotification(int $id): void
    {
        $this->buildClient()
            ->delete(sprintf('/api/%s/notification/%d', $this->apiVersion, $id))
            ->throw();
    }

    /**
     * Upsert a Webhook-implementation notification on the upstream service so
     * the user doesn't have to copy/paste anything into Sonarr/Radarr/Prowlarr.
     *
     * Looks up an existing notification by `$notificationName`. If found, PUT
     * with the new url/method; otherwise POST a fresh one. Returns the upstream
     * payload so the caller can surface the assigned id.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function configureWebhook(string $callbackUrl, string $notificationName = 'MediaManager'): array
    {
        $existing = collect($this->getNotifications())
            ->first(static fn (array $entry): bool => ($entry['name'] ?? null) === $notificationName
                && ($entry['implementation'] ?? null) === 'Webhook');

        // Sonarr/Radarr/Prowlarr each accept a different subset of on* event
        // flags; unknown keys are silently dropped upstream, so we send the
        // union and let each service ignore what it doesn't recognise.
        $payload = [
            'name' => $notificationName,
            'implementation' => 'Webhook',
            'implementationName' => 'Webhook',
            'configContract' => 'WebhookSettings',
            'onGrab' => true,
            'onDownload' => true,
            'onUpgrade' => true,
            'onRename' => true,
            'onSeriesAdd' => true,
            'onSeriesDelete' => true,
            'onEpisodeFileDelete' => true,
            'onEpisodeFileDeleteForUpgrade' => true,
            'onMovieAdded' => true,
            'onMovieDelete' => true,
            'onMovieFileDelete' => true,
            'onMovieFileDeleteForUpgrade' => true,
            'onHealthIssue' => true,
            'onHealthRestored' => true,
            'onApplicationUpdate' => true,
            'onManualInteractionRequired' => true,
            'includeHealthWarnings' => true,
            'tags' => [],
            'fields' => [
                ['name' => 'url', 'value' => $callbackUrl],
                ['name' => 'method', 'value' => 1],
                ['name' => 'username', 'value' => ''],
                ['name' => 'password', 'value' => ''],
            ],
        ];

        if (is_array($existing) && isset($existing['id'])) {
            $payload['id'] = (int) $existing['id'];

            return $this->updateNotification((int) $existing['id'], $payload);
        }

        return $this->createNotification($payload);
    }
}
