<?php

declare(strict_types=1);

use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;

test('--list shows available fixtures', function (): void {
    $this->artisan('webhook:simulate', ['--list' => true])
        ->expectsOutputToContain('emby')
        ->expectsOutputToContain('playback.start')
        ->expectsOutputToContain('sonarr')
        ->expectsOutputToContain('download')
        ->assertSuccessful();
});

test('errors helpfully when service argument is missing', function (): void {
    $this->artisan('webhook:simulate')
        ->expectsOutputToContain('"service" and "event" arguments are required')
        ->assertFailed();
});

test('errors when service is unknown', function (): void {
    $this->artisan('webhook:simulate', ['service' => 'bogus', 'event' => 'whatever'])
        ->expectsOutputToContain('Unknown service "bogus"')
        ->assertFailed();
});

test('errors when event fixture file does not exist', function (): void {
    ServiceConnection::factory()->emby()->create();

    $this->artisan('webhook:simulate', ['service' => 'emby', 'event' => 'nonexistent.event'])
        ->expectsOutputToContain('No fixture found')
        ->assertFailed();
});

test('errors when no active connection exists for the service type', function (): void {
    ServiceConnection::factory()->emby()->inactive()->create();

    $this->artisan('webhook:simulate', ['service' => 'emby', 'event' => 'playback.start'])
        ->expectsOutputToContain('No active Emby connection found')
        ->assertFailed();
});

test('dry-run prints the payload without firing a request', function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->emby()->create(['webhook_token' => 'tok-abc']);

    $this->artisan('webhook:simulate', [
        'service' => 'emby',
        'event' => 'playback.start',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('/api/webhooks/emby/')
        ->expectsOutputToContain('tok-abc')
        ->expectsOutputToContain('playback.start')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('applies --set overlays before firing', function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->emby()->create();

    $this->artisan('webhook:simulate', [
        'service' => 'emby',
        'event' => 'playback.start',
        '--set' => ['User.Id=custom-user-xyz', 'Item.Name=OverriddenTitle'],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('custom-user-xyz')
        ->expectsOutputToContain('OverriddenTitle')
        ->assertSuccessful();
});

test('fires the webhook and shows the resulting WebhookEvent', function (): void {
    Http::preventStrayRequests();

    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $connection = ServiceConnection::factory()->sonarr()->create([
        'webhook_token' => 'sonarr-token-xyz',
    ]);

    // Fake the outbound POST but have it forward directly to our route
    // so the real controller/middleware run.
    Http::fake(fn($request) => Http::response(
        $this->postJson(
            '/api/webhooks/sonarr/'.$connection->id,
            $request->data(),
            ['X-Webhook-Token' => $request->header('X-Webhook-Token')[0] ?? ''],
        )->json(),
        200,
    ));

    $this->artisan('webhook:simulate', [
        'service' => 'sonarr',
        'event' => 'download',
        '--connection' => (string) $connection->id,
    ])
        ->expectsOutputToContain('Webhook delivered')
        ->expectsOutputToContain('WebhookEvent')
        ->expectsOutputToContain('Download')
        ->expectsOutputToContain('ActionRequests')
        ->expectsOutputToContain('emby_library_scan')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Webhook-Token', 'sonarr-token-xyz')
        && str_contains((string) $request->url(), '/api/webhooks/sonarr/'.$connection->id)
        && ($request->data()['eventType'] ?? null) === 'Download');
});
