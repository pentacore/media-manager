<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Sabnzbd\SabnzbdClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new SabnzbdClient($this->connection);
});

test('getVersion sends mode=version with X-Apikey header and output=json', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response(['version' => '4.2.0']),
    ]);

    $result = $this->client->getVersion();

    expect($result['version'])->toBe('4.2.0');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Apikey', 'test-api-key')
        && str_contains($request->url(), 'mode=version')
        && str_contains($request->url(), 'output=json'));
});

test('getQueue returns the queue payload', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response([
            'queue' => [
                'paused' => false,
                'speed' => '1.2 M',
                'slots' => [['nzo_id' => 'abc', 'filename' => 'episode']],
            ],
        ]),
    ]);

    $queue = $this->client->getQueue();

    expect($queue['paused'])->toBeFalse();
    expect($queue['slots'])->toHaveCount(1);
});

test('getHistory passes last_history_update when sinceUnix provided', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response([
            'history' => ['slots' => []],
        ]),
    ]);

    $this->client->getHistory(sinceUnix: 1700000000);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'mode=history')
        && str_contains($request->url(), 'last_history_update=1700000000'));
});

test('pauseSlot sends queue/pause/{nzo} request', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response(['status' => true]),
    ]);

    expect($this->client->pauseSlot('NZO-1'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'mode=queue')
        && str_contains($request->url(), 'name=pause')
        && str_contains($request->url(), 'value=NZO-1'));
});

test('deleteSlot sends queue/delete/{nzo} request', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response(['status' => true]),
    ]);

    expect($this->client->deleteSlot('NZO-2'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'name=delete')
        && str_contains($request->url(), 'value=NZO-2'));
});

test('changePriority sends value+value2 with priority integer', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response(['status' => true]),
    ]);

    expect($this->client->changePriority('NZO-3', 2))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'name=priority')
        && str_contains($request->url(), 'value=NZO-3')
        && str_contains($request->url(), 'value2=2'));
});

test('getDiskSpace maps SABnzbd queue payload to Sonarr-shaped rows', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response([
            'queue' => [
                'diskspace1' => '120.5',
                'diskspacetotal1' => '500',
                'download_dir' => '/downloads/incoming',
                'diskspace2' => '800',
                'diskspacetotal2' => '2000',
                'complete_dir' => '/downloads/complete',
            ],
        ]),
    ]);

    $rows = $this->client->getDiskSpace();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['path'])->toBe('/downloads/incoming');
    expect($rows[0]['label'])->toBe('Incomplete');
    expect($rows[0]['freeSpace'])->toBe((int) round(120.5 * 1024 ** 3));
    expect($rows[0]['totalSpace'])->toBe(500 * 1024 ** 3);
    expect($rows[1]['label'])->toBe('Complete');
});
