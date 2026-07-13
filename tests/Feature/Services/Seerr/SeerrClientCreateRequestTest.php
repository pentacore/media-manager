<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'seerr-test-key',
    ]);

    $this->client = new SeerrClient($this->connection);
});

test('createRequest posts a tv request carrying seasons and userId', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 7]),
    ]);

    $result = $this->client->createRequest(1396, 'tv', [2], 12);

    expect($result['id'])->toBe(7);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/request')
        && $request->hasHeader('X-Api-Key', 'seerr-test-key')
        && $request->data() === [
            'mediaType' => 'tv',
            'mediaId' => 1396,
            'seasons' => [2],
            'userId' => 12,
        ]);
});

test('createRequest defaults tv seasons to all and omits userId when null', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 8]),
    ]);

    $this->client->createRequest(1396, 'tv');

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'mediaType' => 'tv',
        'mediaId' => 1396,
        'seasons' => 'all',
    ]);
});

test('createRequest omits the seasons field for a movie request', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 9]),
    ]);

    $this->client->createRequest(129, 'movie', 'all', 5);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data === [
            'mediaType' => 'movie',
            'mediaId' => 129,
            'userId' => 5,
        ] && ! array_key_exists('seasons', $data);
    });
});

test('createRequest throws on an invalid media type', function (): void {
    expect(fn () => $this->client->createRequest(1, 'anime'))
        ->toThrow(InvalidArgumentException::class);
});

test('createRequest busts the requests cache so a stale list is not served afterwards', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::sequence()
            // First getRequests (cached).
            ->push(['results' => [['id' => 1]]])
            // createRequest POST.
            ->push(['id' => 99])
            // getRequests after bust — must hit the network again.
            ->push(['results' => [['id' => 1], ['id' => 99]]]),
    ]);

    expect($this->client->getRequests(['take' => 10]))->toHaveKey('results');

    $this->client->createRequest(1396, 'tv');

    // Cache was busted, so this re-fetches and sees the new result set.
    $after = $this->client->getRequests(['take' => 10]);
    expect($after['results'])->toHaveCount(2);
});
