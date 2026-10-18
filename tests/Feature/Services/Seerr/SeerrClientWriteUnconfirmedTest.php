<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrWriteUnconfirmed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    Cache::store('array')->flush();
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
});

// Every Seerr write. Shared by both tests.
dataset('seerr writes', [
    'create a request' => [fn (SeerrClient $seerrClient): mixed => $seerrClient->createRequest(603, 'movie')],
    'update a request' => [fn (SeerrClient $seerrClient): mixed => $seerrClient->updateRequest(7, ['mediaType' => 'movie', 'mediaId' => 603])],
    'approve a request' => [fn (SeerrClient $seerrClient): mixed => $seerrClient->updateRequestStatus(7, 'approve')],
    'decline a request' => [fn (SeerrClient $seerrClient): mixed => $seerrClient->updateRequestStatus(7, 'decline')],
    'retry a request' => [fn (SeerrClient $seerrClient): mixed => $seerrClient->retryRequest(7)],
    'delete a request' => [fn (SeerrClient $seerrClient): mixed => $seerrClient->deleteRequest(7)],
]);

test('a Seerr write answered with a login page or a JSON scalar is unconfirmed, after one request', function (Closure $write, string $body): void {
    Http::fake(['seerr.local:5055/api/v1/*' => Http::response($body)]);

    expect(fn () => $write(new SeerrClient($this->connection)))
        ->toThrow(SeerrWriteUnconfirmed::class, SeerrWriteUnconfirmed::MESSAGE);
    Http::assertSentCount(1);
})->with('seerr writes')->with([
    'a login page' => ['<html><body>Sign in</body></html>'],
    'a JSON scalar' => ['true'],
]);

test('a Seerr write answered with JSON data or an empty body succeeds', function (Closure $write, string $body, int $status): void {
    Http::fake(['seerr.local:5055/api/v1/*' => Http::response($body, $status)]);

    $write(new SeerrClient($this->connection));

    Http::assertSentCount(1);
})->with('seerr writes')->with([
    'a JSON object' => ['{"id":7}', 200],
    'an empty 204' => ['', 204],
]);
