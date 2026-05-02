<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('getNotifications fetches /api/v3/notification', function (): void {
    Http::fake([
        'sonarr.local/api/v3/notification' => Http::response([
            ['id' => 7, 'name' => 'MediaManager', 'implementation' => 'Webhook'],
        ], 200),
    ]);

    $connection = ServiceConnection::factory()->sonarr()->make([
        'url' => 'http://sonarr.local',
        'api_key' => 'k',
    ]);

    $result = new SonarrClient($connection)->getNotifications();

    expect($result)->toHaveCount(1)
        ->and($result[0]['name'])->toBe('MediaManager');
});

test('configureWebhook POSTs when no matching notification exists', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'sonarr.local/api/v3/notification' => Http::sequence()
            ->push([], 200)
            ->push(['id' => 99, 'name' => 'MediaManager'], 201),
    ]);

    $connection = ServiceConnection::factory()->sonarr()->make([
        'url' => 'http://sonarr.local',
        'api_key' => 'k',
    ]);

    $result = new SonarrClient($connection)->configureWebhook(
        callbackUrl: 'https://app.local/api/webhooks/sonarr/1?token=t',
        notificationName: 'MediaManager',
    );

    expect($result['id'])->toBe(99);

    Http::assertSentInOrder([
        fn ($request): bool => $request->method() === 'GET'
            && str_ends_with((string) $request->url(), '/api/v3/notification'),
        function ($request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return $body['name'] === 'MediaManager'
                && $body['implementation'] === 'Webhook'
                && collect($body['fields'])->firstWhere('name', 'url')['value']
                    === 'https://app.local/api/webhooks/sonarr/1?token=t'
                && collect($body['fields'])->firstWhere('name', 'method')['value'] === 1;
        },
    ]);
});

test('configureWebhook PUTs when notification with same name exists', function (): void {
    Http::fake([
        'sonarr.local/api/v3/notification' => Http::response([
            [
                'id' => 42,
                'name' => 'MediaManager',
                'implementation' => 'Webhook',
                'fields' => [
                    ['name' => 'url', 'value' => 'http://stale'],
                    ['name' => 'method', 'value' => 1],
                ],
            ],
        ], 200),
        'sonarr.local/api/v3/notification/42' => Http::response([
            'id' => 42,
            'name' => 'MediaManager',
        ], 202),
    ]);

    $connection = ServiceConnection::factory()->sonarr()->make([
        'url' => 'http://sonarr.local',
        'api_key' => 'k',
    ]);

    $result = new SonarrClient($connection)->configureWebhook(
        callbackUrl: 'https://app.local/api/webhooks/sonarr/1?token=t',
        notificationName: 'MediaManager',
    );

    expect($result['id'])->toBe(42);

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'PUT') {
            return false;
        }

        return str_ends_with((string) $request->url(), '/api/v3/notification/42')
            && collect($request->data()['fields'])->firstWhere('name', 'url')['value']
                === 'https://app.local/api/webhooks/sonarr/1?token=t';
    });
});

test('configureWebhook ignores existing notification of a different implementation with the same name', function (): void {
    Http::fake([
        'sonarr.local/api/v3/notification' => Http::sequence()
            ->push([
                ['id' => 5, 'name' => 'MediaManager', 'implementation' => 'Discord'],
            ], 200)
            ->push(['id' => 100, 'name' => 'MediaManager', 'implementation' => 'Webhook'], 201),
    ]);

    $connection = ServiceConnection::factory()->sonarr()->make([
        'url' => 'http://sonarr.local',
        'api_key' => 'k',
    ]);

    $result = new SonarrClient($connection)->configureWebhook(
        callbackUrl: 'https://app.local/api/webhooks/sonarr/1?token=t',
        notificationName: 'MediaManager',
    );

    expect($result['id'])->toBe(100);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v3/notification'));

    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});
