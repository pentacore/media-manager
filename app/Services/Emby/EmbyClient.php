<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * @see https://swagger.emby.media/openapi.json for up-to-date openApi Spec
 */
class EmbyClient
{
    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Emby-Token' => $this->connection->api_key])
            ->timeout(10)
            ->withUserAgent('MediaManager/'.config('app.version').' '.class_basename($this))
            ->connectTimeout(3)
            ->retry(
                times: 3,
                sleepMilliseconds: fn (int $attempt): int => $attempt * 500,
                when: fn (Throwable $throwable): bool => $throwable instanceof ConnectionException
                    || ($throwable instanceof RequestException && $throwable->response->serverError()),
                throw: false,
            );
    }

    /**
     * A 200 that is not JSON data (an SSO or reverse-proxy login page, an
     * HTML error page) is a failed request, never an empty/healthy system
     * info — otherwise a login page in front of Emby would read as Healthy.
     *
     * @return array<string, mixed>
     *
     * @throws EmbyUnexpectedResponse|RequestException|ConnectionException
     */
    public function getSystemInfo(): array
    {
        $response = $this->buildClient()->get('/System/Info')->throw();
        $info = $response->json();

        throw_unless(is_array($info), EmbyUnexpectedResponse::class, $response, 'Emby answered with a body that is not JSON data.');

        return $info;
    }

    /**
     * A 200 that is not a JSON list (an SSO or reverse-proxy login page, an
     * error object) is a failed request, never an empty user list.
     *
     * @return list<mixed>
     *
     * @throws EmbyUnexpectedResponse|RequestException|ConnectionException
     */
    public function getUsers(): array
    {
        $response = $this->buildClient()->get('/Users')->throw();
        $users = $response->json();

        throw_if(! is_array($users) || ! array_is_list($users), EmbyUnexpectedResponse::class, $response);

        return $users;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws EmbyUnexpectedResponse|RequestException|ConnectionException
     */
    public function getUserItems(string $userId, array $params = []): array
    {
        return $this->jsonData($this->buildClient()->get(sprintf('/Users/%s/Items', $userId), $params)->throw(), 'Emby answered with a body that is not a JSON item list.');
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws EmbyUnexpectedResponse|RequestException|ConnectionException
     */
    public function getActiveSessions(): array
    {
        return $this->jsonData($this->buildClient()->get('/Sessions')->throw(), 'Emby answered with a body that is not a JSON session list.');
    }

    /**
     * The decoded body of a write. A blank body is a success; a body that is
     * not JSON data (a proxy login page) throws, so it never reads as a change
     * that happened. Callers must not retry it: whether it landed is unknown.
     *
     * @return array<array-key, mixed>
     *
     * @throws EmbyWriteUnconfirmed
     */
    private function confirmedWrite(Response $response): array
    {
        if (trim($response->body()) === '') {
            return [];
        }

        $body = $response->json();

        throw_unless(is_array($body), EmbyWriteUnconfirmed::class, $response);

        return $body;
    }

    /**
     * The decoded body of a read. A 200 that is not JSON data (an SSO or
     * reverse-proxy login page, an HTML error page) is a failed request,
     * never an empty result.
     *
     * @return array<array-key, mixed>
     *
     * @throws EmbyUnexpectedResponse
     */
    private function jsonData(Response $response, string $message): array
    {
        $body = $response->json();

        throw_unless(is_array($body), EmbyUnexpectedResponse::class, $response, $message);

        return $body;
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function refreshLibrary(): void
    {
        $this->buildClient()->post('/Library/Refresh')->throw();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EmbyWriteUnconfirmed|RequestException|ConnectionException
     */
    public function markItemPlayed(string $userId, string $itemId): array
    {
        return $this->confirmedWrite($this->buildClient()
            ->post(sprintf('/Users/%s/PlayedItems/%s', $userId, $itemId))
            ->throw());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EmbyWriteUnconfirmed|RequestException|ConnectionException
     */
    public function markItemUnplayed(string $userId, string $itemId): array
    {
        return $this->confirmedWrite($this->buildClient()
            ->delete(sprintf('/Users/%s/PlayedItems/%s', $userId, $itemId))
            ->throw());
    }
}
