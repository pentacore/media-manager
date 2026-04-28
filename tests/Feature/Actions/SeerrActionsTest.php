<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrActions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);
});

test('cleanup_seerr_request sends DELETE to seerr', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/55' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'cleanup_seerr_request',
        'payload' => ['seerr_request_id' => 55],
    ]);

    $result = (new SeerrActions)->execute($request);

    expect($result)->toMatchArray(['seerr_request_id' => 55]);
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with((string) $r->url(), '/api/v1/request/55'));
});

test('throws when seerr_request_id is missing', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'cleanup_seerr_request',
        'payload' => [],
    ]);

    expect(fn (): array => (new SeerrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('approve_seerr_request POSTs to seerr approve endpoint', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/77/approve' => Http::response([], 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'approve_seerr_request',
        'payload' => ['seerr_request_id' => 77],
    ]);

    $result = (new SeerrActions)->execute($request);

    expect($result)->toMatchArray(['seerr_request_id' => 77, 'status' => 'approved']);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/api/v1/request/77/approve'));
});

test('decline_seerr_request POSTs to seerr decline endpoint', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/88/decline' => Http::response([], 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'decline_seerr_request',
        'payload' => ['seerr_request_id' => 88],
    ]);

    $result = (new SeerrActions)->execute($request);

    expect($result)->toMatchArray(['seerr_request_id' => 88, 'status' => 'declined']);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/api/v1/request/88/decline'));
});
