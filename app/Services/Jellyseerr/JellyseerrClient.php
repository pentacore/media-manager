<?php

declare(strict_types=1);

namespace App\Services\Jellyseerr;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class JellyseerrClient
{
    protected string $apiVersion = 'v1';

    public function __construct(
        protected ServiceConnection $connection,
    ) {}

    protected function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->connection->url, '/'))
            ->withHeaders(['X-Api-Key' => $this->connection->api_key])
            ->timeout(10)
            ->connectTimeout(3)
            ->retry(
                times: 3,
                sleepMilliseconds: fn (int $attempt): int => $attempt * 500,
                when: fn (\Throwable $throwable): bool => $throwable instanceof ConnectionException
                    || ($throwable instanceof RequestException && $throwable->response->serverError()),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getStatus(): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/status', $this->apiVersion))->throw()->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getRequests(array $params = []): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/request', $this->apiVersion), $params)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getRequestById(int $id): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/request/%d', $this->apiVersion, $id))->throw()->json();
    }

    /**
     * @throws RequestException|ConnectionException
     */
    public function deleteRequest(int $id): void
    {
        $this->buildClient()->delete(sprintf('/api/%s/request/%d', $this->apiVersion, $id))->throw();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function search(string $query): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/search', $this->apiVersion), ['query' => $query])->throw()->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function getUsers(array $params = []): array
    {
        return $this->buildClient()->get(sprintf('/api/%s/user', $this->apiVersion), $params)->throw()->json();
    }
}
