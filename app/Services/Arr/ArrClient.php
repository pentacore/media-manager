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

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Api-Key' => $this->connection->api_key])
            ->timeout(10)
            ->connectTimeout(3)
            ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this))
            ->retry(
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
}
