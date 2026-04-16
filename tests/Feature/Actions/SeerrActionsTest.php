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
