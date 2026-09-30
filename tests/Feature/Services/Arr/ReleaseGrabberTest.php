<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Arr\ReleaseGrabber;
use App\Services\Arr\ReleaseGrabFailed;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('a 404 response is reported as an expired release', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response(['message' => 'not found'], 404)]);

    expect(fn (): null => new ReleaseGrabber()->grab(new SonarrClient($connection), 'Sonarr', 'g-1', 3))
        ->toThrow(ReleaseGrabFailed::class, 'Run the interactive search again');
});

test('a 5xx response is reported as an unconfirmed grab, not a definitive rejection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response('boom', 503)]);

    expect(fn (): null => new ReleaseGrabber()->grab(new SonarrClient($connection), 'Sonarr', 'g-1', 3))
        ->toThrow(ReleaseGrabFailed::class, 'did not confirm the grab');
});

test('a lost connection is reported as an unconfirmed grab', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/release' => fn (): never => throw new ConnectionException('reset')]);

    expect(fn (): null => new ReleaseGrabber()->grab(new SonarrClient($connection), 'Sonarr', 'g-1', 3))
        ->toThrow(ReleaseGrabFailed::class, 'did not confirm the grab');
});

test('a 4xx response other than 404 is not swallowed', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response(['message' => 'bad request'], 400)]);

    expect(fn (): null => new ReleaseGrabber()->grab(new SonarrClient($connection), 'Sonarr', 'g-1', 3))
        ->toThrow(RequestException::class);
});
