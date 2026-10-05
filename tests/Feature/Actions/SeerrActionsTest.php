<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Seerr\SeerrActions;
use App\Services\Seerr\SeerrRequestBusy;
use App\Services\Seerr\SeerrRequestLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

    $result = resolve(SeerrActions::class)->execute($request);

    expect($result)->toMatchArray(['seerr_request_id' => 55]);
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with((string) $r->url(), '/api/v1/request/55'));
});

test('throws when seerr_request_id is missing', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'cleanup_seerr_request',
        'payload' => [],
    ]);

    expect(fn (): array => resolve(SeerrActions::class)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('approve_seerr_request POSTs to seerr approve endpoint', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/77/approve' => Http::response([], 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'approve_seerr_request',
        'payload' => ['seerr_request_id' => 77],
    ]);

    $result = resolve(SeerrActions::class)->execute($request);

    expect($result)->toMatchArray(['seerr_request_id' => 77, 'status' => 'approved']);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/api/v1/request/77/approve'));
});

test('decline_seerr_request POSTs to seerr decline endpoint', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/88/decline' => Http::response([], 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'decline_seerr_request',
        'payload' => ['seerr_request_id' => 88],
    ]);

    $result = resolve(SeerrActions::class)->execute($request);

    expect($result)->toMatchArray(['seerr_request_id' => 88, 'status' => 'declined']);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/api/v1/request/88/decline'));
});

test('a queued approve waits for a held request lock and then gives up without calling Seerr', function (string $type): void {
    Sleep::fake(syncWithCarbon: true);
    Cache::lock(SeerrRequestLock::key(ServiceConnection::query()->sole()->id, 77), SeerrRequestLock::TTL_SECONDS)->get();

    $request = ActionRequest::factory()->create([
        'type' => $type,
        'payload' => ['seerr_request_id' => 77],
    ]);

    expect(fn (): array => resolve(SeerrActions::class)->execute($request))->toThrow(SeerrRequestBusy::class);
    Http::assertNothingSent();
})->with(['approve_seerr_request', 'decline_seerr_request']);

test('a malformed Seerr request id says whether it is missing or invalid, and nothing is sent', function (string $type, array $payload, string $message): void {
    Http::fake(['seerr.local:5055/*' => Http::response([], 500)]);

    expect(fn (): array => resolve(SeerrActions::class)->execute(ActionRequest::factory()->create(['type' => $type, 'payload' => $payload])))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'cleanup without an id' => ['cleanup_seerr_request', [], 'seerr_request_id is required'],
    'approve with a non-numeric id' => ['approve_seerr_request', ['seerr_request_id' => 'abc'], 'seerr_request_id must be a positive integer'],
    'decline with a zero id' => ['decline_seerr_request', ['seerr_request_id' => 0], 'seerr_request_id must be a positive integer'],
]);
