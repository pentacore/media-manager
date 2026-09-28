<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyActions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'k',
    ]);
});

test('emby_library_scan POSTs to /Library/Refresh', function (): void {
    Http::fake(['emby.local:8096/Library/Refresh' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'emby_library_scan',
        'payload' => [],
    ]);

    $result = (new EmbyActions)->execute($request);

    expect($result)->toMatchArray(['triggered' => true]);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/Library/Refresh'));
});

test('execute throws for unknown type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'not_a_type', 'payload' => []]);

    expect(fn (): array => (new EmbyActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('a coalesced scan refreshes the emby server it was queued for', function (): void {
    $second = ServiceConnection::factory()->emby()->create(['url' => 'http://emby-2.local:8096', 'api_key' => 'k2']);
    Http::fake(['emby-2.local:8096/Library/Refresh' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'emby_library_scan',
        'payload' => ['emby_connection_id' => $second->id],
    ]);

    (new EmbyActions)->execute($request);

    Http::assertSent(fn ($r): bool => str_starts_with((string) $r->url(), 'http://emby-2.local:8096/Library/Refresh'));
});

test('a coalesced scan whose emby server was deactivated sends nothing', function (): void {
    $inactive = ServiceConnection::factory()->emby()->inactive()->create(['url' => 'http://emby-off.local:8096']);
    Http::fake();

    $request = ActionRequest::factory()->create([
        'type' => 'emby_library_scan',
        'payload' => ['emby_connection_id' => $inactive->id],
    ]);

    expect(fn (): array => (new EmbyActions)->execute($request))->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
});
