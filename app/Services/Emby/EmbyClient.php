<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getSystemInfo(): array
    {
        return $this->buildClient()->get('/System/Info')->throw()->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getUsers(): array
    {
        return $this->buildClient()->get('/Users')->throw()->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getUserItems(string $userId, array $params = []): array
    {
        return $this->buildClient()->get(sprintf('/Users/%s/Items', $userId), $params)->throw()->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function getActiveSessions(): array
    {
        return $this->buildClient()->get('/Sessions')->throw()->json() ?? [];
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
     * @throws RequestException|ConnectionException
     */
    public function markItemPlayed(string $userId, string $itemId): array
    {
        return $this->buildClient()
            ->post(sprintf('/Users/%s/PlayedItems/%s', $userId, $itemId))
            ->throw()
            ->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function markItemUnplayed(string $userId, string $itemId): array
    {
        return $this->buildClient()
            ->delete(sprintf('/Users/%s/PlayedItems/%s', $userId, $itemId))
            ->throw()
            ->json() ?? [];
    }
}
