<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Jellyseerr\JellyseerrActions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->jellyseerr()->create([
        'url' => 'http://jellyseerr.local:5055',
        'api_key' => 'k',
    ]);
});

test('cleanup_jellyseerr_request sends DELETE to jellyseerr', function (): void {
    Http::fake(['jellyseerr.local:5055/api/v1/request/55' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'cleanup_jellyseerr_request',
        'payload' => ['jellyseerr_request_id' => 55],
    ]);

    $result = (new JellyseerrActions)->execute($request);

    expect($result)->toMatchArray(['jellyseerr_request_id' => 55]);
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with((string) $r->url(), '/api/v1/request/55'));
});

test('throws when jellyseerr_request_id is missing', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'cleanup_jellyseerr_request',
        'payload' => [],
    ]);

    expect(fn (): array => (new JellyseerrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});
