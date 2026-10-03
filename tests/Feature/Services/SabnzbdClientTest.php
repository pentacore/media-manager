<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\Sabnzbd\SabnzbdRefused;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'test-api-key',
    ]);

    $this->client = new SabnzbdClient($this->connection);
});

test('getVersion sends mode=version with the apikey parameter and output=json', function (): void {
    Http::fake([
        'sab.local:8080/api*' => Http::response(['version' => '4.2.0']),
    ]);

    $result = $this->client->getVersion();

    expect($result['version'])->toBe('4.2.0');
    // SABnzbd reads the key from request params only — no header alternative.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'apikey=test-api-key')
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
        'sab.local:8080/api*' => Http::response(['position' => 0]),
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

test('a read SABnzbd refuses or answers with something other than its JSON object throws instead of reading as empty', function (string $method, mixed $body): void {
    Http::fake(['sab.local:8080/api*' => Http::response($body, 200, is_string($body) && str_starts_with($body, '<') ? ['Content-Type' => 'text/html'] : [])]);

    expect(fn (): array => $this->client->{$method}())->toThrow(SabnzbdRefused::class);
})->with([
    'queue, wrong api key' => ['getQueue', ['status' => false, 'error' => 'API Key Incorrect']],
    'queue, error without status' => ['getQueue', ['error' => 'API Key Required']],
    'queue, login page' => ['getQueue', '<html><body>Sign in</body></html>'],
    'queue, json scalar' => ['getQueue', '42'],
    'queue, section is a string' => ['getQueue', ['queue' => 'busy']],
    'queue, no section' => ['getQueue', ['version' => '4.2.0']],
    'history, wrong api key' => ['getHistory', ['status' => false, 'error' => 'API Key Incorrect']],
    'history, section is a string' => ['getHistory', ['history' => 'none']],
    'version, login page' => ['getVersion', '<html><body>Sign in</body></html>'],
    'full status, refused' => ['getFullStatus', ['status' => false, 'error' => 'API Key Incorrect']],
]);

test('a refused read is a RequestException with a fixed message that never quotes the body', function (): void {
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => false, 'error' => 'API Key Incorrect, see /config/sabnzbd.ini'])]);

    expect(fn (): array => $this->client->getQueue())->toThrow(function (SabnzbdRefused $sabnzbdRefused): void {
        expect($sabnzbdRefused)->toBeInstanceOf(RequestException::class)
            ->and($sabnzbdRefused->getMessage())->toBe('SABnzbd refused the request.')
            ->and($sabnzbdRefused->response->status())->toBe(200);
    });
});

test('the fixed message survives report(), which would otherwise rebuild it from the raw body', function (): void {
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => false, 'error' => 'API Key Incorrect, see /config/sabnzbd.ini'])]);

    try {
        $this->client->getQueue();
    } catch (SabnzbdRefused $sabnzbdRefused) {
        $sabnzbdRefused->report();

        expect($sabnzbdRefused->getMessage())->toBe('SABnzbd refused the request.');

        return;
    }

    $this->fail('Expected SabnzbdRefused to be thrown.');
});

test('fullstatus answers with a status object, which is not a refusal', function (): void {
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => ['version' => '4.2.0', 'paused' => false]])]);

    expect($this->client->getFullStatus()['status']['version'])->toBe('4.2.0');
});

test('a priority change is accepted only when SABnzbd reports a queue position', function (mixed $body, bool $accepted): void {
    Http::fake(['sab.local:8080/api*' => Http::response($body)]);

    expect($this->client->changePriority('SABnzbd_nzo_3', 1))->toBe($accepted);
})->with([
    'new position' => [['position' => 2], true],
    'moved to the top' => [['position' => 0], true],
    'numeric string position' => [['position' => '4'], true],
    'status true' => [['status' => true], true],
    'unknown job' => [['position' => -1], false],
    'status false' => [['status' => false, 'error' => 'not found'], false],
    'empty object' => [[], false],
]);

test('a write answered with a bare JSON scalar reads as refused, not a TypeError', function (): void {
    Http::fake(['sab.local:8080/api*' => Http::response('true')]);

    expect($this->client->pauseQueue())->toBeFalse();
});
