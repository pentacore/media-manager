<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wraps the SABnzbd JSON API. Every endpoint funnels through `/sabnzbd/api`
 * with a `mode` parameter; auth via the `X-Apikey` header so the key never
 * lands in URL logs.
 *
 * @see https://sabnzbd.org/wiki/advanced/api
 */
class SabnzbdClient
{
    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Apikey' => $this->connection->api_key])
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function request(array $extra): array
    {
        $params = array_merge(['output' => 'json', 'apikey' => $this->connection->api_key], $extra);

        return $this->buildClient()->get('/api', $params)->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getVersion(): array
    {
        return $this->request(['mode' => 'version']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getFullStatus(): array
    {
        return $this->request(['mode' => 'fullstatus']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getQueue(int $start = 0, int $limit = 50): array
    {
        $payload = $this->request([
            'mode' => 'queue',
            'start' => $start,
            'limit' => $limit,
        ]);

        return $payload['queue'] ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getHistory(int $start = 0, int $limit = 50, ?int $sinceUnix = null): array
    {
        $params = [
            'mode' => 'history',
            'start' => $start,
            'limit' => $limit,
        ];

        if ($sinceUnix !== null) {
            $params['last_history_update'] = $sinceUnix;
        }

        $payload = $this->request($params);

        return $payload['history'] ?? [];
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function pauseQueue(): bool
    {
        return (bool) ($this->request(['mode' => 'pause'])['status'] ?? false);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function resumeQueue(): bool
    {
        return (bool) ($this->request(['mode' => 'resume'])['status'] ?? false);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function pauseSlot(string $nzoId): bool
    {
        return $this->slotAction('pause', $nzoId);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function resumeSlot(string $nzoId): bool
    {
        return $this->slotAction('resume', $nzoId);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteSlot(string $nzoId): bool
    {
        return $this->slotAction('delete', $nzoId);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function changePriority(string $nzoId, int $priority): bool
    {
        return (bool) ($this->request([
            'mode' => 'queue',
            'name' => 'priority',
            'value' => $nzoId,
            'value2' => $priority,
        ])['status'] ?? false);
    }

    /**
     * Translate SABnzbd's queue payload into the Sonarr/Radarr shape so the existing
     * Service Health disk-space tile renders unchanged.
     *
     * @return array<int, array{path: ?string, label: ?string, freeSpace: ?int, totalSpace: ?int}>
     *
     * @throws RequestException|ConnectionException
     */
    public function getDiskSpace(): array
    {
        $queue = $this->getQueue();

        $rows = [];

        foreach ([1, 2] as $slot) {
            $free = $queue['diskspace'.$slot] ?? null;
            $total = $queue['diskspacetotal'.$slot] ?? null;

            if ($free === null && $total === null) {
                continue;
            }

            $rows[] = [
                'path' => $slot === 1
                    ? ($queue['download_dir'] ?? null)
                    : ($queue['complete_dir'] ?? null),
                'label' => $slot === 1 ? 'Incomplete' : 'Complete',
                'freeSpace' => $free !== null ? (int) round(((float) $free) * 1024 ** 3) : null,
                'totalSpace' => $total !== null ? (int) round(((float) $total) * 1024 ** 3) : null,
            ];
        }

        return $rows;
    }

    /**
     * @throws RequestException|ConnectionException
     */
    private function slotAction(string $action, string $nzoId): bool
    {
        return (bool) ($this->request([
            'mode' => 'queue',
            'name' => $action,
            'value' => $nzoId,
        ])['status'] ?? false);
    }
}
