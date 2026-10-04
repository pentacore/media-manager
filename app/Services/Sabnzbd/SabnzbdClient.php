<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use App\Models\ServiceConnection;
use App\Support\UrlQueryRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wraps the SABnzbd JSON API. Every endpoint funnels through `/sabnzbd/api`
 * with a `mode` parameter. SABnzbd only accepts the API key as the `apikey`
 * query parameter (its `check_apikey` reads request params exclusively — no
 * header alternative exists), so the key unavoidably travels in the URL.
 * Every consumer that surfaces client exception messages must therefore
 * scrub them with {@see UrlQueryRedactor} before persisting,
 * broadcasting, or logging.
 *
 * @see https://sabnzbd.org/wiki/configuration/5.0/api
 */
class SabnzbdClient
{
    /**
     * SABnzbd job ids: `SABnzbd_nzo_` plus a tempfile-style suffix. Every
     * route taking an nzo_id is constrained by this so nothing else can ride
     * into the API query string.
     */
    public const string NZO_ID_PATTERN = 'SABnzbd_nzo_[A-Za-z0-9_]{1,64}';

    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
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
     * A write. SABnzbd answers a refused write with HTTP 200 and `status:
     * false`, which the bool-returning callers read; a body that is not a JSON
     * object reads as that refusal too.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function request(array $extra): array
    {
        $body = $this->send($extra)->json();

        return is_array($body) ? $body : [];
    }

    /**
     * A read. A refusal (`status: false`, an `error`) or a body that is not
     * a JSON object — or, with $section, whose section is not one — throws
     * instead of reading as an empty queue or history.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws SabnzbdRefused|RequestException|ConnectionException
     */
    private function read(array $extra, ?string $section = null): array
    {
        $response = $this->send($extra);
        $body = $response->json();

        throw_unless(is_array($body), SabnzbdRefused::class, $response, 'SABnzbd answered with a body that is not JSON data.');

        // fullstatus answers {"status": {...}}: only a literal false is a refusal.
        throw_if(($body['status'] ?? null) === false || (is_string($body['error'] ?? null) && trim($body['error']) !== ''), SabnzbdRefused::class, $response, 'SABnzbd refused the request.');

        if ($section === null) {
            return $body;
        }

        if (! is_array($body[$section] ?? null)) {
            throw new SabnzbdRefused($response, sprintf('SABnzbd answered without a %s section.', $section));
        }

        return $body[$section];
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws RequestException|ConnectionException
     */
    private function send(array $extra): Response
    {
        $params = array_merge(['output' => 'json', 'apikey' => $this->connection->api_key], $extra);

        return $this->buildClient()->get('/api', $params)->throw();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SabnzbdRefused|RequestException|ConnectionException
     */
    public function getVersion(): array
    {
        return $this->read(['mode' => 'version']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SabnzbdRefused|RequestException|ConnectionException
     */
    public function getFullStatus(): array
    {
        return $this->read(['mode' => 'fullstatus']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SabnzbdRefused|RequestException|ConnectionException
     */
    public function getQueue(int $start = 0, int $limit = 50): array
    {
        return $this->read([
            'mode' => 'queue',
            'start' => $start,
            'limit' => $limit,
        ], 'queue');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SabnzbdRefused|RequestException|ConnectionException
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

        return $this->read($params, 'history');
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
     * SABnzbd answers a priority change with the job's new queue position,
     * `-1` when it has no job with that id — not a status flag. A refusal
     * (`status: false`) carries no position either.
     *
     * @throws RequestException|ConnectionException
     */
    public function changePriority(string $nzoId, int $priority): bool
    {
        $body = $this->request([
            'mode' => 'queue',
            'name' => 'priority',
            'value' => $nzoId,
            'value2' => $priority,
        ]);
        $position = $body['position'] ?? null;

        return (is_numeric($position) && (int) $position >= 0) || ($body['status'] ?? null) === true;
    }

    /**
     * Translate SABnzbd's queue payload into the Sonarr/Radarr shape so the existing
     * Service Health disk-space tile renders unchanged.
     *
     * @return array<int, array{path: ?string, label: ?string, freeSpace: ?int, totalSpace: ?int}>
     *
     * @throws SabnzbdRefused|RequestException|ConnectionException
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
     * `value` is a percentage of SABnzbd's configured maximum ("50"), an
     * absolute rate ("500K", "5M"), or null to remove the limit (SABnzbd
     * treats an empty value as "no limit").
     *
     * @throws RequestException|ConnectionException
     */
    public function setSpeedLimit(?string $value): bool
    {
        return (bool) ($this->request([
            'mode' => 'config',
            'name' => 'speedlimit',
            'value' => $value ?? '',
        ])['status'] ?? false);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function retryHistory(string $nzoId): bool
    {
        return (bool) ($this->request(['mode' => 'retry', 'value' => $nzoId])['status'] ?? false);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteHistory(string $nzoId, bool $withFiles): bool
    {
        $params = ['mode' => 'history', 'name' => 'delete', 'value' => $nzoId];

        if ($withFiles) {
            $params['del_files'] = 1;
        }

        return (bool) ($this->request($params)['status'] ?? false);
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
