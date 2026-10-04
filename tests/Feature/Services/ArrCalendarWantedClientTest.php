<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Arr\ArrUnexpectedResponse;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('the Sonarr calendar asks for unmonitored items with their series and caches the range', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']));
    Http::fake(['sonarr.local:8989/api/v3/calendar*' => Http::response([['id' => 1]])]);
    $start = CarbonImmutable::parse('2026-08-25T00:00:00Z');
    $end = CarbonImmutable::parse('2026-10-08T00:00:00Z');

    $client->getCalendar($start, $end);
    $client->getCalendar($start, $end);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['start'] === '2026-08-25T00:00:00Z'
        && $request['end'] === '2026-10-08T00:00:00Z'
        && $request['unmonitored'] === 'true'
        && $request['includeSeries'] === 'true');
});

test('wanted lists page, sort by air date and pass the monitored switch', function (): void {
    $sonarr = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']));
    $radarr = new RadarrClient(ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']));
    Http::fake([
        'sonarr.local:8989/api/v3/wanted/*' => Http::response(['page' => 2, 'totalRecords' => 41, 'records' => []]),
        'radarr.local:7878/api/v3/wanted/*' => Http::response(['page' => 1, 'totalRecords' => 3, 'records' => []]),
    ]);

    expect($sonarr->getWanted('missing', 2, 20, true)['totalRecords'])->toBe(41);
    $radarr->getWanted('cutoff', 1, 20, false);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local:8989/api/v3/wanted/missing')
        && $request['page'] === 2 && $request['pageSize'] === 20 && $request['monitored'] === 'true'
        && $request['sortKey'] === 'episodes.airDateUtc' && $request['sortDirection'] === 'descending' && $request['includeSeries'] === 'true');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'radarr.local:7878/api/v3/wanted/cutoff')
        && $request['monitored'] === 'false' && $request['sortKey'] === 'movieMetadata.digitalRelease');
});

test('an unknown wanted list is rejected', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']));

    expect(fn (): array => $client->getWanted('queue', 1, 20, true))->toThrow(InvalidArgumentException::class);
});

test('a calendar body with a null or scalar entry skips it and still returns the valid items', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']));
    Http::fake(['sonarr.local:8989/api/v3/calendar*' => Http::response([['id' => 1], null, 'not-an-episode', 42])]);

    expect($client->getCalendar(CarbonImmutable::now(), CarbonImmutable::now()))->toBe([['id' => 1]]);
});

test('an object-shaped calendar body yields no items without throwing', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']));
    Http::fake(['sonarr.local:8989/api/v3/calendar*' => Http::response(['error' => 'Bad Request'])]);

    expect($client->getCalendar(CarbonImmutable::now(), CarbonImmutable::now()))->toBe([]);
});

test('wanted records with a null or scalar entry are dropped while the pagination keys survive', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']));
    Http::fake(['sonarr.local:8989/api/v3/wanted/*' => Http::response([
        'page' => 1, 'totalRecords' => 2, 'records' => [['id' => 1], null, 'garbage'],
    ])]);

    expect($client->getWanted('missing', 1, 20, true))->toBe(['page' => 1, 'totalRecords' => 2, 'records' => [['id' => 1]]]);
});

test('a wanted body that is not JSON data is an upstream failure, not an empty list', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']));
    Http::fake(['sonarr.local:8989/api/v3/wanted/*' => Http::response('oops')]);

    expect(fn (): array => $client->getWanted('missing', 1, 20, true))->toThrow(ArrUnexpectedResponse::class);
});

test('a calendar body that is not JSON data is an upstream failure, not an empty calendar', function (): void {
    $client = new SonarrClient(ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']));
    Http::fake(['sonarr.local:8989/api/v3/calendar*' => Http::response('<html>Sign in</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn (): array => $client->getCalendar(CarbonImmutable::now(), CarbonImmutable::now()))->toThrow(ArrUnexpectedResponse::class);
});
