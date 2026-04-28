<?php

use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'emby-test-key',
    ]);

    $this->client = new EmbyClient($this->connection);
});

test('sends X-Emby-Token header with requests', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response(['ServerName' => 'MyEmby']),
    ]);

    $this->client->getSystemInfo();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Emby-Token', 'emby-test-key'));
});

test('does not send X-Api-Key header', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response(['ServerName' => 'MyEmby']),
    ]);

    $this->client->getSystemInfo();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Api-Key'));
});

test('getSystemInfo returns system data', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response([
            'ServerName' => 'MyEmby',
            'Version' => '4.8.0.0',
            'OperatingSystem' => 'Linux',
        ]),
    ]);

    $result = $this->client->getSystemInfo();

    expect($result['ServerName'])->toBe('MyEmby');
    expect($result['Version'])->toBe('4.8.0.0');
});

test('getUsers returns user list', function (): void {
    Http::fake([
        'emby.local:8096/Users' => Http::response([
            ['Id' => 'user-1', 'Name' => 'Admin'],
            ['Id' => 'user-2', 'Name' => 'Guest'],
        ]),
    ]);

    $result = $this->client->getUsers();

    expect($result)->toHaveCount(2);
    expect($result[0]['Name'])->toBe('Admin');
});

test('getUserItems passes query params', function (): void {
    Http::fake([
        'emby.local:8096/Users/user-1/Items*' => Http::response([
            'Items' => [['Id' => 'item-1', 'Name' => 'Movie']],
            'TotalRecordCount' => 1,
        ]),
    ]);

    $result = $this->client->getUserItems('user-1', ['Limit' => 10, 'StartIndex' => 0]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Limit=10')
        && str_contains($request->url(), 'StartIndex=0'));

    expect($result['TotalRecordCount'])->toBe(1);
});

test('getActiveSessions returns sessions', function (): void {
    Http::fake([
        'emby.local:8096/Sessions' => Http::response([
            ['Id' => 'session-1', 'UserName' => 'Admin', 'NowPlayingItem' => ['Name' => 'Movie']],
        ]),
    ]);

    $result = $this->client->getActiveSessions();

    expect($result)->toHaveCount(1);
    expect($result[0]['UserName'])->toBe('Admin');
});

test('refreshLibrary sends POST', function (): void {
    Http::fake([
        'emby.local:8096/Library/Refresh' => Http::response([], 204),
    ]);

    $this->client->refreshLibrary();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/Library/Refresh'));
});

test('throws on server error', function (): void {
    Http::fake([
        'emby.local:8096/System/Info' => Http::response([], 500),
    ]);

    $this->client->getSystemInfo();
})->throws(RequestException::class);

test('markItemPlayed POSTs to PlayedItems endpoint', function (): void {
    Http::fake([
        'emby.local:8096/Users/user1/PlayedItems/item1*' => Http::response(['Played' => true]),
    ]);

    $result = $this->client->markItemPlayed('user1', 'item1');

    expect($result['Played'])->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/Users/user1/PlayedItems/item1'));
});

test('markItemUnplayed DELETEs the PlayedItems endpoint', function (): void {
    Http::fake([
        'emby.local:8096/Users/user1/PlayedItems/item1*' => Http::response(['Played' => false]),
    ]);

    $result = $this->client->markItemUnplayed('user1', 'item1');

    expect($result['Played'])->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/Users/user1/PlayedItems/item1'));
});
