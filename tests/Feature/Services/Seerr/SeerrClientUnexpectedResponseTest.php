<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrUnexpectedResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
});

// Every Seerr read: the call, and a body it accepts. Shared by two tests.
dataset('seerr reads', [
    'status' => [fn (SeerrClient $seerrClient): array => $seerrClient->getStatus(), ['version' => '2.0.0']],
    'requests' => [fn (SeerrClient $seerrClient): array => $seerrClient->getRequests(['take' => 10]), ['pageInfo' => ['pages' => 1], 'results' => []]],
    'request count' => [fn (SeerrClient $seerrClient): array => $seerrClient->getRequestCount(), ['total' => 0]],
    'request by id' => [fn (SeerrClient $seerrClient): array => $seerrClient->getRequestById(7), ['id' => 7]],
    'request by id, uncached' => [fn (SeerrClient $seerrClient): array => $seerrClient->getRequestByIdUncached(7), ['id' => 7]],
    'movie details' => [fn (SeerrClient $seerrClient): array => $seerrClient->getMovieDetails(603), ['id' => 603]],
    'TV details' => [fn (SeerrClient $seerrClient): array => $seerrClient->getTvDetails(1399), ['id' => 1399]],
    'search' => [fn (SeerrClient $seerrClient): array => $seerrClient->search('example'), ['results' => []]],
    'users' => [fn (SeerrClient $seerrClient): array => $seerrClient->getUsers(), ['results' => []]],
    'discover movies' => [fn (SeerrClient $seerrClient): array => $seerrClient->discoverMovies(), ['results' => []]],
    'discover TV' => [fn (SeerrClient $seerrClient): array => $seerrClient->discoverTv(), ['results' => []]],
    'trending' => [fn (SeerrClient $seerrClient): array => $seerrClient->discoverTrending(), ['results' => []]],
    'upcoming' => [fn (SeerrClient $seerrClient): array => $seerrClient->discoverUpcoming('movie'), ['results' => []]],
]);

test('a Seerr read answered with a login page or a JSON scalar fails instead of reading as empty', function (Closure $read, array $accepted, string $body): void {
    Http::fake(['seerr.local:5055/api/v1/*' => Http::response($body)]);

    expect(fn () => $read(new SeerrClient($this->connection)))
        ->toThrow(SeerrUnexpectedResponse::class, 'Seerr answered with a body that is not JSON data.');
})->with('seerr reads')->with([
    'a login page' => ['<html><body>Sign in</body></html>'],
    'a JSON scalar' => ['"ok"'],
]);

test('a refused Seerr read is not cached: the next read reaches Seerr again', function (Closure $read, array $accepted): void {
    $state = new class
    {
        public int $calls = 0;
    };
    Http::fake(['seerr.local:5055/api/v1/*' => function () use ($state, $accepted) {
        $state->calls++;

        return $state->calls === 1 ? Http::response('<html><body>Sign in</body></html>') : Http::response($accepted);
    }]);
    $seerrClient = new SeerrClient($this->connection);

    expect(fn () => $read($seerrClient))->toThrow(SeerrUnexpectedResponse::class);
    expect($read($seerrClient))->toBe($accepted)
        ->and($state->calls)->toBe(2);
})->with('seerr reads');

test('a media detail answered with a login page is left out of the batch and not cached', function (): void {
    $state = new class
    {
        public int $calls = 0;
    };
    Http::fake(['seerr.local:5055/api/v1/movie/603' => function () use ($state) {
        $state->calls++;

        return $state->calls === 1
            ? Http::response('<html><body>Sign in</body></html>')
            : Http::response(['id' => 603, 'title' => 'Example Movie']);
    }]);
    $seerrClient = new SeerrClient($this->connection);

    expect($seerrClient->getMediaDetailsBatch([['movie', 603]]))->toBe([])
        ->and($seerrClient->getMediaDetailsBatch([['movie', 603]]))->toBe(['movie:603' => ['id' => 603, 'title' => 'Example Movie']])
        ->and($state->calls)->toBe(2);
});
