<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Arr\RemoveStuckDownloadActions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
});

function fakeQueue(array $records): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/queue?*' => Http::response(['records' => $records]),
        'sonarr.local:8989/api/v3/queue/*' => Http::response(null, 200),
    ]);
}

test('removes all queue rows matching the downloadId without blocklisting', function (): void {
    // Season pack: two queue rows share one downloadId.
    fakeQueue([
        ['id' => 55, 'downloadId' => 'dl-1'],
        ['id' => 56, 'downloadId' => 'dl-1'],
        ['id' => 57, 'downloadId' => 'other'],
    ]);

    $request = ActionRequest::factory()->create([
        'type' => 'remove_stuck_download',
        'target_service' => 'sonarr',
        'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1'],
    ]);

    $result = resolve(RemoveStuckDownloadActions::class)->execute($request);

    expect($result)->toMatchArray(['service' => 'sonarr', 'download_id' => 'dl-1', 'removed' => 2, 'blocklist' => false]);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/queue/55')
        && str_contains((string) $r->url(), 'blocklist=false')
        && str_contains((string) $r->url(), 'removeFromClient=true'));
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/queue/56'));
    // The unrelated row is left alone.
    Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/queue/57'));
});

test('blocklists the release when the payload requests it', function (): void {
    fakeQueue([
        ['id' => 55, 'downloadId' => 'dl-1'],
    ]);

    $request = ActionRequest::factory()->create([
        'type' => 'remove_stuck_download',
        'target_service' => 'sonarr',
        'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1', 'blocklist' => true],
    ]);

    $result = resolve(RemoveStuckDownloadActions::class)->execute($request);

    expect($result)->toMatchArray(['service' => 'sonarr', 'download_id' => 'dl-1', 'removed' => 1, 'blocklist' => true]);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/queue/55')
        && str_contains((string) $r->url(), 'blocklist=true')
        && str_contains((string) $r->url(), 'removeFromClient=true'));
});

test('throws when no queue row matches the downloadId', function (): void {
    fakeQueue([['id' => 99, 'downloadId' => 'something-else']]);

    $request = ActionRequest::factory()->create([
        'type' => 'remove_stuck_download',
        'target_service' => 'sonarr',
        'payload' => ['service' => 'sonarr', 'download_id' => 'dl-1'],
    ]);

    expect(fn (): array => resolve(RemoveStuckDownloadActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class);
});

test('throws for the wrong action type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'delete_series', 'payload' => []]);

    expect(fn (): array => resolve(RemoveStuckDownloadActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class);
});
